<?php

namespace App\Exceptions;

final class InvalidReservationWindow extends DomainException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'invalid_reservation_window';
    }
}
