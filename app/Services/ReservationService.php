<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Exceptions\HoldExpired;
use App\Exceptions\InvalidReservationWindow;
use App\Exceptions\ReservationNotCancellable;
use App\Exceptions\SlotUnavailable;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ReservationService
{
    public function __construct(private readonly HoldExpiryService $expiry) {}

    /**
     * Crea un hold. La garantía de exclusión la da PostgreSQL: si dos
     * transacciones compiten por el mismo slot, una inserta y la otra recibe
     * SQLSTATE 23P01, que acá se traduce a un 409 de dominio.
     */
    public function hold(
        Resource $resource,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?User $user = null,
    ): Reservation {
        $startUtc = $start->utc();
        $endUtc = $end->utc();

        $this->assertValidWindow($resource, $startUtc, $endUtc);

        return DB::transaction(function () use ($resource, $startUtc, $endUtc, $user) {
            // Un hold vencido no libera el índice por sí solo (ADR 0002).
            $this->expiry->forSlot($resource, $startUtc, $endUtc);

            try {
                return Reservation::create([
                    'resource_id' => $resource->getKey(),
                    'user_id' => $user?->getKey(),
                    'start_at' => $startUtc,
                    'end_at' => $endUtc,
                    'status' => ReservationStatus::Held,
                    'expires_at' => now()->utc()->addMinutes(
                        (int) config('slotlock.hold_ttl_minutes')
                    ),
                ]);
            } catch (QueryException $e) {
                if ($this->sqlState($e) === '23P01') {
                    throw new SlotUnavailable;
                }

                throw $e;
            }
        }, attempts: 5);
    }

    /**
     * Confirma un hold dentro de su TTL. Si expiró (o el job lo marcó
     * `expired`), el UPDATE afecta 0 filas y se responde 410.
     */
    public function confirm(Reservation $reservation): Reservation
    {
        $updated = DB::table('reservations')
            ->where('id', $reservation->getKey())
            ->where('status', ReservationStatus::Held->value)
            ->whereRaw('expires_at > now()')
            ->update([
                'status' => ReservationStatus::Confirmed->value,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw new HoldExpired;
        }

        return $reservation->refresh();
    }

    public function cancel(Reservation $reservation): Reservation
    {
        $updated = DB::table('reservations')
            ->where('id', $reservation->getKey())
            ->whereIn('status', [
                ReservationStatus::Held->value,
                ReservationStatus::Confirmed->value,
            ])
            ->update([
                'status' => ReservationStatus::Cancelled->value,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw new ReservationNotCancellable;
        }

        return $reservation->refresh();
    }

    private function assertValidWindow(
        Resource $resource,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): void {
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

    private function sqlState(QueryException $e): ?string
    {
        $state = $e->errorInfo[0] ?? null;

        return is_string($state) ? $state : null;
    }
}
