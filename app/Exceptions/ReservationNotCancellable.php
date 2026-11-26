<?php

namespace App\Exceptions;

final class ReservationNotCancellable extends DomainException
{
    public function __construct()
    {
        parent::__construct('Only held or confirmed reservations can be cancelled.');
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'reservation_not_cancellable';
    }
}
