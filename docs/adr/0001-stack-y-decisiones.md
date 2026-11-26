# ADR 0001 — Stack, base de datos y estrategia de concurrencia

- **Estado:** Aceptado
- **Fecha:** 2026-11-26
- **Contexto:** Proyecto de portafolio "SlotLock" (reservas con concurrencia real).

## Contexto

Se necesita un sistema de reservas donde la garantía de "no hay solapamientos para un mismo recurso" sea **verificable y reproducible**, no una promesa de la aplicación. El proyecto debe distinguirse de un CRUD típico mostrando SQL, planes de ejecución y resultados de prueba de carga.

## Decisión 1 — Framework PHP: Laravel

**Elegido:** Laravel 13 sobre PHP 8.4.

**Motivos:**
- Ecosistema maduro (Eloquent, migraciones, queues, cache).
- Migraciones versionadas → reproducibilidad del esquema.
- Blade + HTMX encaja con "frontend mínimo renderizado en servidor".
- Familiaridad del autor → menos riesgo de scope creep.

**Alternativas descartadas:** Symfony (más verboso para este alcance), Slim (demasiado bajo nivel, reinventaríamos ruedas de migraciones/queues).

## Decisión 2 — Base de datos: PostgreSQL 16 en Docker

**Elegido:** PostgreSQL 16 vía Docker Compose.

**Motivos:**
- `EXCLUDE USING gist` con `tstzrange` es la pieza central; MySQL no lo soporta de forma equivalente.
- Reproducibilidad: `docker compose up` da la misma versión y extensiones (`btree_gist`) en cualquier máquina.
- Limpieza trivial para pruebas de carga (`docker compose down -v`).
- Compatible con CI (`services: postgres`).

**Alternativas descartadas:** PostgreSQL local (depende del entorno, "funciona en mi máquina"), MySQL (sin exclusion constraints nativas), SQLite (sin concurrencia real).

## Decisión 3 — La garantía de exclusión vive en la DB

**Elegido:** restricción `EXCLUDE USING gist (resource_id WITH =, time_range WITH &&)` sobre la tabla `reservations`.

**Motivos:**
- Una validación en PHP (`SELECT ... WHERE overlaps`) tiene race condition por construcción: dos transacciones concurrentes leen "libre" y ambas insertan.
- La restricción se evalúa atómicamente por el motor, bajo cualquier nivel de aislamiento, incluyendo concurrencia real.
- Es auditable: aparece en `\d reservations` y en `pg_constraint`.

**Consecuencia:** la aplicación debe traducir el error `23P01` (exclusion_violation) a una respuesta HTTP 409 con mensaje claro.

## Decisión 4 — Holds (reservas temporales): expiración oportunista + job

**Elegido:** un hold es una fila en `reservations` con `status = 'held'` y `expires_at`.
La expiración se resuelve **en la ruta de escritura**: antes del `INSERT`, la misma
transacción marca `expired` los holds vencidos que solapan el slot. Un job cada
minuto hace el barrido global como higiene/métricas.

**Motivos:**
- PostgreSQL no permite `now()` en el predicado de un índice parcial, así que el
  `EXCLUDE` no puede ignorar holds vencidos por sí solo.
- Un job únicamente deja una ventana de hasta 60 s con el slot bloqueado y hace que
  la corrección dependa del scheduler.
- Codificar el vencimiento en el rango (`LEAST(end_at, expires_at)`) fue probado y
  descartado: con un slot futuro y TTL corto el rango queda vacío y el hold no
  bloquea nada.

**Consecuencia:** un `UPDATE` extra acotado por recurso+rango en la ruta de reserva;
el job de limpieza sigue existiendo pero no es crítico para la corrección. Detalle
completo y alternativas en [ADR 0002](0002-expiracion-de-holds.md).

## Decisión 5 — Idempotencia: clave por operación

**Elegido:** tabla `idempotency_keys (key, request_hash, response_status, response_body, created_at, expires_at)` con PK en `key`. El cliente **debe** enviar `Idempotency-Key` (UUID v4); requests sin header se rechazan con `400`. No hay fallback server-side.

**Motivos:**
- Un retry de red no debe duplicar la reserva.
- La clave permite devolver la respuesta original (status + body) sin re-ejecutar lógica.
- La idempotencia solo es garantizable si el cliente controla la clave; un fallback server-side no protege contra retries ciegos.

**Regla:** si la clave existe y el `request_hash` coincide → devolver respuesta cacheada. Si existe y el hash difiere → 409 (conflicto de idempotencia). Si no existe → procesar.

## Decisión 6 — Nivel de aislamiento: READ COMMITTED + restricciones

**Elegido:** `READ COMMITTED` (default de PG) confiando en la restricción `EXCLUDE` y en `UNIQUE` para idempotencia.

**Motivos:**
- `SERIALIZABLE` añade overhead y retries por serialization failure; la restricción ya garantiza la invariante.
- `READ COMMITTED` + restricción única es suficiente y más simple de razonar.

**Excepción:** la promoción de waitlist usa `SELECT ... FOR UPDATE SKIP LOCKED` para evitar bloqueos, y la creación de reservas toma un advisory lock por recurso + día para ordenar inserciones concurrentes (ver C8).

## Decisión 7 — Prueba de carga: k6 como evidencia principal

**Elegido:** k6 con script que lanza N peticiones simultáneas al endpoint de reserva para el mismo slot. Verifica que exactamente 1 tiene éxito (201) y el resto 409.

**Motivos:**
- Reproducible, versionable, ejecutable en CI.
- Métricas estándar (p95, throughput, error rate).

**Complemento:** un pequeño programa en C (o Go) que mide latencia p99 bajo carga, para el "toque de dos lenguajes". Opcional, no bloqueante.

**No:** C# (no es stack del autor; el valor está en el resultado, no en el lenguaje).

## Decisión 8 — Frontend mínimo y tardío

**Elegido:** Blade + HTMX, 3 pantallas, construido al final. Pero los endpoints (`/availability`, `/simulate`) se diseñan desde el backend desde el inicio.

**Motivos:**
- Evita que el frontend dicte el API.
- Las pantallas son ilustración, no evidencia técnica (el navegador limita conexiones por host).

## Consecuencias

- El esquema es el artefacto más importante del proyecto; se versiona en `database/migrations/`.
- Cada consulta crítica documenta su `EXPLAIN (ANALYZE, BUFFERS)` en `docs/explain/`.
- La aplicación maneja errores de restricción (`23P01`, `23505`) de forma explícita.
- Los tests corren contra PostgreSQL real: SQLite no puede validar la invariante.
- El README incluye SQL, planes y resultados de la prueba de carga.

## Alternativas globales consideradas

- **Reservas en Redis (SETNX):** más rápido pero pierde durabilidad y auditoría; la prueba de carga dejaría de ser sobre la DB. Descartado: el punto es demostrar PG.
- **Bloqueo optimista con version:** viable pero más complejo; la restricción es más robusta y más fácil de auditar. Descartado a favor de `EXCLUDE`.