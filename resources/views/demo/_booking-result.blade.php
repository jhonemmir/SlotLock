@if (! empty($availability))
    @include('demo._availability', ['oob' => true])
@endif

@if ($ok)
    <div class="banner ok">
        <strong>Hold creado.</strong>
        <code>{{ $reservation->id }}</code>
        <span class="muted">
            expira {{ $reservation->expires_at?->setTimezone($resource->timezone)->format('H:i') }}
            · estado <code>{{ $reservation->status->value }}</code>
        </span>
    </div>
@else
    <div class="banner error">
        <strong>{{ $error }}</strong>
        <span>{{ $message }}</span>
    </div>
@endif
