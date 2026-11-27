#!/bin/sh
set -e

# Render, Koyeb y Cloud Run inyectan PORT; Fly lo fija en fly.toml.
PORT="${PORT:-8080}"
export PORT

# nginx no expande variables de entorno: renderizamos el listen del PaaS.
envsubst '$PORT' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# La demo se llama a sí misma por el puerto interno.
export SIMULATE_BASE_URL="http://127.0.0.1:${PORT}"

# Diagnóstico sin filtrar secretos.
echo "• SlotLock boot: PORT=${PORT} APP_KEY=${APP_KEY:+set} DATABASE_URL=${DATABASE_URL:+set}"

# Render free no tiene release command: migramos en el arranque.
# Con timeout para que una DB mal configurada no bloquee nginx para siempre.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "• Running migrations..."
    if ! timeout 60 php artisan migrate --force; then
        echo "✗ migrate failed or timed out; starting the app anyway"
    fi

    echo "• Seeding demo data..."
    if ! timeout 60 php artisan db:seed --force; then
        echo "✗ seed failed or timed out"
    fi
fi

exec "$@"
