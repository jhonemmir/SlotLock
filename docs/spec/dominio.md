# Especificación de dominio — SlotLock

## Entidades

### Resource (recurso)

Unidad reservable: sala, cancha, mesa, etc.

| Campo | Tipo | Notas |
|---|---|---|
| `id` | uuid PK | |
| `name` | varchar(120) | |
| `type` | varchar(40) | `room`, `court`, `table` |
| `capacity` | int | 1 para exclusivo; >1 para compartido (futuro) |
| `timezone` | varchar(40) | IANA, p.ej. `America/Argentina/Buenos_Aires` |
| `created_at`, `updated_at` | timestamptz | |

**Regla:** un recurso con `capacity = 1` es exclusivo: no dos reservas solapadas.

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
| `expires_at` | timestamptz | solo si `status = 'held'` |
| `idempotency_key` | varchar(64) | nullable, unique |
| `created_at`, `updated_at` | timestamptz | |

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
| `desired_start`, `desired_end` | timestamptz | |
| `status` | varchar(20) | `waiting`, `notified`, `expired` |
| `created_at` | timestamptz | FIFO por `created_at` |

### IdempotencyKey

| Campo | Tipo | Notas |
|---|---|---|
| `key` | varchar(64) PK | UUID del cliente |
| `request_hash` | varchar(64) | sha256 del body normalizado |
| `response_status` | int | |
| `response_body` | jsonb | |
| `created_at` | timestamptz | |
| `expires_at` | timestamptz | TTL (p.ej. 24h) |

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
             │ expira (lazy)            │ cancelar
             ▼                          ▼
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
| `held` | `expired` | lazy, al consultar si `expires_at <= now()` |
| `confirmed` | `cancelled` | `DELETE /reservations/{id}` o no-show job |
| `held` | `cancelled` | cancelación explícita antes de confirmar |

**Transiciones inválidas** (rechazadas con 409/422): confirmar un `expired`/`cancelled`, cancelar dos veces, etc.

## Reglas de solapamiento

1. **Rango half-open `[start, end)`**: dos reservas solapan si `start1 < end2 AND start2 < end1`.
2. **Solo `held` (no expirado) y `confirmed` bloquean.** `cancelled`/`expired` no.
3. **Un hold expirado se considera libre** en consultas de disponibilidad (filtro `expires_at > now()` o `status = 'confirmed'`).
4. **Mismo recurso, mismo rango exacto:** conflicto.
5. **Rangos anidados o parciales:** conflicto (cubierto por `&&` de rangos).
6. **Granularidad mínima:** las reservas se redondean a múltiplos de 15 minutos (validación en app + check constraint opcional). Evita fragmentación.
7. **Duración máxima:** configurable por recurso (default 4h).
8. **Horario de operación:** un recurso tiene `opening_hours` (JSONB) por día; reservas fuera de horario se rechazan (validación en app, no en DB).

## Idempotencia

- Endpoint `POST /reservations` **requiere** header `Idempotency-Key: <uuid>`. Requests sin header se rechazan con `400 Bad Request` (`missing_idempotency_key`).
- La clave es generada por el cliente (UUID v4). El servidor no genera fallback: la idempotencia solo es garantizable si el cliente controla la clave.
- Si se reintentan con la misma clave y mismo body → misma respuesta (201 con el mismo `id`).
- Reintentar con la misma clave y body distinto → 409 `idempotency_conflict`.

## Cancelación

- `DELETE /reservations/{id}` con `Idempotency-Key` opcional.
- Solo el dueño o un admin puede cancelar.
- Cancelar una reserva `confirmed` libera el slot y (opcional, fase posterior) notifica al primero de la waitlist.
- Cancelar un `held` lo marca `cancelled` (no `expired`), para distinguir "usuario canceló" de "se le venció".

## Lista de espera

- `POST /waitlist` registra interés en un slot ocupado.
- Promoción: cuando se cancela una reserva, el primer waitlist entry compatible (mismo recurso, solapamiento de rango) se marca `notified` y se le otorga un hold con TTL corto (p.ej. 5 min).
- Concurrencia: la promoción usa `SELECT ... FOR UPDATE SKIP LOCKED` sobre waitlist entries para no bloquear ni duplicar.

## Disponibilidad

- `GET /resources/{id}/availability?date=YYYY-MM-DD`
- Devuelve la grilla del día dividida en slots de 15 min, cada uno con estado `free` / `held` / `confirmed`.
- Implementación: generar slots en app (o con `generate_series`), left join con reservas del día, filtrar holds expirados.
- Índice: `(resource_id, start_at)` y parcial para holds activos.

## Endpoints pensados para el frontend (desde el inicio)

| Método | Ruta | Uso |
|---|---|---|
| GET | `/resources` | lista de recursos |
| GET | `/resources/{id}/availability?date=` | cuadrícula del día |
| POST | `/reservations` | crear hold (idempotente) |
| POST | `/reservations/{id}/confirm` | confirmar hold |
| DELETE | `/reservations/{id}` | cancelar |
| POST | `/waitlist` | anotarse en lista de espera |
| POST | `/simulate/reservations` | simular N reservas simultáneas (ilustración) |
| GET | `/health` | healthcheck |

El endpoint `/simulate/reservations` recibe `{ resource_id, slot, n }` y lanza N intentos concurrentes server-side, devolviendo `{ succeeded: 1, rejected: n-1, errors: [...] }`. Es la base de la tercera pantalla.