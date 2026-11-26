@php
    $timezone = $entry->resource->timezone;
    $from = $entry->desired_start->setTimezone($timezone)->format('d/m/Y H:i');
    $to = $entry->desired_end->setTimezone($timezone)->format('H:i');
    $until = $reservation->expires_at?->setTimezone($timezone)->format('H:i');
@endphp

<p>Hola {{ $entry->user->name }},</p>

<p>Se liberó el horario que estabas esperando en <strong>{{ $entry->resource->name }}</strong>:</p>

<p><strong>{{ $from }} – {{ $to }}</strong></p>

<p>
    Te guardamos un hold hasta las <strong>{{ $until }}</strong>.
    Confirmalo antes de que expire para quedarte con el slot.
</p>

<p>ID de reserva: <code>{{ $reservation->id }}</code></p>

<p>— SlotLock</p>
