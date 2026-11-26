<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Granularidad y duración
    |--------------------------------------------------------------------------
    |
    | Las reservas se alinean a múltiplos de `slot_minutes` en la zona horaria
    | del recurso. La duración se valida contra `max_duration_minutes` del
    | recurso (con este valor como fallback).
    |
    */

    'slot_minutes' => (int) env('SLOT_MINUTES', 15),
    'max_duration_minutes' => (int) env('MAX_DURATION_MINUTES', 240),

    /*
    |--------------------------------------------------------------------------
    | Holds
    |--------------------------------------------------------------------------
    |
    | Un hold bloquea el slot mientras `expires_at > now()`. La expiración es
    | oportunista (en la ruta de escritura) + un job de higiene cada minuto.
    | Ver docs/adr/0002-expiracion-de-holds.md.
    |
    */

    'hold_ttl_minutes' => (int) env('HOLD_TTL_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Idempotencia
    |--------------------------------------------------------------------------
    */

    'idempotency_ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Simulación (demo visual)
    |--------------------------------------------------------------------------
    |
    | La app se llama a sí misma por HTTP para lanzar N intentos concurrentes
    | contra la API real. En Docker el base URL es http://nginx; en local,
    | APP_URL alcanza.
    |
    */

    'simulate_base_url' => env('SIMULATE_BASE_URL', env('APP_URL', 'http://localhost:8000')),
    'simulate_max_n' => (int) env('SIMULATE_MAX_N', 50),
    'simulate_timeout_seconds' => (int) env('SIMULATE_TIMEOUT_SECONDS', 15),

];
