<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Events\ReservationCancelled;
use App\Exceptions\HoldExpired;
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
    public function __construct(
        private readonly HoldExpiryService $expiry,
        private readonly ReservationWindowValidator $windows,
    ) {}

    /**
     * Crea un hold. La garantía de exclusión la da PostgreSQL: si dos
     * transacciones compiten por el mismo slot, una inserta y la otra recibe
     * SQLSTATE 23P01, que acá se traduce a un 409 de dominio.
     *
     * `$ttlMinutes` permite un TTL corto para holds promovidos desde la waitlist.
     */
    public function hold(
        Resource $resource,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?User $user = null,
        ?int $ttlMinutes = null,
    ): Reservation {
        $startUtc = $start->utc();
        $endUtc = $end->utc();

        $this->windows->validate($resource, $startUtc, $endUtc);

        return DB::transaction(function () use ($resource, $startUtc, $endUtc, $user, $ttlMinutes) {
            // Serializa los intentos que podrían solaparse. El lock es por
            // recurso + día (en la zona del recurso), no por recurso global:
            // dos reservas en días distintos no se bloquean entre sí.
            // La garantía sigue siendo el EXCLUDE (ver C8).
            $this->lockResourceDays($resource, $startUtc, $endUtc);

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
                        $ttlMinutes ?? (int) config('slotlock.hold_ttl_minutes')
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

        $reservation = $reservation->refresh();

        // La promoción de la waitlist corre en un listener síncrono; si falla,
        // se loguea pero la cancelación ya está confirmada.
        ReservationCancelled::dispatch($reservation);

        return $reservation;
    }

    /**
     * Advisory locks por recurso + día local. Si la reserva cruza medianoche,
     * se toman los dos días en orden lexicográfico para evitar deadlocks.
     * PostgreSQL no tiene advisory locks por UUID: hashtextextended convierte
     * la clave (uuid:día) en un bigint estable.
     */
    private function lockResourceDays(
        Resource $resource,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): void {
        $localStart = $start->setTimezone($resource->timezone);
        $localEnd = $end->setTimezone($resource->timezone);

        $days = [$localStart->toDateString()];

        if ($localEnd->toDateString() !== $localStart->toDateString()) {
            $days[] = $localEnd->toDateString();
        }

        sort($days);

        foreach ($days as $day) {
            DB::select(
                'select pg_advisory_xact_lock(hashtextextended(?::text, 0))',
                [$resource->getKey().':'.$day],
            );
        }
    }

    private function sqlState(QueryException $e): ?string
    {
        $state = $e->errorInfo[0] ?? null;

        return is_string($state) ? $state : null;
    }
}
