# Casos de concurrencia — SlotLock

## Principio general

> La invariante "no hay dos reservas solapadas para el mismo recurso" se garantiza en la base de datos mediante `EXCLUDE USING gist`. La aplicación traduce el error en una respuesta HTTP determinista. **Nunca se confía en un `SELECT` previo para decidir si se puede insertar.**

## Casos cubiertos

### C1 — Doble reserva simultánea del mismo slot

**Escenario:** dos usuarios (o dos tabs) envían `POST /reservations` para el mismo recurso y rango al mismo tiempo.

**Flujo:**
1. Ambas transacciones hacen `INSERT ... VALUES (...)`.
2. PostgreSQL serializa los inserts por la restricción `EXCLUDE`. La primera adquiere el lock de índice; la segunda espera.
3. Al liberarse, la segunda intenta insertar y viola `EXCLUDE` → error `23P01`.
4. La app captura `QueryException` con SQLSTATE `23P01` y responde `409 Conflict` con `{ error: "slot_already_taken", conflicting_reservation_id }`.

**Resultado esperado:** exactamente 1 éxito (201), N-1 rechazos (409). Verificable con k6.

**Anti-patrón evitado:** `SELECT count(*) ... WHERE overlaps` antes del insert (TOCTOU).

### C2 — Hold + confirmación concurrente

**Escenario:** el hold del usuario A expira en el mismo instante en que intenta confirmarlo, mientras B intenta reservar el mismo slot.

**Flujo:**
- Confirmar un hold es `UPDATE reservations SET status='confirmed' WHERE id=? AND status='held' AND expires_at > now()`.
- Si `expires_at <= now()`, el `UPDATE` afecta 0 filas → 410 Gone (hold expirado).
- Si B inserta antes, la restricción `EXCLUDE` (que cuenta `held` activos) bloquea a B hasta que A confirme o expire. Si A confirma, B recibe 409. Si A expira (lazy), B puede insertar.

**Resultado:** sin doble reserva; el slot queda para quien confirmó a tiempo.

### C3 — Cancelación + reserva simultánea

**Escenario:** A cancela su reserva mientras B intenta reservar el mismo slot.

**Flujo:**
- Cancelación: `UPDATE reservations SET status='cancelled' WHERE id=? AND status IN ('held','confirmed')` (con lock de fila).
- Reserva de B: `INSERT ...`. Si A ya canceló, B inserta OK. Si A aún no canceló, B espera el lock de índice y luego inserta (porque `cancelled` no cuenta en el `WHERE` del EXCLUDE).
- Orden determinista por lock de índice; sin deadlock porque ambas operaciones tocan el mismo índice en el mismo orden.

**Resultado:** B obtiene el slot liberado, o recibe 409 si A no canceló a tiempo. Nunca doble.

### C4 — Idempotency key duplicada (retry de red)

**Escenario:** el cliente reintenta `POST /reservations` porque no recibió respuesta (timeout de red), con la misma `Idempotency-Key`.

**Flujo:**
- Transacción: `INSERT INTO idempotency_keys ... ON CONFLICT (key) DO NOTHING` + verificar `request_hash`.
- Si la clave ya existe y el hash coincide → devolver `response_body` cacheado con `response_status`, **sin re-ejecutar** la reserva.
- Si la clave existe y el hash difiere → 409 `idempotency_conflict`.

**Resultado:** un solo hold creado; el retry devuelve el mismo `id` y status.

### C5 — Expiración lazy vs confirmación

**Escenario:** un hold expira (`expires_at <= now()`) y otro usuario intenta reservar el slot.

**Flujo:**
- La expiración es lazy: no hay job que cambie `status` a `expired`. Las consultas filtran `status = 'confirmed' OR (status = 'held' AND expires_at > now())`.
- El `EXCLUDE` cuenta `held` y `confirmed`, **incluyendo holds expirados** (porque el índice no evalúa `now()`).
- ⚠️ **Riesgo:** un hold expirado sigue bloqueando el EXCLUDE hasta que se limpie físicamente o se cancele.

