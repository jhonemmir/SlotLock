<?php

namespace App\Services;

use App\Exceptions\InvalidReservationWindow;
use App\Models\Resource;
use Carbon\CarbonImmutable;

/**
 * Reglas de ventana compartidas por reservas y waitlist: futuro, duración
 * máxima y alineación a los slots en la zona horaria del recurso.
 */
final class ReservationWindowValidator
{
    public function validate(Resource $resource, CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidReservationWindow('end_at must be after start_at.');
        }

        if ($start->lessThanOrEqualTo(now()->utc())) {
            throw new InvalidReservationWindow('start_at must be in the future.');
        }

        $slotMinutes = max(1, (int) config('slotlock.slot_minutes'));

        // La granularidad se valida en la zona horaria del recurso, no en UTC.
        $localStart = $start->setTimezone($resource->timezone);
        $localEnd = $end->setTimezone($resource->timezone);

        foreach (['start_at' => $localStart, 'end_at' => $localEnd] as $field => $moment) {
            if ($moment->minute % $slotMinutes !== 0 || $moment->second !== 0 || $moment->micro !== 0) {
                throw new InvalidReservationWindow(
                    "{$field} must be aligned to {$slotMinutes}-minute slots in {$resource->timezone}."
                );
            }
        }

        $duration = (int) round($localStart->diffInMinutes($localEnd));
        $maxDuration = (int) ($resource->max_duration_minutes ?: config('slotlock.max_duration_minutes'));

        if ($duration > $maxDuration) {
            throw new InvalidReservationWindow(
                "Duration exceeds the {$maxDuration} minute limit for this resource."
            );
        }
    }
}
