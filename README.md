# SlotLock

> Sistema de reservas con concurrencia real: la exclusión de solapamientos vive en PostgreSQL, no en PHP.

**Estado:** diseño / pre-implementación. Este repositorio documenta primero el plan, el ADR y la especificación; el código sigue.

## ¿Qué es esto?

SlotLock es un proyecto de portafolio que demuestra cómo resolver el problema clásico de las reservas concurrentes:

- Dos usuarios intentan reservar el mismo recurso en el mismo horario → **solo una gana**, y la otra recibe un error claro y determinista.
- La garantía no depende de validaciones en la aplicación (que fallan bajo race conditions), sino de una **restricción de exclusión en PostgreSQL** (`EXCLUDE USING gist`).
- Incluye reservas temporales (holds) con expiración, cancelaciones transaccionales, lista de espera, idempotencia en reintentos, y una prueba de carga reproducible.

## Por qué no es un CRUD más

| CRUD típico de reservas | SlotLock |
|---|---|
| Valida solapamiento en PHP (`SELECT ... WHERE ...`) | Restricción `EXCLUDE` en la DB, atómica |
| "Funciona en mi máquina" | Docker Compose + migraciones versionadas |
| Sin evidencia de concurrencia | k6 con N peticiones simultáneas, resultados en README |
| Sin métricas de rendimiento | `EXPLAIN (ANALYZE, BUFFERS)` documentado por consulta crítica |
| Frontend opcional e improvisado | Frontend mínimo diseñado desde el backend (endpoints pensados para él) |

## Stack

- **Backend:** PHP 8.3 + Laravel
- **Base de datos:** PostgreSQL 16 (Docker) con extensiones `btree_gist`, `uuid-ossp`
- **Frontend:** Blade + HTMX (mínimo, 3 pantallas)
- **Prueba de carga:** k6 (evidencia técnica) + simulador in-browser (ilustración)
- **Infra:** Docker Compose

## Documentación

- [`docs/plan.md`](docs/plan.md) — plan por fases con entregables.
- [`docs/adr/0001-stack-y-decisiones.md`](docs/adr/0001-stack-y-decisiones.md) — decisiones arquitectónicas.
- [`docs/spec/dominio.md`](docs/spec/dominio.md) — entidades, estados, transiciones.
- [`docs/spec/concurrencia.md`](docs/spec/concurrencia.md) — casos de concurrencia y estrategia.

## Estructura (objetivo)

```
SlotLock/
├── app/                 # Laravel
├── database/
│   └── migrations/      # esquema + restricciones + índices
├── docs/
│   ├── adr/
│   ├── spec/
│   └── explain/         # planes EXPLAIN (ANALYZE, BUFFERS)
├── k6/                  # scripts de prueba de carga
├── resources/views/     # Blade (3 pantallas)
├── docker-compose.yml
└── README.md
```

## Inicio rápido (cuando exista código)

```bash
cp .env.example .env
docker compose up -d
# migraciones + seed
# composer install && php artisan migrate --seed
```

## Evidencia técnica (a completar)

- **SQL crítico:** ver `database/migrations/`.
- **Planes de ejecución:** ver `docs/explain/`.
- **Prueba de carga:** ver `k6/` y sección "Resultados" (a añadir).

---

> Proyecto de portafolio. Diseñado para que cada decisión técnica sea visible y reproducible.