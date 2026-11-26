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
- Si B inserta antes, la restricción `EXCLUDE` (que cuenta `held` activos) bloquea a B hasta que A confirme o expire. Si A confirma, B recibe 409. Si A expira (la nueva reserva lo marca `expired` o corre el job), B puede insertar.
- Carrera confirmación vs limpieza: ambas son `UPDATE` sobre la misma fila; el row lock serializa. Si gana la limpieza, la confirmación afecta 0 filas → 410. Si gana la confirmación, la limpieza no matchea `status='held'`. Resultado determinista en ambos órdenes.

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
- Transacción: `INSERT INTO idempotency_keys ... ON CONFLICT (key) DO NOTHING` + `SELECT ... FOR UPDATE` + verificar `request_hash`.
- Si la clave ya existe y el hash coincide → devolver `response_body` cacheado con `response_status`, **sin re-ejecutar** la reserva.
- Si la clave existe y el hash difiere → 409 `idempotency_conflict`.
- Si dos requests concurrentes usan la misma clave, el `INSERT ... ON CONFLICT DO NOTHING` de la segunda espera a que la primera commitee (o rollbackee) y luego resuelve: replay si commiteó, ejecución propia si rollbackeó.
- El hash incluye endpoint + usuario + payload canónico, así una clave no puede replayar la respuesta de otro endpoint.

**Resultado:** un solo hold creado; el retry devuelve el mismo `id` y status.

### C5 — Expiración de holds vs confirmación

**Escenario:** un hold expira (`expires_at <= now()`) y otro usuario intenta reservar el slot.

**El problema estructural:** el `EXCLUDE` cuenta `held` y `confirmed` **incluyendo holds vencidos**, porque un índice no puede evaluar `now()`. PostgreSQL no acepta predicados volátiles en índices parciales, así que no existe un `WHERE ... OR expires_at > now()`.

**Estrategia implementada (ADR 0002):** limpieza oportunista en la ruta de escritura. Antes del `INSERT`, dentro de la misma transacción:

```sql
UPDATE reservations
   SET status = 'expired', updated_at = now()
 WHERE id IN (
     SELECT id FROM reservations
      WHERE resource_id = :resource
        AND status = 'held'
        AND expires_at <= now()
        AND time_range && tstzrange(:start, :end, '[)')
      ORDER BY id
        FOR UPDATE
 )
```

El `ORDER BY id` fija el orden de locks entre transacciones concurrentes. El `INSERT` siguiente ya no encuentra el hold en el índice. Un job cada minuto (`slotlock:expire-holds`) hace el barrido global como higiene/métricas, pero **la corrección ya no depende de él**.

**Trampa descartada:** intentar `tstzrange(start_at, LEAST(end_at, expires_at))` produce un rango vacío cuando `expires_at < start_at`, que es el caso normal al reservar con anticipación (TTL de 10 min, slot de mañana). Un rango vacío no bloquea nada: dos usuarios podrían tomar el mismo slot futuro. El TTL no pertenece a la ventana del slot.

**Carrera confirmación vs limpieza:** ambas son `UPDATE` sobre la misma fila; el row lock serializa. Si gana la limpieza, la confirmación afecta 0 filas → 410. Si gana la confirmación, la limpieza no matchea `status='held'`. Determinista en ambos órdenes.

**Resultado:** el primer intento de reservar un slot con hold vencido lo toma; la ventana de "slot muerto" en escrituras es cero.

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

**Resultado esperado en README:** tabla con N, éxitos, rechazos, p95 de latencia, throughput. **Pendiente (fase 3).**

Cada VU debe enviar una `Idempotency-Key` **distinta**; si comparten clave, el test mide replays de idempotencia (N × 201) y no la exclusión. El escenario de retry es el único que reusa la clave.

### C8 — Deadlocks y orden de intentos

Con muchas inserciones simultáneas sobre el mismo slot, PostgreSQL puede
detectar ciclos de locks (`40P01`) mientras verifica la restricción `EXCLUDE`:
cada transacción espera por otra que, a su vez, espera por una tercera. El
deadlock detector mata una víctima por segundo, y un reintento inmediato puede
volver a entrar en el ciclo (livelock) bajo contención alta.

Reglas implementadas:

- **Serialización por recurso**: antes de la limpieza y del `INSERT`, la
  transacción toma `pg_advisory_xact_lock(hashtextextended(resource_id, 0))`.
  PostgreSQL no tiene un advisory lock por UUID nativo: `hashtextextended`
  convierte el UUID en un `bigint` estable de 64 bits, que es la clave que
  espera `pg_advisory_xact_lock`. Los intentos del mismo recurso se ordenan; el
  primero inserta y los demás reciben `23P01` → `409`. Recursos distintos siguen
  en paralelo. Se libera al terminar la transacción de nivel superior. Una
  colisión de hash solo haría que dos recursos se serialicen entre sí; no afecta
  la corrección.
- **Reintentos como red de seguridad**: la transacción externa (idempotencia)
  se reintenta hasta 5 veces para cruces menos frecuentes (confirmación vs.
  inserción, por ejemplo). No se reintenta `23P01`: es un resultado de dominio.
- La limpieza de holds ordena por `id` antes de `FOR UPDATE`.
- La promoción de waitlist usará `ORDER BY created_at, id` + `FOR UPDATE SKIP LOCKED` (fase 5).

**La garantía no depende del advisory lock**: es una decisión de throughput
para evitar el livelock; si se quita, el `EXCLUDE` sigue impidiendo el
solapamiento (el test de `23P01` lo verifica).

**Medición:** 100 requests concurrentes al mismo slot → 1×201, 99×409, 0×500 en
~2 s, y una segunda corrida → 100×409 (`scripts/concurrency.sh`).

## Estrategia de locking resumida

| Operación | Mecanismo |
|---|---|
| Crear reserva | advisory lock por recurso + limpieza oportunista de holds vencidos + `EXCLUDE` (GiST) que serializa por recurso+rango |
| Confirmar hold | `UPDATE ... WHERE status='held' AND expires_at > now()` (row lock implícito) |
| Cancelar | `UPDATE ... WHERE id=?` (row lock) + índice EXCLUDE |
| Expirar holds | `UPDATE ... WHERE status='held' AND expires_at<=now()` ordenado por `id` (`FOR UPDATE`) + job cada minuto |
| Promover waitlist | `SELECT ... FOR UPDATE SKIP LOCKED` (fase 5) |
| Idempotencia | `INSERT ... ON CONFLICT (key)` + `SELECT ... FOR UPDATE` |

## Niveles de aislamiento

- Default: `READ COMMITTED`.
- No se usa `SERIALIZABLE` (la restricción ya garantiza la invariante; SERIALIZABLE añadiría retries sin beneficio).
- La conexión fija `SET TIME ZONE 'UTC'` (`DB_TIMEZONE`) para que los `timestampTz` se comparen siempre en la misma zona, independientemente del host.
- Excepción: operaciones de waitlist usan row locks explícitos (`FOR UPDATE`).

## Índices

```sql
-- Disponibilidad por recurso + orden por fecha (btree)
CREATE INDEX reservations_resource_id_start_at_index
  ON reservations (resource_id, start_at);

-- Barrido global de holds vencidos (job de higiene)
CREATE INDEX idx_reservations_held_expiry
  ON reservations (expires_at)
  WHERE status = 'held';

-- El índice GiST de reservations_no_overlap cubre `time_range && ...`
-- (ver docs/explain/availability.md)

-- Idempotencia (limpieza por TTL)
CREATE INDEX idempotency_keys_expires_at_index
  ON idempotency_keys (expires_at);

-- Waitlist FIFO determinista + unicidad de encolado
CREATE INDEX idx_waitlist_fifo
  ON waitlist_entries (resource_id, created_at, id)
  WHERE status = 'waiting';

CREATE UNIQUE INDEX idx_waitlist_unique_waiting
  ON waitlist_entries (resource_id, user_id, desired_start, desired_end)
  WHERE status = 'waiting';
```

## Pruebas de concurrencia (checklist)

- [ ] k6: 100 VUs, mismo slot → exactamente 1 éxito. *(fase 3)*
- [ ] k6: 100 VUs, slots distintos → 100 éxitos, 0 conflictos. *(fase 3)*
- [x] Test: el segundo insert solapado recibe `23P01` (sin pasar por el servicio).
- [x] Test: hold expirado no bloquea la nueva reserva y queda en `expired`.
- [x] Test: confirmar un hold vencido → 410.
- [x] Test: cancelación libera el slot.
- [x] Test: retry con la misma idempotency key → mismo `id`.
- [x] Test: la misma key con payload distinto → 409.
- [x] Test: clave de idempotencia vencida → se reutiliza (no 409).
- [x] Test: cancelar dos veces → 409 y confirmar vencido por HTTP → 410.
- [ ] Test: promoción de waitlist sin duplicados. *(fase 5)*