**Mitigación:** job de limpieza periódico (cada 1 min) que hace `UPDATE reservations SET status='expired' WHERE status='held' AND expires_at <= now()`. Como la promoción usa `SKIP LOCKED`, no bloquea confirmaciones. Alternativa: cambiar el `EXCLUDE` a un índice parcial `WHERE status = 'confirmed' OR (status='held' AND expires_at > now())` — pero PG no permite predicados volátiles (`now()`) en índices parciales. Por eso se mantiene el job de limpieza.

**Decisión documentada:** holds expirados se marcan `expired` por job cada 60s; ventana de inconsistencia máxima = 60s, durante la cual el slot puede aparecer ocupado pero se libera al expirar. Aceptable para el dominio.

### C6 — Promoción de waitlist concurrente

**Escenario:** dos cancelaciones simultáneas promueven entradas de la waitlist para el mismo recurso.

**Flujo:**
- Al cancelar, buscar el primer waitlist entry compatible: `SELECT ... FROM waitlist_entries WHERE resource_id=? AND status='waiting' ORDER BY created_at FOR UPDATE SKIP LOCKED LIMIT 1`.
- `SKIP LOCKED` evita que la segunda transacción espere a la primera; toma el siguiente o ninguno.
- La promoción crea un hold con TTL corto para el usuario notificado.

**Resultado:** cada cancelación promueve a un usuario distinto (o a ninguno si no hay waitlist). Sin deadlocks.

### C7 — Simulación: 50 reservas simultáneas

**Escenario:** endpoint `/simulate/reservations` con `{ resource_id, slot, n: 50 }`.

**Flujo:**
- El servidor lanza 50 goroutines/tasks concurrentes (o N procesos) que llaman al mismo código de creación de hold.
- Cada uno intenta `INSERT`. La restricción serializa.
- Se mide: tiempo total, éxitos (esperado: 1), rechazos (49), errores inesperados (esperado: 0).

**Resultado esperado en README:** tabla con N, éxitos, rechazos, p95 de latencia, throughput.

## Estrategia de locking resumida

| Operación | Mecanismo |
|---|---|
| Crear reserva | `EXCLUDE` (índice GiST) serializa por recurso+rango |
| Confirmar hold | `UPDATE ... WHERE status='held' AND expires_at > now()` (row lock implícito) |
| Cancelar | `UPDATE ... WHERE id=?` (row lock) + índice EXCLUDE |
| Promover waitlist | `SELECT ... FOR UPDATE SKIP LOCKED` |
| Idempotencia | `INSERT ... ON CONFLICT (key)` (unique constraint) |

## Niveles de aislamiento

- Default: `READ COMMITTED`.
- No se usa `SERIALIZABLE` (la restricción ya garantiza la invariante; SERIALIZABLE añadiría retries sin beneficio).
- Excepción: operaciones de waitlist usan row locks explícitos (`FOR UPDATE`).

## Índices

```sql
-- Disponibilidad por recurso y fecha
CREATE INDEX idx_reservations_resource_start
  ON reservations (resource_id, start_at);

-- Holds activos (parcial)
CREATE INDEX idx_reservations_held_active
  ON reservations (resource_id, expires_at)
  WHERE status = 'held';

-- Idempotencia (limpieza por TTL)
CREATE INDEX idx_idempotency_expires
  ON idempotency_keys (expires_at);

-- Waitlist FIFO
CREATE INDEX idx_waitlist_resource_created
  ON waitlist_entries (resource_id, created_at)
  WHERE status = 'waiting';
```

## Pruebas de concurrencia (checklist)

- [ ] k6: 100 VUs, mismo slot → exactamente 1 éxito.
- [ ] k6: 100 VUs, slots distintos → 100 éxitos, 0 conflictos.
- [ ] k6: retry con misma idempotency key → mismo id.
- [ ] Test: hold expirado no bloquea nueva reserva (después del job).
- [ ] Test: cancelación libera slot para reserva concurrente.
- [ ] Test: promoción de waitlist sin duplicados.