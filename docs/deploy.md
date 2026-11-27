# Deploy — SlotLock demo

**Demo en vivo:** <https://slotlock-demo.onrender.com/demo> — Render free + Neon free.

La imagen de producción (`Dockerfile.prod`) es **portable**: escucha en `$PORT`
(Render/Koyeb/Cloud Run lo inyectan; Fly lo fija en `fly.toml`). La demo se llama
a sí misma por `http://127.0.0.1:$PORT`, y `RUN_MIGRATIONS=true` ejecuta
`migrate + seed` al arrancar (el seed es idempotente).

## Opción gratis (recomendada): Render + Neon

- **Render free** corre el contenedor: 512 MB, 0.1 CPU, se apaga a los 15 min sin
  tráfico (cold start ~1 min), 750 horas/mes. No requiere tarjeta para el plan free.
- **Neon free** da PostgreSQL real: 1 GB, 100 CU-hours/mes, scale-to-zero y **sin
  tarjeta**. Soporta `btree_gist` (`CREATE EXTENSION`).
- **No uses el Postgres free de Render**: expira a los 30 días. Neon no expira.

### 1. Neon

1. Crear un proyecto en <https://neon.com>.
2. Copiar el connection string **directo** (sin `-pooler`; el pooler transaccional
   complica prepared statements).
3. Opcional: ejecutar `CREATE EXTENSION IF NOT EXISTS btree_gist;` en el SQL
   Editor. La migración también lo intenta; Neon permite crearla.

#### Si ya tenés un proyecto en Neon

Se puede reutilizar, pero **no la misma base de datos**: Laravel crea tablas con
nombres fijos (`users`, `sessions`, `cache`, `jobs`, `migrations`) y dos apps en
la misma DB chocan. Opciones:

- **Recomendado:** crear un proyecto nuevo para SlotLock (Neon free permite 100
  proyectos; cuotas separadas de storage y CU-hours).
- **Reutilizar el proyecto:** crear una base nueva y apuntar el `DATABASE_URL` a
  ella:

  ```sql
  -- en el SQL Editor, conectado a la DB por defecto del proyecto
  CREATE DATABASE slotlock;
  ```

  Luego usá el connection string con `/slotlock` al final (mismo host/usuario).
  Las cuotas de storage y compute del proyecto se comparten, pero la demo es
  chica: tablas de unos pocos MB y tráfico esporádico.
- No uses una branch copiada de tu otra app como DB de SlotLock: arrastra sus
  tablas y datos.
- `btree_gist` es por base: la migración la crea en `slotlock`; no afecta a la
  otra app.

En ambos casos, el compute de Neon escala a cero a los 5 minutos; sumado al
cold start de Render, la primera visita puede tardar. Es esperable.

### 2. Render

Con el blueprint [`render.yaml`](../render.yaml):

1. Render → **New → Blueprint** → conectar el repo de GitHub (puede ser privado).
2. Completar los valores `sync: false`:
   - `DATABASE_URL`: el connection string directo de Neon.
   - `APP_KEY`: `base64:...` (ver abajo).
   - `APP_URL`: `https://<servicio>.onrender.com`.
3. Apply / Deploy. Render construye `Dockerfile.prod`, usa el `PORT` que inyecta y
   chequea `GET /health`.

Generar la clave desde el entorno local:

```bash
KEY=$(docker compose exec -T app php artisan key:generate --show)
echo "$KEY"
```

Si preferís configurar el servicio a mano: **New → Web Service → Docker**, plan
Free, Dockerfile `Dockerfile.prod`, health check `/health`, y las variables:

| Variable | Valor |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_NAME` | `SlotLock` |
| `DB_CONNECTION` | `pgsql` |
| `LOG_CHANNEL` | `stderr` |
| `MAIL_MAILER` | `log` |
| `RUN_MIGRATIONS` | `true` |
| `DB_SSLMODE` | `require` |
| `DATABASE_URL` | Neon (directo) |
| `APP_KEY` | `base64:...` |
| `APP_URL` | URL de Render |

El primer arranque corre las migraciones y el seed. La demo queda en
`https://<servicio>.onrender.com/demo`.

**Free tier:** 0.1 CPU y cold start de ~1 min. La simulación de 50 requests puede
tardar varios segundos; si molesta, bajá `SIMULATE_MAX_N` (p. ej. 25).

## Alternativas sin costo

### Koyeb (free)

- Un servicio free de 512 MB / 0.1 CPU, scale-to-zero a la hora; Frankfurt o
  Washington; puede pedir tarjeta para verificación.
- El Postgres free de Koyeb son **5 horas activas/mes**; usá Neon.
- Deploy desde el Dockerfile; el puerto del servicio debe ser el `PORT` que Koyeb
  inyecta (la imagen lo respeta).

### Google Cloud Run (always free)

