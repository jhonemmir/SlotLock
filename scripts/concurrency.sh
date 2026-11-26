#!/bin/sh
# Lanza N holds concurrentes al mismo slot y resume los status HTTP.
# Cada request usa una Idempotency-Key distinta (si no, se mediría el replay).
#
# Uso:
#   ./scripts/concurrency.sh            # 20 requests
#   N=100 ./scripts/concurrency.sh
#   START_AT=... END_AT=... N=50 ./scripts/concurrency.sh
set -eu

N="${N:-20}"
HERE="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

seq 1 "$N" | xargs -P "$N" -I{} "$HERE/hold.sh" | sort | uniq -c
