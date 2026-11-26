# Especificación de dominio — SlotLock

## Entidades

### Resource (recurso)

Unidad reservable: sala, cancha, mesa, etc.

| Campo | Tipo | Notas |
|---|---|---|
| `id` | uuid PK | |
| `name` | varchar(120) | |
| `type` | varchar(40) | `room`, `court`, `table` |
| `capacity` | int | v1: siempre `1` (`CHECK`); compartido requiere otra estrategia |
| `timezone` | varchar(64) | IANA, p.ej. `America/Argentina/Buenos_Aires` |
| `max_duration_minutes` | int | 15–1440; default 240 |
| `created_at`, `updated_at` | timestamptz(6) | |

**Regla:** un recurso con `capacity = 1` es exclusivo: no dos reservas solapadas. En v1 la base exige `capacity = 1` con un `CHECK`; los recursos compartidos necesitan una estrategia distinta (suma de unidades), no un `EXCLUDE`.

### Reservation (reserva)

| Campo | Tipo | Notas |
|---|---|---|
| `id` | uuid PK | |
| `resource_id` | uuid FK → resources | |
| `user_id` | uuid FK → users (o nullable para anónimo) | |
| `start_at` | timestamptz | inclusivo |
| `end_at` | timestamptz | exclusivo |
| `time_range` | tstzrange | generado: `tstzrange(start_at, end_at, '[)')` |
| `status` | varchar(20) | ver estados |
| `expires_at` | timestamptz(6) | obligatorio si `status = 'held'` (`CHECK`); se conserva como auditoría al confirmar |
| `created_at`, `updated_at` | timestamptz(6) | |

> La idempotencia vive en la tabla `idempotency_keys`, no en una columna
> duplicada acá. Una sola fuente de verdad evita traducir `23505` como si fuera
> un conflicto de slot.

**Restricción de exclusión:**
```sql
ALTER TABLE reservations
  ADD CONSTRAINT reservations_no_overlap
  EXCLUDE USING gist (
    resource_id WITH =,
    time_range WITH &&
  ) WHERE (status IN ('held', 'confirmed'));
```

Esto garantiza que dos reservas `held` o `confirmed` del mismo recurso con rangos solapados no pueden coexistir, **sin importar la concurrencia**.

### WaitlistEntry (lista de espera)

| Campo | Tipo | Notas |
|---|---|---|
| `id` | uuid PK | |
| `resource_id` | uuid FK | |
| `user_id` | uuid FK | |
| `desired_start`, `desired_end` | timestamptz(6) | `CHECK (desired_start < desired_end)` |
| `status` | varchar(20) | `waiting`, `notified`, `expired` (`CHECK`) |
| `created_at` | timestamptz(6) | FIFO por `(created_at, id)` |

Una sola entrada `waiting` por usuario y rango (índice único parcial). La
promoción usa `ORDER BY created_at, id` para que el orden sea determinista ante
empates de timestamp.

### IdempotencyKey

| Campo | Tipo | Notas |
|---|---|---|
| `key` | varchar(64) PK | UUID del cliente |
| `endpoint` | varchar(80) | scope de la clave |
| `request_hash` | char(64) | sha256 de `endpoint + user + payload canónico` |
| `response_status` | smallint nullable | `NULL` = operación en curso |
| `response_body` | jsonb nullable | respuesta cacheada |
| `created_at` | timestamptz(6) | default `now()` |
| `expires_at` | timestamptz(6) | TTL (24h); se poda por comando |

## Estados de una reserva

```
        ┌──────────┐
        │  (nueva) │
        └────┬─────┘
             │ crear hold
             ▼
        ┌──────────┐  confirmar   ┌────────────┐
        │   held   │ ───────────► │ confirmed  │
        └────┬─────┘              └─────┬──────┘
             │ expira                │ cancelar
             ▼                       ▼
        ┌──────────┐              ┌────────────┐
        │ expired  │              │ cancelled  │
        └──────────┘              └────────────┘
```

| Estado | Significado | Cuenta como "ocupado" para disponibilidad |
|---|---|---|
| `held` | Reserva temporal, expira si no se confirma | Sí (mientras `expires_at > now()`) |
| `confirmed` | Reserva definitiva | Sí |
| `cancelled` | Cancelada por el usuario o por no-show | No |
| `expired` | Hold no confirmado a tiempo | No (y se trata como libre) |

**Transiciones válidas:**

| Desde | Hacia | Disparador |
|---|---|---|
| (nueva) | `held` | `POST /reservations` (crea hold) |
| `held` | `confirmed` | `POST /reservations/{id}/confirm` dentro de `expires_at` |
| `held` | `expired` | limpieza oportunista al reservar el mismo slot + job cada minuto |
| `confirmed` | `cancelled` | `DELETE /reservations/{id}` o no-show job |
| `held` | `cancelled` | cancelación explícita antes de confirmar |

**Transiciones inválidas** (rechazadas con 409/422): confirmar un `expired`/`cancelled`, cancelar dos veces, etc.

