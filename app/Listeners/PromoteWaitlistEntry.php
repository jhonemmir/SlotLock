<?php

namespace App\Listeners;

use App\Events\ReservationCancelled;
use App\Services\WaitlistService;
use Throwable;

final class PromoteWaitlistEntry
{
    public function __construct(private readonly WaitlistService $waitlist) {}

    public function handle(ReservationCancelled $event): void
    {
        try {
            $this->waitlist->promote($event->reservation);
        } catch (Throwable $e) {
            // La cancelación ya está confirmada: un fallo de promoción no debe
            // convertir el request en un 500.
            report($e);
        }
    }
}
