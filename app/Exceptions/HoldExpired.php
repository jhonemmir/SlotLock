<?php

namespace App\Exceptions;

final class HoldExpired extends DomainException
{
    public function __construct()
    {
        parent::__construct('The hold expired or was already confirmed. Create a new reservation.');
    }

    public function status(): int
    {
        return 410;
    }

    public function errorCode(): string
    {
        return 'hold_expired';
    }
}