**Expiración:** un hold vencido sigue en el índice del `EXCLUDE` hasta que se
marca `expired`, porque PostgreSQL no permite `now()` en el predicado de un
índice parcial. La estrategia implementada es limpieza oportunista dentro de la
transacción de la nueva reserva + job de higiene cada minuto. Ver
[ADR 0002](../adr/0002-expiracion-de-holds.md).

## Reglas de solapamiento

1. **Rango half-open `[start, end)`**: dos reservas solapan si `start1 < end2 AND start2 < end1`.
2. **Solo `held` (no expirado) y `confirmed` bloquean.** `cancelled`/`expired` no.
3. **Un hold expirado se considera libre** en disponibilidad (`expires_at > now()`), y además se marca `expired` al intentar reservar ese slot (ADR 0002).
4. **Mismo recurso, mismo rango exacto:** conflicto.
5. **Rangos anidados o parciales:** conflicto (cubierto por `&&` de rangos).
6. **Granularidad mínima:** 15 minutos, validada **en la zona horaria del recurso** (no en UTC). Evita fragmentación.
7. **Duración máxima:** `resources.max_duration_minutes` (15–1440, default 240).
8. **Horario de operación:** `opening_hours` (JSONB) por día. **Pendiente** de validación en app (fase 2); hoy el endpoint no lo aplica.

## Idempotencia

- Endpoint `POST /reservations` **requiere** header `Idempotency-Key: <uuid>`. Requests sin header se rechazan con `400 Bad Request` (`missing_idempotency_key`); un valor que no es UUID se rechaza con `400` (`invalid_idempotency_key`).
- La clave es generada por el cliente (UUID v4). El servidor no genera fallback: la idempotencia solo es garantizable si el cliente controla la clave.
- Si se reintenta con la misma clave y mismo body → misma respuesta (201 con el mismo `id`, header `Idempotency-Replayed: true`).
- Reintentar con la misma clave y body distinto → 409 `idempotency_conflict`.
- El hash es canónico: `sha256(endpoint + user_id + payload ordenado)`; así la misma clave usada en otro endpoint no replaya una respuesta ajena.
- Una clave vencida se reutiliza in-place (se resetean hash y respuesta), sin `DELETE` + `INSERT`; el comando `slotlock:prune-idempotency` la elimina físicamente.
- Solo se cachean respuestas exitosas. Si la operación falla, la transacción se revierte y la clave desaparece: el reintento vuelve a ejecutar la lógica, sin efectos parciales.

## Cancelación

- `DELETE /reservations/{id}` (sin idempotencia en v1).
- **Limitación v1:** no hay autenticación; la regla "solo el dueño o un admin" queda documentada pero no aplicada.
- Cancelar una reserva `confirmed` libera el slot y (opcional, fase posterior) notifica al primero de la waitlist.
- Cancelar un `held` lo marca `cancelled` (no `expired`), para distinguir "usuario canceló" de "se le venció".

## Lista de espera

- `POST /waitlist` registra interés en un slot ocupado.
- Promoción: cuando se cancela una reserva, el primer waitlist entry compatible (mismo recurso, solapamiento de rango) se marca `notified` y se le otorga un hold con TTL corto (p.ej. 5 min).
- Concurrencia: la promoción usa `SELECT ... FOR UPDATE SKIP LOCKED` sobre waitlist entries para no bloquear ni duplicar.

## Disponibilidad

- `GET /api/resources/{id}/availability?date=YYYY-MM-DD`
- Devuelve la grilla del día dividida en slots de 15 min, cada uno con estado `free` / `held` / `confirmed`, en la zona horaria del recurso.
- Implementación: generar slots en app sobre la zona del recurso; una consulta por rango (`time_range &&`) filtrada por `blocking`.
- Índice: el GiST de `reservations_no_overlap` sirve la consulta por rango (medido en [`docs/explain/availability.md`](../explain/availability.md)); `(resource_id, start_at)` cubre el orden y los rangos puntuales.

## Endpoints

| Método | Ruta | Estado |
|---|---|---|
| GET | `/api/resources` | ✅ implementado |
| GET | `/api/resources/{id}/availability?date=` | ✅ implementado |
| POST | `/api/reservations` | ✅ implementado (idempotente) |
| POST | `/api/reservations/{id}/confirm` | ✅ implementado |
| DELETE | `/api/reservations/{id}` | ✅ implementado |
| POST | `/api/waitlist` | ⬜ fase 5 |
| POST | `/api/simulate/reservations` | ✅ implementado (fase 6, máx. 50) |
| GET | `/health` | ✅ implementado (incluye `SELECT 1`) |

El endpoint `/api/simulate/reservations` recibe `{ resource_id, start_at, end_at, n }` y lanza N intentos concurrentes server-side con `Http::pool`, devolviendo `{ n, succeeded, rejected, errors, duration_ms }`. Cada intento usa una `Idempotency-Key` distinta. Es la base de la tercera pantalla de la demo.