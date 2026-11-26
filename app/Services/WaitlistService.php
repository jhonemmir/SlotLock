<?php

namespace App\Services;

use App\Enums\WaitlistStatus;
use App\Exceptions\SlotIsFree;
use App\Exceptions\SlotUnavailable;
use App\Exceptions\WaitlistDuplicate;
use App\Mail\WaitlistPromoted;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class WaitlistService
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ReservationWindowValidator $windows,
    ) {}

    /**
     * Registra interés en un slot que está ocupado (hold activo o confirmado).
     * El índice único parcial impide dos entradas `waiting` del mismo usuario
     * para el mismo recurso y rango.
     */
    public function register(
        Resource $resource,
        CarbonImmutable $start,
        CarbonImmutable $end,
        User $user,
    ): WaitlistEntry {
        $startUtc = $start->utc();
        $endUtc = $end->utc();

        $this->windows->validate($resource, $startUtc, $endUtc);

        if (! $this->isBusy($resource, $startUtc, $endUtc)) {
            throw new SlotIsFree;
        }

        try {
            return WaitlistEntry::create([
                'resource_id' => $resource->getKey(),
                'user_id' => $user->getKey(),
                'desired_start' => $startUtc,
                'desired_end' => $endUtc,
                'status' => WaitlistStatus::Waiting,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw new WaitlistDuplicate;
            }

            throw $e;
        }
    }

    /**
     * Promueve la primera entrada FIFO compatible con la reserva cancelada.
     *
     * Una entrada es compatible si su rango solapa el slot cancelado y, además,
     * su rango completo quedó libre (puede ser más amplio que el slot cancelado).
     * `SKIP LOCKED` evita que dos cancelaciones simultáneas promuevan la misma
     * entrada: la segunda toma la siguiente o ninguna.
     */
    public function promote(Reservation $cancelled): ?Reservation
    {
        return DB::transaction(function () use ($cancelled) {
            $entry = WaitlistEntry::query()
                ->where('resource_id', $cancelled->resource_id)
                ->where('status', WaitlistStatus::Waiting->value)
                ->whereRaw(
                    "tstzrange(desired_start, desired_end, '[)') && tstzrange(?::timestamptz, ?::timestamptz, '[)')",
                    [
                        $cancelled->start_at->utc()->toIso8601String(),
                        $cancelled->end_at->utc()->toIso8601String(),
                    ],
                )
                ->orderBy('created_at')
                ->orderBy('id')
                // Postgres: bloquea la entrada sin esperar a las que ya están
                // tomadas por otra cancelación. `skipLocked()` no existe en
                // el builder de Laravel 13; se pasa el lock explícito.
                ->lock('for update skip locked')
                ->first();

            if ($entry === null) {
                return null;
            }

            $resource = $entry->resource;

            // El rango deseado puede ser más amplio que el slot que se liberó:
            // solo se promueve si está completamente libre.
            if ($this->isBusy($resource, $entry->desired_start, $entry->desired_end)) {
                return null;
            }

            try {
                $hold = $this->reservations->hold(
                    $resource,
                    $entry->desired_start,
                    $entry->desired_end,
                    $entry->user,
                    (int) config('slotlock.waitlist_hold_ttl_minutes'),
                );
            } catch (SlotUnavailable) {
                // Otra transacción tomó el slot entre el chequeo y el insert.
                return null;
            }

            $entry->forceFill([
                'status' => WaitlistStatus::Notified,
                'promoted_reservation_id' => $hold->getKey(),
            ])->save();

            // La notificación corre después del commit: si falla, el hold y la
            // entrada ya quedaron persistidos.
            DB::afterCommit(fn () => $this->notify($entry, $hold));

            return $hold;
        }, attempts: 5);
    }

    private function isBusy(Resource $resource, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return Reservation::query()
            ->where('resource_id', $resource->getKey())
            ->whereRaw(
                "time_range && tstzrange(?::timestamptz, ?::timestamptz, '[)')",
                [$start->utc()->toIso8601String(), $end->utc()->toIso8601String()],
            )
            ->blocking()
            ->exists();
    }

    private function notify(WaitlistEntry $entry, Reservation $hold): void
    {
        Log::info('Waitlist entry promoted', [
            'waitlist_entry_id' => $entry->getKey(),
            'reservation_id' => $hold->getKey(),
            'user_id' => $entry->user_id,
        ]);

        try {
            Mail::to($entry->user)->send(new WaitlistPromoted($entry, $hold));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
