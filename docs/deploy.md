# Deploy — SlotLock demo

Artefactos listos:

- `Dockerfile.prod`: php-fpm + nginx + supervisor en un solo contenedor (puerto 8080).
- `docker/prod/`: configs de nginx, FPM, supervisor y php.ini de producción.
- `fly.toml`: app de Fly con healthcheck, release command y auto-stop.

> Estado: **probado localmente, no desplegado**. El deploy a Fly crea una app y
> una base Postgres con costo; ejecutalo con tu cuenta y tu aprobación.

## Smoke test local del imagen de producción

Usa la Postgres de `docker compose` (la DB ya está migrada). No corre el release
command, así que no migra ni siembra.

```bash
docker build -f Dockerfile.prod -t slotlock/prod:local .

KEY=$(docker compose exec -T app php artisan key:generate --show)

docker run --rm -d --name slotlock-prod --network slotlock_default -p 8081:8080 \
  -e APP_KEY="$KEY" \
  -e APP_URL=http://localhost:8081 \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST=postgres \
  -e DB_DATABASE=slotlock \
  -e DB_USERNAME=slotlock \
  -e DB_PASSWORD=slotlock \
  slotlock/prod:local

curl -s localhost:8081/health
# {"status":"ok","app":"SlotLock","database":"ok",...}

# Demo
open http://localhost:8081/demo

docker rm -f slotlock-prod
```

Para probar la simulación interna del contenedor (se llama a `http://127.0.0.1:8080`,
que es nginx dentro de la misma imagen):

```bash
curl -s -X POST localhost:8081/api/simulate/reservations \
  -H 'Content-Type: application/json' \
  -d '{"resource_id":"00000000-0000-4000-8000-000000000001","start_at":"2028-05-01T10:00:00-03:00","end_at":"2028-05-01T11:00:00-03:00","n":10}' | jq
```

## Fly.io

Requisitos: cuenta de Fly con método de pago y `flyctl auth login`.

```bash
# 1. App y Postgres (cambiá los nombres si están tomados;
#    si cambiás la app, actualizá `app` en fly.toml)
fly apps create slotlock-demo
fly postgres create --name slotlock-db --region iad
fly postgres attach slotlock-db --app slotlock-demo   # setea DATABASE_URL

# 2. Clave y URL pública
KEY=$(docker compose exec -T app php artisan key:generate --show)
fly secrets set --app slotlock-demo \
  APP_KEY="$KEY" \
  APP_URL="https://slotlock-demo.fly.dev"

# 3. Deploy (construye la imagen local; no requiere que el repo sea público)
fly deploy --app slotlock-demo

# 4. Verificación
fly logs --app slotlock-demo
curl -s https://slotlock-demo.fly.dev/health
```

`fly postgres attach` define `DATABASE_URL`; `config/database.php` la usa como
fallback de `DB_URL`. El `release_command` de `fly.toml` corre
`migrate --force` + `db:seed --force`; el seed es idempotente.

### Costos y funcionamiento

- Fly cobra por la máquina y por Postgres. `auto_stop_machines = "stop"` apaga la
  máquina sin tráfico; el primer request puede tardar unos segundos (cold start).
- El scheduler (`schedule:work`) **no** corre en este deploy de un proceso. La
  expiración oportunista de holds mantiene la corrección; el job de higiene se
  puede agregar como process group o cron si se quiere.
- Para borrar todo: `fly apps destroy slotlock-demo` y destruir el cluster de
  Postgres desde el dashboard/CLI.

### Troubleshooting

- `CREATE EXTENSION btree_gist`: el usuario de Fly Postgres es superusuario; si
  no, ejecutala con `fly postgres connect`.
- `APP_KEY` faltante → 500. `fly secrets list --app slotlock-demo`.
- `SIMULATE_BASE_URL` debe apuntar al nginx interno (`http://127.0.0.1:8080`, ya
  seteado en `fly.toml`/imagen). Si la simulación falla, revisar `fly logs`.
- `LOG_CHANNEL=stderr` para ver logs en `fly logs`.
- `MAIL_MAILER=log`: los mails de waitlist van al log; no hay SMTP configurado.

## Otros proveedores

Render, Railway o un VPS pueden usar el mismo `Dockerfile.prod`:

- puerto interno `8080`,
- healthcheck `GET /health`,
- `DATABASE_URL` (o `DB_*`) del Postgres gestionado,
- release command `php artisan migrate --force && php artisan db:seed --force`,
- `APP_KEY` y `APP_URL` como variables/secretos.
