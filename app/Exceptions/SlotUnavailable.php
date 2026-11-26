<?php

namespace App\Exceptions;

final class SlotUnavailable extends DomainException
{
    public function __construct()
    {
        parent::__construct('The requested slot is already taken by another reservation.');
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'slot_already_taken';
    }
}
