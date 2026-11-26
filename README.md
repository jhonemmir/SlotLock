# SlotLock

> Sistema de reservas con concurrencia real: la exclusión de solapamientos vive en PostgreSQL, no en PHP.

[![CI](https://github.com/jhonemmir/SlotLock/actions/workflows/ci.yml/badge.svg)](https://github.com/jhonemmir/SlotLock/actions/workflows/ci.yml)
![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777bb4)
![Laravel 13](https://img.shields.io/badge/Laravel-13-ff2d20)
![PostgreSQL 16](https://img.shields.io/badge/PostgreSQL-16-336791)
![License MIT](https://img.shields.io/badge/license-MIT-green)

**Estado:** fases 0, 1, 1.5, 2 y 4 implementadas (núcleo + API + holds + tests + CI).
Pendiente: k6 (fase 3) y frontend (fase 6). Ver [`docs/plan.md`](docs/plan.md).

---

## Qué demuestra

Dos usuarios intentan reservar el mismo recurso en el mismo horario, al mismo tiempo.
**Uno gana y el otro recibe un `409` determinista**, sin `SELECT` previos ni locks de
aplicación, porque la garantía es una restricción de PostgreSQL:

```sql
ALTER TABLE reservations
  ADD CONSTRAINT reservations_no_overlap
  EXCLUDE USING gist (
    resource_id WITH =,
    time_range  WITH &&
  ) WHERE (status IN ('held', 'confirmed'));
```

Bajo concurrencia real, PostgreSQL serializa los inserts por el índice GiST: el
primero commitea, el segundo viola la restricción con SQLSTATE `23P01` y la API lo
traduce a `409 slot_already_taken`. Un `SELECT ... WHERE overlaps` antes del insert
tiene TOCTOU por construcción: dos transacciones leen "libre" y ambas insertan.

Además del núcleo, el proyecto implementa:

- **Holds con TTL** (10 min) y expiración que no deja slots muertos ([ADR 0002](docs/adr/0002-expiracion-de-holds.md)).
- **Idempotencia obligatoria** por `Idempotency-Key` (UUID), con replay de la respuesta original.
- **Disponibilidad** por día en la zona horaria del recurso.
- **Tests contra PostgreSQL real** (nunca SQLite) y un test que verifica el SQLSTATE `23P01`.
- **CI** con PostgreSQL 16, migraciones, aserción de la restricción y Pint.
- **Entorno reproducible**: la app entera corre en Docker (PHP-FPM + nginx + scheduler).

## Inicio rápido

Requisito: Docker + Docker Compose.

```bash
make setup
```

Eso levanta PostgreSQL, PHP-FPM, nginx y el scheduler; instala dependencias, genera
la clave, corre las migraciones y siembra 3 recursos.

- App: <http://localhost:8000>
- Salud: <http://localhost:8000/health>
- Recursos: <http://localhost:8000/api/resources>

Otros comandos:

```bash
make test     # 20 tests contra PostgreSQL (crea slotlock_test si falta)
make fresh    # migrate:fresh + seed (borra los datos de demo)
make psql     # sesión psql sobre la DB de desarrollo
make lint     # Pint en modo verificación
make logs
```

> Si ves archivos `root` en `storage/`, corré `make fix-perms`.
> El UID/GID del contenedor se alinea con `HOST_UID`/`HOST_GID` (default 1000).
>
> La migración ejecuta `CREATE EXTENSION IF NOT EXISTS btree_gist`. En Docker el
> usuario es superusuario; en un PostgreSQL gestionado, el rol debe tener permiso
> para crear extensiones (o crearla una vez con un administrador).

## API

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/resources` | Lista de recursos |
| `GET` | `/api/resources/{id}/availability?date=YYYY-MM-DD` | Grilla de 15 min (`free`/`held`/`confirmed`) |
| `POST` | `/api/reservations` | Crea un hold. Requiere `Idempotency-Key` |
| `POST` | `/api/reservations/{id}/confirm` | Confirma un hold dentro del TTL |
| `DELETE` | `/api/reservations/{id}` | Cancela un hold o una reserva confirmada |
| `GET` | `/health` | Estado de la app + `SELECT 1` a la DB |

```bash
# Crear un hold (el recurso "Sala A" viene del seeder).
# Ajustá las fechas a un slot futuro libre.
curl -i -X POST localhost:8000/api/reservations \
  -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(cat /proc/sys/kernel/random/uuid)" \
  -d '{
        "resource_id": "00000000-0000-4000-8000-000000000001",
        "start_at": "2027-03-01T10:00:00-03:00",
        "end_at":   "2027-03-01T11:00:00-03:00"
      }'
```

Errores de dominio:

| HTTP | `error` | Cuándo |
|---|---|---|
| `400` | `missing_idempotency_key` | Falta el header |
| `400` | `invalid_idempotency_key` | El header no es un UUID |
| `409` | `slot_already_taken` | La restricción `EXCLUDE` rechazó el insert |
| `409` | `idempotency_conflict` | Misma clave, distinto payload |
| `409` | `reservation_not_cancellable` | Ya estaba cancelada/expirada |
| `410` | `hold_expired` | Se intentó confirmar un hold vencido |
| `422` | `invalid_reservation_window` | Desalineado a 15 min, en el pasado o excede la duración |

## Cómo funciona

### Ciclo de vida

```
(nueva) ──hold──► held ──confirmar──► confirmed
                    │                     │
                    │ expira              │ cancelar
                    ▼                     ▼
                 expired              cancelled
```

- `held` bloquea el slot mientras `expires_at > now()`.
- Confirmar un `held` vencido devuelve `410` (`UPDATE ... WHERE expires_at > now()` afecta 0 filas).
- Cancelar un `held` lo marca `cancelled` (no `expired`), para distinguir "lo canceló el usuario".

### Expiración de holds (el detalle no obvio)

PostgreSQL no permite `now()` en el predicado de un índice parcial, así que un hold
vencido **sigue bloqueando** el `EXCLUDE` hasta que su fila cambia de estado. La
solución implementada: antes de insertar una reserva, la misma transacción expira
los holds vencidos que solapan ese slot (`UPDATE ... ORDER BY id FOR UPDATE`), y un
job cada minuto hace la higiene global.

La alternativa "elegante" de codificar el vencimiento en el rango
(`tstzrange(start_at, LEAST(end_at, expires_at))`) **fue probada y descartada**: con
un slot futuro y TTL de 10 minutos el rango queda vacío y el hold no bloquea nada.
El razonamiento completo está en [`docs/adr/0002-expiracion-de-holds.md`](docs/adr/0002-expiracion-de-holds.md).

### Idempotencia

- `POST /api/reservations` exige `Idempotency-Key` (UUID). Sin header → `400`.
- La misma clave + el mismo payload devuelve la respuesta cacheada con
  `Idempotency-Replayed: true` (mismo `id`, sin re-ejecutar).
- La misma clave + payload distinto → `409 idempotency_conflict`.
- El hash incluye endpoint + usuario + payload canónico, así una clave no puede
  replayar la respuesta de otro endpoint.
- Una clave vencida se reutiliza in-place (se resetea la fila), sin `DELETE` +
  `INSERT`; el comando de poda la elimina físicamente.
- Si la operación falla, la transacción se revierte y la clave desaparece: el
  reintento vuelve a ejecutar la lógica, sin efectos parciales.

### Deadlocks y orden de intentos

La restricción `EXCLUDE` garantiza la exclusión, pero si N transacciones insertan
el mismo rango a la vez, PostgreSQL puede detectar ciclos de locks (`40P01`)
mientras verifica el índice; el detector mata una víctima por segundo y los
reintentos inmediatos pueden entrar en livelock.

Por eso, antes de tocar `reservations`, cada reserva toma un **advisory lock
transaccional por recurso**:

```sql
SELECT pg_advisory_xact_lock(hashtextextended(:resource_id, 0));
```

PostgreSQL no tiene un advisory lock por UUID nativo: `hashtextextended`
convierte el UUID en un `bigint` estable de 64 bits, que es la clave que espera
`pg_advisory_xact_lock`.

Los intentos del mismo recurso se ordenan: el primero inserta y los demás
reciben `23P01` → `409` sin ciclos. Recursos distintos siguen en paralelo. El
lock se libera al terminar la transacción. Los reintentos (5) quedan como red de
seguridad para otros cruces (por ejemplo, confirmación vs. inserción).

Esto **no reemplaza la garantía**: si se quita el advisory lock, la restricción
sigue impidiendo el solapamiento (el test de `23P01` lo prueba); solo empeora la
latencia bajo contención extrema. Es una decisión de throughput, no de
corrección. Medido con 100 requests concurrentes: 1×201, 99×409, 0×500 en ~2 s.

### Zona horaria y granularidad

- Todo se almacena en UTC; la conexión fija `SET TIME ZONE 'UTC'` (`DB_TIMEZONE`).
- La granularidad de 15 minutos se valida en la zona horaria del recurso.
- Un `start_at` sin offset se interpreta en la zona del recurso; con offset se respeta.
- La disponibilidad se genera en la zona del recurso (soporta días de 23/25 h por DST).

## Evidencia

### 1. Tests

`make test` → **20 tests, 58 assertions**, contra PostgreSQL 16 real:

- El insert solapado recibe `23P01` **sin pasar por el servicio** (la restricción es la fuente de verdad).
- Un hold vencido no bloquea y queda en `expired`.
- Confirmar un hold vencido → `410` (servicio y HTTP).
- Cancelar libera el slot; cancelar dos veces → `409`.
- Retry con la misma clave → mismo `id`; payload distinto → `409`; clave vencida → se reutiliza.
- Disponibilidad marca `confirmed` y trata los holds vencidos como libres.

La suite usa `DatabaseTruncation` en lugar de `RefreshDatabase`: el test que
provoca `23P01` no queda dentro de una transacción abortada, y la conexión se
verifica usable inmediatamente después del error.

### 2. Concurrencia HTTP (verificación manual)

100 requests en paralelo al mismo slot, cada uno con su propia `Idempotency-Key`, a
través de nginx → PHP-FPM (40 workers) → PostgreSQL:

```bash
make fresh                 # deja libre el slot de ejemplo del seeder
N=100 ./scripts/concurrency.sh
```

```text
      1 201
     99 409

$ select status, count(*) from reservations where resource_id = '...' group by 1;
 held | 1
```

Durante la corrida no hay respuestas `500`: la serialización por recurso evita
los ciclos de deadlocks y los reintentos de transacción quedan como red de
seguridad. La prueba de carga formal (k6 con 100 VUs, p95 y throughput) es la
fase 3 pendiente. No se publican números que no estén medidos.

### 3. Planes de ejecución

[`docs/explain/availability.md`](docs/explain/availability.md) — 30.000 reservas, PostgreSQL 16:

| Consulta | Índice | Tiempo | Buffers |
|---|---|---|---|
| Disponibilidad por rango (`time_range &&`) | GiST `reservations_no_overlap` | 0.319 ms | 4 |
| Misma ventana por `start_at` | btree `(resource_id, start_at)` | 0.076 ms | 3 |
| Barrido de holds vencidos | parcial `(expires_at) WHERE held` | 0.300 ms | 1 |

El índice del `EXCLUDE` también sirve la consulta de disponibilidad: no hay un
índice de rangos "extra".

### 4. CI

[`.github/workflows/ci.yml`](.github/workflows/ci.yml): PostgreSQL 16 como service,
`composer install`, migraciones, **aserción de que `reservations_no_overlap` existe**,
`php artisan test` y `pint --test`.

## Modelo de datos

```
resources ──1:N── reservations
    │
    └──1:N── waitlist_entries

idempotency_keys  (PK: key)
```

Detalles y reglas: [`docs/spec/dominio.md`](docs/spec/dominio.md).
Casos de concurrencia C1–C8: [`docs/spec/concurrencia.md`](docs/spec/concurrencia.md).

Decisiones de arquitectura:

- [ADR 0001 — Stack, base de datos y estrategia de concurrencia](docs/adr/0001-stack-y-decisiones.md)
- [ADR 0002 — Expiración de holds](docs/adr/0002-expiracion-de-holds.md)

## Limitaciones conocidas (v1)

- **Sin autenticación**: `user_id` es opcional y no se aplica "solo el dueño cancela". Es una demo abierta.
- **`opening_hours` no se valida** todavía en el endpoint (la columna y el seed existen).
- **`capacity = 1`** por `CHECK`: recursos compartidos requieren otra estrategia.
- **Waitlist**: esquema listo (CHECKs, unicidad, FIFO) sin endpoints.
- **k6 y frontend**: pendientes (fases 3 y 6).

## Stack

- PHP **8.4** + Laravel **13** (el lock exige ≥ 8.4.1 por Symfony 8; el README anterior decía 8.3 y era incorrecto).
- PostgreSQL **16** con `btree_gist`.
- nginx + PHP-FPM (40 workers) en Docker Compose.
- Blade para la landing (sin build), Blade + HTMX planificado para el frontend.
- PHPUnit, Pint, GitHub Actions.

## Estructura

```
app/
├── Console/Commands/      # slotlock:expire-holds, slotlock:prune-idempotency
├── Enums/                 # ReservationStatus
├── Exceptions/            # errores de dominio con traducción HTTP
├── Http/                  # controladores, requests y resources de la API
├── Models/                # Resource, Reservation
└── Services/              # reserva, idempotencia, expiración, disponibilidad
database/migrations/       # esquema + EXCLUDE + CHECKs + índices
docker/                    # php-fpm, nginx, init de Postgres
docs/                      # plan, ADRs, specs y EXPLAIN
scripts/                   # hold.sh y concurrency.sh (verificación manual)
tests/Feature/             # la suite contra PostgreSQL real
```

## Licencia

MIT — ver [`LICENSE`](LICENSE).
