#!/bin/sh
# Alinea www-data con el UID/GID del host para que el bind mount
# (storage/, bootstrap/cache/) sea escribible sin chmod 777.
set -e

HOST_UID="${HOST_UID:-1000}"
HOST_GID="${HOST_GID:-1000}"

if [ "$(id -u www-data)" != "$HOST_UID" ] || [ "$(id -g www-data)" != "$HOST_GID" ]; then
    deluser www-data 2>/dev/null || true
    delgroup www-data 2>/dev/null || true
    addgroup -g "$HOST_GID" www-data
    adduser -D -u "$HOST_UID" -G www-data www-data
fi

# Bind mounts de distintos hosts (Linux/macOS) pueden traer permisos
# inesperados; solo se ajustan los directorios que Laravel escribe.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
