<?php

namespace App\Exceptions;

final class SlotIsFree extends DomainException
{
    public function __construct()
    {
        parent::__construct('The slot is free; create a reservation instead of joining the waitlist.');
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'slot_is_free';
    }
}
