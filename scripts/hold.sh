#!/bin/sh
# Crea un hold contra la API y devuelve solo el status HTTP.
#
# Variables (todas opcionales):
#   BASE_URL, RESOURCE_ID, START_AT, END_AT, IDEMPOTENCY_KEY
#
# Uso:
#   ./scripts/hold.sh
#   START_AT=2027-04-01T10:00:00-03:00 END_AT=2027-04-01T11:00:00-03:00 ./scripts/hold.sh
set -eu

BASE_URL="${BASE_URL:-http://localhost:8000}"
RESOURCE_ID="${RESOURCE_ID:-00000000-0000-4000-8000-000000000001}"

# Por defecto, un slot futuro (hoy + 30 días, 10:00 UTC) para que el script
# siga funcionando sin tocar fechas. Si se pasa START_AT, hay que pasar END_AT.
if [ -z "${START_AT:-}" ] || [ -z "${END_AT:-}" ]; then
    if date -u -d '+30 days' +%Y-%m-%d >/dev/null 2>&1; then
        DAY=$(date -u -d '+30 days' +%Y-%m-%d)
    else
        DAY=$(date -u -v+30d +%Y-%m-%d)
    fi
    START_AT="${DAY}T10:00:00+00:00"
    END_AT="${DAY}T11:00:00+00:00"
fi

uuid() {
    if [ -r /proc/sys/kernel/random/uuid ]; then
        cat /proc/sys/kernel/random/uuid
    else
        uuidgen | tr 'A-Z' 'a-z'
    fi
}

KEY="${IDEMPOTENCY_KEY:-$(uuid)}"

curl -s -o /dev/null -w '%{http_code}\n' -X POST "$BASE_URL/api/reservations" \
    -H 'Content-Type: application/json' \
    -H "Idempotency-Key: $KEY" \
    -d "{
        \"resource_id\": \"$RESOURCE_ID\",
        \"start_at\": \"$START_AT\",
        \"end_at\": \"$END_AT\"
    }"
