# Plan de implementación — SlotLock

Filosofía: **núcleo sólido primero, capas recortables después.** Cada fase termina con algo verificable (test, endpoint, benchmark) antes de avanzar.

## Fase 0 — Diseño y documentación ✅

- [x] Nombre y ruta del proyecto.
- [x] ADR 0001 (stack, DB, concurrencia).
- [x] Spec de dominio (entidades, estados, reglas).
- [x] Spec de concurrencia (casos C1–C8).
- [x] Revisión del diseño con el autor (feedback incorporado en Fase 1.5).
- **Entregable:** docs listos para implementar.

## Fase 1 — Esqueleto + base de datos ✅

- [x] Repo git + Laravel 13 (PHP 8.4).
- [x] `docker compose` con PostgreSQL 16 + app (php-fpm) + nginx + scheduler + pgAdmin opcional.
- [x] `.env.example`.
- [x] Migración 001: `resources`, `reservations` con `EXCLUDE USING gist`, `waitlist_entries`, `idempotency_keys`.
- [x] Extensiones (`btree_gist`) dentro de la migración, no en el init de Docker.
- [x] CHECKs de dominio (estados, TTL de holds, rango válido, capacity v1, waitlist).
- [x] Índices (ver concurrencia.md).
- [x] Seeders idempotentes: 3 recursos con horarios.
- [x] Healthcheck `/health` con `SELECT 1`.
- **Entregable:** `make setup` + `\d reservations` muestran la restricción. ✅

## Fase 1.5 — Endurecimiento (agregada tras la revisión) ✅

- [x] Estrategia de expiración de holds: limpieza oportunista + job (ADR 0002).
- [x] `slotlock:expire-holds` cada minuto y `slotlock:prune-idempotency` cada hora.
- [x] Tests contra PostgreSQL real (nunca SQLite) y base de datos de tests aislada.
- [x] Test que verifica el SQLSTATE `23P01` sin pasar por el servicio.
- [x] CI: PostgreSQL 16 + migraciones + aserción de la restricción + Pint.
- [x] App dockerizada con PHP-FPM (40 workers) para concurrencia real.
- [x] README, ADR y specs sincronizados con el código.
- **Entregable:** `make test` (41 tests) verde en local y en CI.

## Fase 2 — API de reservas (núcleo) ✅

- [x] `POST /api/reservations` (crea hold) con `Idempotency-Key` obligatoria.
- [x] `POST /api/reservations/{id}/confirm`.
- [x] `DELETE /api/reservations/{id}`.
- [x] `GET /api/resources/{id}/availability?date=`.
- [x] Traducción de errores PG (`23P01` → 409) y errores de dominio (410/422/400).
- [x] Validación: duración, granularidad 15 min en la zona del recurso, futuro, `max_duration_minutes`.
- [x] Idempotencia con hash canónico (endpoint + usuario + payload) y replay cacheado.
- [ ] Autenticación y ownership ("solo el dueño cancela") — v2; hoy es una demo abierta.
- [x] Validación de `opening_hours` (por día, con ventanas nocturnas) en reservas,
  waitlist y disponibilidad.
- [ ] Rate limiting.
- **Entregable:** ciclo completo probado con `.http`/curl y tests. ✅

## Fase 3 — Prueba de carga (evidencia técnica) ✅

- [x] `EXPLAIN (ANALYZE, BUFFERS)` de disponibilidad y barrido → `docs/explain/`.
- [x] Verificación manual: 100 requests concurrentes → 1×201, 99×409, 0×5xx (`scripts/concurrency.sh`).
- [x] Script k6: mismo slot, 100 VUs → 1×201, 99×409 (claves de idempotencia distintas).
- [x] Script k6: slots distintos → 100×201.
- [x] Script k6: retry idempotente concurrente → mismo `id`, 100 replays.
- [x] Documentar resultados en README + `docs/evidence/k6.md` (N, 201, 409, 5xx, p95, p99, RPS, hardware).
- **Entregable:** evidencia reproducible; README con números reales. ✅

## Fase 4 — Holds y expiración ✅

