# Plan de implementación — SlotLock

Filosofía: **núcleo sólido primero, capas recortables después.** Cada fase termina con algo verificable (test, endpoint, benchmark) antes de avanzar.

## Fase 0 — Diseño y documentación ✅ (actual)

- [x] Nombre y ruta del proyecto.
- [x] ADR 0001 (stack, DB, concurrencia).
- [x] Spec de dominio (entidades, estados, reglas).
- [x] Spec de concurrencia (casos C1–C7).
- [ ] Revisión del diseño con el autor.
- **Entregable:** docs listos para implementar.

## Fase 1 — Esqueleto + base de datos

- [ ] Crear repo git + Laravel (composer create-project).
- [ ] `docker-compose.yml` con PostgreSQL 16 + pgAdmin (opcional).
- [ ] `.env.example`.
- [ ] Migración 001: `resources`, `reservations` con `EXCLUDE USING gist`, `waitlist_entries`, `idempotency_keys`.
- [ ] Índices (ver concurrencia.md).
- [ ] Seeders: 3 recursos (sala, cancha, mesa) con horarios.
- [ ] Healthcheck `/health`.
- **Entregable:** `docker compose up` + `php artisan migrate` funcionan; `\d reservations` muestra la restricción.

## Fase 2 — API de reservas (núcleo)

- [ ] `POST /reservations` (crea hold) con `Idempotency-Key`.
- [ ] `POST /reservations/{id}/confirm`.
- [ ] `DELETE /reservations/{id}`.
- [ ] `GET /resources/{id}/availability?date=`.
- [ ] Traducción de errores PG (`23P01` → 409, `23505` → 409).
- [ ] Form requests + validación (duración, horario de operación, granularidad 15 min).
- **Entregable:** ciclo completo reserva → confirmar → cancelar probado con `.http`/Postman.

## Fase 3 — Prueba de carga (evidencia técnica)

- [ ] Script k6: mismo slot, N VUs → 1 éxito.
- [ ] Script k6: slots distintos → N éxitos.
- [ ] Script k6: retry idempotente.
- [ ] Capturar `EXPLAIN (ANALYZE, BUFFERS)` de las consultas críticas → `docs/explain/`.
- [ ] Documentar resultados en README (tabla N, éxitos, rechazos, p95, throughput).
- **Entregable:** evidencia reproducible; README con números reales.

## Fase 4 — Holds y expiración

- [ ] Job de limpieza de holds expirados (cada 60s).
- [ ] Endpoint de disponibilidad filtrando holds expirados.
- [ ] Test: hold expirado libera slot.
- **Entregable:** reservas temporales funcionales y seguras.

## Fase 5 — Cancelaciones + waitlist

- [ ] Cancelación con idempotencia.
- [ ] `POST /waitlist`.
- [ ] Promoción con `FOR UPDATE SKIP LOCKED`.
- [ ] Notificación (log o email fake) al promovido.
- **Entregable:** flujo cancelación → promoción verificado.

## Fase 6 — Frontend mínimo (Blade + HTMX)

- [ ] Pantalla 1: cuadrícula de disponibilidad por día.
- [ ] Pantalla 2: formulario de reserva con conflicto visible (409).
- [ ] Pantalla 3: simulación ("intentar 50 reservas simultáneas" → resultados).
- [ ] `POST /simulate/reservations` (server-side, concurrente).
- **Entregable:** demo visual en 10 segundos.

## Fase 7 — Pulido y documentación final

- [ ] README final con SQL, planes, resultados k6.
- [ ] `.env.example` completo.
- [ ] Tests (PHPUnit/Pest) de los casos C1–C7.
- [ ] (Opcional) Programa en C/Go para latencia p99.
- **Entregable:** proyecto listo para portafolio.

## Criterios de "done" por fase

Cada fase se considera completa cuando:
1. El código compila/corre.
2. Hay al menos un test o verificación manual documentada.
3. No se avanza a la siguiente fase con deuda técnica sin anotarla en un issue.

## Decisiones de diseño confirmadas (Fase 0)

| # | Decisión | Valor elegido |
|---|---|---|
| 1 | Granularidad de slots | 15 min |
| 2 | Capacidad de recursos | Solo exclusivos (capacity=1) en v1 |
| 3 | Hold TTL | 10 min |
| 4 | Estados de reserva | held, confirmed, cancelled, expired (4) |
| 5 | Idempotency-Key | **Obligatorio** (400 si falta) |
| 6 | Promoción de waitlist | **Automática** en v1 (FOR UPDATE SKIP LOCKED) |

| Riesgo | Mitigación |
|---|---|
| Scope creep en waitlist | Promoción manual primero; automática solo si sobra tiempo |
| Job de limpieza compitiendo | Lazy expiry + job solo para métricas; `SKIP LOCKED` |
| k6 no disponible en CI | Usar imagen Docker de k6 |
| Frontend consume tiempo | HTMX mínimo, sin SPA |

## Orden de entrega recomendado para el portafolio

Si el tiempo se acorta, cortar en este orden (de menos a más valioso):
1. Frontend (fase 6) → se puede mostrar con Swagger/curl.
2. Waitlist (fase 5) → documentar como "diseñado, no implementado".
3. Programa en C (fase 7 opcional).

**Nunca cortar:** fases 1–3 (núcleo + evidencia de concurrencia). Son el corazón del proyecto.