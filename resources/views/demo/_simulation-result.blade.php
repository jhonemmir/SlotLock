@if (! empty($availability))
    @include('demo._availability', ['oob' => true])
@endif

<div class="sim">
    <div class="sim-stats">
        <div><strong>{{ $result['succeeded'] }}</strong><span>creadas</span></div>
        <div><strong>{{ $result['rejected'] }}</strong><span>rechazadas (409)</span></div>
        <div><strong>{{ $result['duration_ms'] }}</strong><span>ms</span></div>
    </div>

    @if ($result['n'] > 0)
        <div class="sim-bars" aria-hidden="true">
            @for ($i = 0; $i < $result['n']; $i++)
                <span class="sim-bar {{ $i < $result['succeeded'] ? 'win' : 'lose' }}"></span>
            @endfor
        </div>
    @endif

    @if (! empty($result['errors']))
        <details class="errors">
            <summary>{{ count($result['errors']) }} error(es) inesperado(s)</summary>
            <pre>{{ json_encode($result['errors'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @endif

    <p class="muted">
        Cada intento usó una <code>Idempotency-Key</code> distinta. Si el slot ya estaba
        ocupado, verás 0 creadas y todos rechazados.
    </p>
</div>
