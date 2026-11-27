<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SlotLock — Demo de concurrencia</title>
    <meta name="theme-color" content="#0f172a">
    <meta name="color-scheme" content="dark">
    <link rel="stylesheet" href="/css/tokens.css?v=2">
    <link rel="stylesheet" href="/css/base.css?v=2">
    <link rel="stylesheet" href="/css/demo.css?v=2">
    <script src="/vendor/htmx.min.js" defer></script>
    <script src="/js/demo.js" defer></script>
</head>
<body hx-headers="{{ json_encode(['X-Demo-Key' => config('slotlock.demo_api_key')]) }}">
    <header class="top">
        <a class="brand" href="/">Slot<span>Lock</span></a>
        <nav>
            <a href="/">Inicio</a>
            <a href="/api/resources">API</a>
            <a href="https://github.com/jhonemmir/SlotLock" rel="noreferrer">GitHub</a>
        </nav>
    </header>

    <main>
        <section class="hero">
            <span class="pill">Demo en vivo</span>
            <h1>Reservas concurrentes, <em>sin dobles asignaciones</em>.</h1>
            <p>
                Elegí un horario, reservalo y después lanzá N intentos simultáneos sobre el
                mismo slot. Solo uno gana; el resto recibe el <code>409</code> de PostgreSQL.
            </p>
        </section>

        @if (! $resource)
            <div class="card warning">
                No hay recursos cargados. Corré <code>make fresh</code> para sembrar la demo.
            </div>
        @else
            <section class="card">
                <div class="card-head">
                    <h2>Disponibilidad</h2>
                    <div class="controls">
                        <label>Recurso
                            <select
                                id="resource-select"
                                name="resource"
                                hx-get="/demo/availability"
                                hx-target="#availability"
                                hx-swap="outerHTML"
                                hx-include="#date-input"
                                hx-trigger="change"
                            >
                                @foreach ($resources as $option)
                                    <option value="{{ $option->id }}" @selected($option->id === $resource->id)>
                                        {{ $option->name }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label>Fecha
                            <input
                                id="date-input"
                                type="date"
                                name="date"
                                value="{{ $date }}"
                                hx-get="/demo/availability"
                                hx-target="#availability"
                                hx-swap="outerHTML"
                                hx-include="#resource-select"
                                hx-trigger="change"
                            >
                        </label>
                    </div>
                </div>

                @include('demo._availability')

                <div class="legend">
                    <span><i class="dot free" aria-hidden="true"></i> libre</span>
                    <span><i class="dot held" aria-hidden="true"></i> hold (10 min)</span>
                    <span><i class="dot confirmed" aria-hidden="true"></i> confirmada</span>
                    <span class="legend-note">Clic en cualquier slot para seleccionarlo.</span>
                </div>
            </section>

            <div class="columns">
                <section class="card" id="booking-card">
                    <h2>Reservar</h2>
                    <p class="muted">
                        Elegí un slot de la grilla. Podés elegir uno ocupado para ver el conflicto.
                    </p>

                    <form
                        hx-post="/demo/reservations"
                        hx-target="#booking-result"
                        hx-swap="innerHTML"
                        hx-disabled-elt="#book-button"
                        hx-indicator="#book-spinner"
                    >
                        @csrf
                        <input type="hidden" name="resource_id" id="resource_id" value="{{ $resource->id }}">
                        <input type="hidden" name="start_at" id="start_at">
                        <input type="hidden" name="end_at" id="end_at">

                        <p class="selection" id="selected-slot" aria-live="polite">Elegí un horario en la grilla.</p>

                        <div class="actions">
                            <button type="submit" id="book-button" disabled>Crear hold</button>
                            <span id="book-spinner" class="spinner htmx-indicator" aria-hidden="true"></span>
                        </div>
                    </form>

                    <div id="booking-result" aria-live="polite"></div>
                </section>

                <section class="card" id="simulation-card">
                    <h2>Simulación de concurrencia</h2>
                    <p class="muted">
                        Lanza N requests concurrentes al slot seleccionado, cada uno con su propia
                        <code>Idempotency-Key</code>. Lo esperado: 1 creada y N-1 rechazadas.
                    </p>

                    <form
                        hx-post="/demo/simulate"
                        hx-target="#simulation-result"
                        hx-swap="innerHTML"
                        hx-disabled-elt="#sim-button"
                        hx-indicator="#sim-spinner"
                    >
                        @csrf
                        <input type="hidden" name="resource_id" id="sim_resource_id" value="{{ $resource->id }}">
                        <input type="hidden" name="start_at" id="sim_start_at">
                        <input type="hidden" name="end_at" id="sim_end_at">

                        <div class="actions">
                            <label class="inline">Intentos
                                <input type="number" name="n" value="{{ min(50, $maxN) }}" min="1" max="{{ $maxN }}">
                            </label>
                            <button type="submit" id="sim-button" disabled>Simular</button>
                            <span id="sim-spinner" class="spinner htmx-indicator" aria-hidden="true"></span>
                        </div>
                    </form>

                    <div id="simulation-result" aria-live="polite"></div>
                </section>
            </div>
        @endif
    </main>

    <footer>
        <span>SlotLock · demo de la restricción <code>EXCLUDE USING gist</code></span>
        <span>Escrituras: <code>X-Demo-Key: {{ config('slotlock.demo_api_key') }}</code></span>
        <span>PHP 8.4 · Laravel 13 · PostgreSQL 16</span>
    </footer>
</body>
</html>