- [x] Limpieza oportunista en la ruta de escritura (ADR 0002).
- [x] Job de higiene de holds expirados (cada 60 s).
- [x] Endpoint de disponibilidad filtrando holds expirados.
- [x] Tests: hold expirado libera el slot; confirmación tardía → 410.
- **Entregable:** reservas temporales funcionales y seguras. ✅

## Fase 5 — Cancelaciones + waitlist ✅

- [x] Cancelación transaccional (libera el slot).
- [x] `POST /api/waitlist` (el esquema ya estaba listo: CHECKs + unicidad + FIFO).
- [x] Promoción con `FOR UPDATE SKIP LOCKED` + chequeo de rango completo libre.
- [x] Hold corto (5 min) con `promoted_reservation_id` en la entrada.
- [x] Notificación (mail fake/log) al promovido, después del commit.
- [x] Tests: FIFO, dos cancelaciones → dos usuarios, no duplicación, mail y TTL.
- **Entregable:** flujo cancelación → promoción verificado. ✅

## Fase 6 — Frontend mínimo (Blade + HTMX) ✅

- [x] Landing estática (sin build) con la invariante, CTA a la demo y ejemplos curl.
- [x] Pantalla 1: cuadrícula de disponibilidad por día (HTMX, resource/date).
- [x] Pantalla 2: formulario de reserva con conflicto visible (409) y refresh OOB de la grilla.
- [x] Pantalla 3: simulación ("intentar N reservas simultáneas" → resultados + barras).
- [x] `POST /api/simulate/reservations` (server-side con `Http::pool`, rate-limited).
- [x] Captura real en `docs/screenshots/demo.png`.
- [x] GIF de la simulación (30 s) en `docs/screenshots/demo.gif`.
- **Entregable:** demo visual en `/demo`. ✅

## Fase 7 — Pulido y documentación final ✅

- [x] README con quickstart, API, decisiones y evidencia parcial.
- [x] README con resultados k6.
- [x] Captura real de la demo en el README.
- [x] GIF/asciinema de la simulación (30 s).
- [x] Demo desplegada en Render + Neon (free).
- [ ] (Opcional) programa en C/Go para latencia p99.
- **Entregable:** proyecto listo para portafolio. ✅

## Criterios de "done" por fase

1. El código compila/corre.
2. Hay al menos un test o verificación manual documentada.
3. No se avanza con deuda técnica sin anotarla en un issue.

## Decisiones de diseño confirmadas

| # | Decisión | Valor elegido |
|---|---|---|
| 1 | Granularidad de slots | 15 min, validada en la zona del recurso |
| 2 | Capacidad de recursos | Solo exclusivos (`CHECK capacity = 1`) en v1 |
| 3 | Hold TTL | 10 min |
| 4 | Estados de reserva | `held`, `confirmed`, `cancelled`, `expired` (`CHECK`) |
| 5 | Idempotency-Key | **Obligatoria** (400 si falta o no es UUID) |
| 6 | Expiración de holds | Oportunista en escritura + job de higiene (ADR 0002) |
| 7 | Promoción de waitlist | Automática con FIFO + `SKIP LOCKED` + rango completo libre + hold de 5 min |
| 8 | Zona horaria | Almacenamiento UTC; sesión de DB fijada en UTC |

| Riesgo | Mitigación |
|---|---|
| Scope creep en waitlist | Se entregó acotada a un solo listener + tests; sin UI extra |
| Job de limpieza compitiendo | La corrección no depende del job (ADR 0002); `ORDER BY id FOR UPDATE` |
| k6 no disponible en CI | Se ejecuta localmente/Docker; CI valida la restricción y los tests |
| Frontend consume tiempo | HTMX mínimo, sin SPA; landing estática ya lista |
| Tests contra SQLite | Prohibido: `phpunit.xml` fuerza PostgreSQL |

## Orden de entrega recomendado para el portafolio

1. **Deploy** (fase 7): lo que falta para publicar.
2. Auth/ownership (v2) y, si se quiere, capacidad compartida.
3. Programa en C/Go (opcional).

**Nunca cortar:** la restricción, los tests contra PostgreSQL y los planes de ejecución. Son el corazón del proyecto.
