<?php

namespace App\Support;

use App\Models\Resource;
use Carbon\CarbonImmutable;

/**
 * Horarios de operación por día (`opening_hours` JSONB).
 *
 * Soporta ventanas que cruzan medianoche (p.ej. 22:00–02:00): la ventana del
 * día anterior puede cubrir la madrugada del día siguiente. Un recurso sin
 * `opening_hours` (null o vacío) no tiene restricción.
 */
final class OpeningHours
{
    public static function isScheduled(Resource $resource): bool
    {
        return is_array($resource->opening_hours) && $resource->opening_hours !== [];
    }

    public static function covers(Resource $resource, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if (! self::isScheduled($resource)) {
            return true;
        }

        $localStart = $start->setTimezone($resource->timezone);
        $localEnd = $end->setTimezone($resource->timezone);

        $windows = [];

        for (
            $day = $localStart->subDay()->startOfDay();
            $day->lessThanOrEqualTo($localEnd->startOfDay());
            $day = $day->addDay()
        ) {
            $window = self::windowFor($resource, $day);

            if ($window !== null) {
                $windows[] = $window;
            }
        }

        if ($windows === []) {
            return false;
        }

        usort(
            $windows,
            fn (array $a, array $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp(),
        );

        $cursor = $localStart;

        foreach ($windows as [$open, $close]) {
            if ($close->lessThanOrEqualTo($cursor)) {
                continue;
            }

            if ($open->greaterThan($cursor)) {
                return false;
            }

            $cursor = $close;

            if ($cursor->greaterThanOrEqualTo($localEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private static function windowFor(Resource $resource, CarbonImmutable $day): ?array
    {
        $hours = $resource->opening_hours;
        $key = strtolower($day->format('D'));
        $range = $hours[$key] ?? null;

        if (! is_array($range) || count($range) < 2) {
            return null;
        }

        $open = CarbonImmutable::parse(
            $day->toDateString().' '.(string) $range[0],
            $resource->timezone,
        );

        $close = CarbonImmutable::parse(
            $day->toDateString().' '.(string) $range[1],
            $resource->timezone,
        );

        // 22:00–02:00 o 12:00–00:00: la ventana termina al día siguiente.
        if ($close->lessThanOrEqualTo($open)) {
            $close = $close->addDay();
        }

        return [$open, $close];
    }
}