- 2M requests + 180k vCPU-s + 360k GiB-s por mes; requiere cuenta de facturación
  de GCP (tarjeta), aunque el uso pequeño entra en el free tier.
- Subir la imagen a Artifact Registry y crear el servicio Cloud Run; Cloud Run
  define `PORT=8080` y la imagen lo usa. `RUN_MIGRATIONS=true` en el servicio.
- Usar Neon para la DB. Cloud Run no incluye Postgres gratis.

### Oracle Cloud Always Free

- Una VM ARM always-free (hasta 4 OCPU / 24 GB) donde podés correr el compose y
  Caddy para TLS. Sin costo, pero es una VM: más operación y disponibilidad de
  capacidad variable.

### Fly.io

- Ya no tiene free tier. `fly.toml` y [`Dockerfile.prod`](../Dockerfile.prod)
  siguen listos si querés pagar una máquina pequeña; los pasos están abajo.

## Smoke test local de la imagen

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

open http://localhost:8081/demo
docker rm -f slotlock-prod
```

La imagen también funciona con otro puerto (como Render):

```bash
docker run --rm -d --name slotlock-prod2 --network slotlock_default \
  -p 8082:10000 -e PORT=10000 -e APP_KEY="$KEY" \
  -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=slotlock \
  -e DB_USERNAME=slotlock -e DB_PASSWORD=slotlock \
  slotlock/prod:local
curl -s localhost:8082/health
```

## Fly.io (pago)

```bash
fly apps create slotlock-demo
fly postgres create --name slotlock-db --region iad
fly postgres attach slotlock-db --app slotlock-demo

KEY=$(docker compose exec -T app php artisan key:generate --show)
fly secrets set --app slotlock-demo \
  APP_KEY="$KEY" \
  APP_URL="https://slotlock-demo.fly.dev"

fly deploy --app slotlock-demo
fly logs --app slotlock-demo
```

`fly postgres attach` setea `DATABASE_URL`; `config/database.php` la usa como
fallback de `DB_URL`. El release command de `fly.toml` corre migraciones y seed.
El scheduler no corre en este deploy; la expiración oportunista mantiene la
corrección.

## Verificación post-deploy

1. **Smoke test** desde tu máquina (solo necesita Python 3):

   ```bash
   BASE_URL=https://slotlock-demo.onrender.com python3 scripts/smoke.py
   ```

   Verifica: health, recursos, disponibilidad, alta, replay idempotente,
   conflicto 409, simulación 1/9 y cancelación. Si imprime todo ✓, el deploy
   funciona de punta a punta.

2. **Neon SQL Editor**:

   ```sql
   select extname from pg_extension where extname = 'btree_gist';
   select conname from pg_constraint where conname = 'reservations_no_overlap';

   select status, count(*) from reservations group by 1;
   select count(*) from idempotency_keys;
   ```

   Debe existir `btree_gist`, la restricción, y después de una simulación debe
   haber una sola fila `held` nueva para ese slot.

3. **Render dashboard**: el servicio debe estar **Live**, el health check en
   verde y los logs sin `500`. La primera visita tras el idle tarda por el cold
   start de Render + Neon.

4. **Demo**: `https://<servicio>.onrender.com/demo` → elegir un slot, crear un
   hold, ver el banner verde; tocar un slot ocupado y ver el 409; simular N
   intentos y ver 1 verde + N-1 rojas.

## Troubleshooting

- **Deploy "in progress" sin respuesta**: mirá si está en **Build** o **Runtime**.
  - Build: el plan free tarda varios minutos; esperá y revisá los logs.
  - Runtime: buscá `SQLSTATE`, `could not connect` o `migrate failed`. Casi siempre
    es `DATABASE_URL` (usar la directa de Neon, sin `-pooler`), `DB_SSLMODE` o
    Neon dormido.
  - Probá la DB desde tu máquina:
    ```bash
    docker run --rm postgres:16-alpine \
      psql "postgresql://.../neondb?sslmode=require" -c 'select 1;'
    ```
  - Para destrabar el arranque y ver la app: poné `RUN_MIGRATIONS=false` y
    redeployá. Si arranca, el problema es la DB/migraciones; corregila y volvé a
    `true`.
- **`CREATE EXTENSION btree_gist`**: Neon lo permite; si el rol no puede, correrla
  desde el SQL Editor. Sin la extensión, la restricción `EXCLUDE` no se crea.
- **500 al arrancar**: revisar `APP_KEY`, `DATABASE_URL` y logs (`LOG_CHANNEL=stderr`).
- **La simulación falla**: `SIMULATE_BASE_URL` lo setea el entrypoint con `$PORT`;
  verificar que el proxy del PaaS apunte al mismo puerto.
- **Cold start**: Render free duerme a los 15 min; la primera visita puede tardar.
- **Borrar todo**: eliminar el servicio en Render y el proyecto en Neon; en Fly,
  `fly apps destroy` y destruir el Postgres.
