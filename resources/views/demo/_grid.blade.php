@php
    $slots = $availability['slots'] ?? [];
    $hours = collect($slots)->chunk(4);
@endphp

@if ($slots === [])
    <p class="muted">No hay slots para este día. Probá con otra fecha.</p>
@else
    <div class="slot-grid" role="grid" aria-label="Disponibilidad de {{ $resource->name }} el {{ $availability['date'] }}">
        <div class="slot-head" aria-hidden="true">
            <span></span>
            <span>:00</span>
            <span>:15</span>
            <span>:30</span>
            <span>:45</span>
        </div>

        @foreach ($hours as $hour)
            @php($first = \Carbon\CarbonImmutable::parse($hour->first()['start']))
            <div class="slot-row" role="row">
                <span class="slot-hour">{{ $first->format('H:i') }}</span>

                @foreach ($hour as $slot)
                    @php($start = \Carbon\CarbonImmutable::parse($slot['start']))
                    @php($end = \Carbon\CarbonImmutable::parse($slot['end']))
                    <button
                        type="button"
                        role="gridcell"
                        class="slot {{ $slot['status'] }}"
                        data-slot
                        data-status="{{ $slot['status'] }}"
                        data-start="{{ $slot['start'] }}"
                        data-end="{{ $slot['end'] }}"
                        data-label="{{ $start->format('H:i') }}–{{ $end->format('H:i') }}"
                        aria-label="{{ $start->format('H:i') }} a {{ $end->format('H:i') }}: {{ $slot['status'] }}"
                        title="{{ $start->format('H:i') }} · {{ $slot['status'] }}"
                    ></button>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
