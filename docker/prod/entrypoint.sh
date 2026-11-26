#!/bin/sh
set -e

# Render, Koyeb y Cloud Run inyectan PORT; Fly lo fija en fly.toml.
PORT="${PORT:-8080}"
export PORT

# nginx no expande variables de entorno: renderizamos el listen del PaaS.
envsubst '$PORT' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# La demo se llama a sí misma por el puerto interno.
export SIMULATE_BASE_URL="http://127.0.0.1:${PORT}"

# Render free no tiene release command: migramos en el arranque.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
    php artisan db:seed --force
fi

exec "$@"
