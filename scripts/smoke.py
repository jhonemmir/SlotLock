#!/usr/bin/env python3
"""Smoke test de SlotLock contra un entorno local o desplegado.

Uso:
    python3 scripts/smoke.py
    BASE_URL=https://slotlock-demo.onrender.com python3 scripts/smoke.py

Solo usa la stdlib de Python 3.
"""

import json
import os
import sys
import uuid
from datetime import datetime, timedelta, timezone
from typing import Optional
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

BASE_URL = os.environ.get("BASE_URL", "http://localhost:8000").rstrip("/")


def ok(message: str) -> None:
    print(f"✓ {message}")


def fail(message: str) -> None:
    print(f"✗ {message}")
    sys.exit(1)


def call(method: str, path: str, body: Optional[dict] = None, headers: Optional[dict] = None, timeout: int = 60):
    data = json.dumps(body).encode() if body is not None else None
    request_headers = {"Accept": "application/json", **(headers or {})}

    if data is not None:
        request_headers["Content-Type"] = "application/json"

    request = Request(BASE_URL + path, data=data, headers=request_headers, method=method)

    try:
        with urlopen(request, timeout=timeout) as response:
            raw = response.read()
            parsed = json.loads(raw) if raw else None
            return response.status, {k.lower(): v for k, v in response.headers.items()}, parsed
    except HTTPError as error:
        raw = error.read()
        try:
            parsed = json.loads(raw) if raw else None
        except json.JSONDecodeError:
            parsed = raw.decode(errors="replace")
        return error.code, {k.lower(): v for k, v in error.headers.items()}, parsed
    except URLError as error:
        fail(f"no se pudo conectar a {BASE_URL}: {error.reason}")


def hold_payload(slot: dict) -> dict:
    return {
        "resource_id": resource,
        "start_at": slot["start"],
        "end_at": slot["end"],
    }


print(f"→ Smoke test contra {BASE_URL}")

# 1. Health (incluye SELECT 1 a la DB). El timeout cubre el cold start.
status, _, health = call("GET", "/health", timeout=90)
if status != 200 or not health or health.get("status") != "ok" or health.get("database") != "ok":
    fail(f"health devolvió {status}: {health}")
ok("health ok (app + DB)")

# 2. Recursos sembrados.
status, _, resources = call("GET", "/api/resources")
items = (resources or {}).get("data", [])
if not items:
    fail("no hay recursos; ¿corriste el seed?")
resource = items[0]["id"]
ok(f"recursos: {len(items)}")

# 3. Disponibilidad y dos slots libres (uno para el hold, otro para simular).
day = (datetime.now(timezone.utc) + timedelta(days=30)).strftime("%Y-%m-%d")
status, _, availability = call("GET", f"/api/resources/{resource}/availability?date={day}")
free = [slot for slot in (availability or {}).get("slots", []) if slot.get("status") == "free"]
if len(free) < 2:
    fail(f"menos de 2 slots libres el {day}")
first, second = free[0], free[1]
ok(f"disponibilidad: {len(free)} slots libres el {day}")

# 4. Crear un hold.
key = str(uuid.uuid4())
status, _, created = call("POST", "/api/reservations", hold_payload(first), {"Idempotency-Key": key})
if status != 201 or not isinstance(created, dict) or "id" not in created:
    fail(f"crear hold devolvió {status}: {created}")
reservation_id = created["id"]
ok(f"hold creado ({reservation_id})")

# 5. Replay con la misma clave: mismo id + header de replay.
status, headers, replay = call("POST", "/api/reservations", hold_payload(first), {"Idempotency-Key": key})
if status != 201 or (replay or {}).get("id") != reservation_id:
    fail(f"el replay no devolvió el mismo id: {status} {replay}")
if headers.get("idempotency-replayed") != "true":
    fail("el replay no trajo Idempotency-Replayed: true")
ok("idempotencia: mismo id + replay")

# 6. Mismo slot, otra clave: 409 de la restricción.
status, _, conflict = call("POST", "/api/reservations", hold_payload(first), {"Idempotency-Key": str(uuid.uuid4())})
if status != 409 or (conflict or {}).get("error") != "slot_already_taken":
    fail(f"conflicto inesperado: {status} {conflict}")
ok("conflicto: 409 slot_already_taken")

# 7. Simulación concurrente sobre el segundo slot: 1 gana, 9 rechazadas.
status, _, simulation = call(
    "POST",
    "/api/simulate/reservations",
    {**hold_payload(second), "n": 10},
    timeout=180,
)
if status != 200 or simulation.get("succeeded") != 1 or simulation.get("rejected") != 9 or simulation.get("errors"):
    fail(f"simulación inesperada: {status} {simulation}")
ok("simulación: 1 creada / 9 rechazadas")

# 8. Cancelar el hold para dejar el slot libre.
status, _, _ = call("DELETE", f"/api/reservations/{reservation_id}")
if status != 204:
    fail(f"cancelar devolvió {status}")
ok("cancelación: 204")

print("\nTodo OK. App, DB, idempotencia, restricción y simulación funcionan.")
