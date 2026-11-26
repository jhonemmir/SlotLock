<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Resource;
use Carbon\CarbonImmutable;

final class AvailabilityService
{
    /**
     * Grilla de slots del día (en la zona del recurso) con estado
     * free/held/confirmed. Los holds vencidos no se muestran ocupados.
     *
     * @return array{date: string, timezone: string, slots: list<array{start: string, end: string, status: string}>}
     */
    public function forDate(Resource $resource, CarbonImmutable $date): array
    {
        $localStart = $date->setTimezone($resource->timezone)->startOfDay();
        $localEnd = $localStart->addDay();
        $step = max(1, (int) config('slotlock.slot_minutes'));

        $blocking = Reservation::query()
            ->where('resource_id', $resource->getKey())
            ->whereRaw(
                "time_range && tstzrange(?::timestamptz, ?::timestamptz, '[)')",
                [$localStart->utc()->toIso8601String(), $localEnd->utc()->toIso8601String()],
            )
            ->blocking()
            ->orderBy('start_at')
            ->get();

        $slots = [];

        for ($cursor = $localStart; $cursor->lessThan($localEnd); $cursor = $cursor->addMinutes($step)) {
            $slotEnd = $cursor->addMinutes($step);
            $status = 'free';

            foreach ($blocking as $reservation) {
                if ($reservation->start_at->lessThan($slotEnd->utc())
                    && $cursor->utc()->lessThan($reservation->end_at)) {
                    $status = $reservation->status->value;
                    break;
                }
            }

            $slots[] = [
                'start' => $cursor->toIso8601String(),
                'end' => $slotEnd->toIso8601String(),
                'status' => $status,
            ];
        }

        return [
            'date' => $localStart->toDateString(),
            'timezone' => $resource->timezone,
            'slots' => $slots,
        ];
    }
}
