<?php

namespace App\Exceptions;

final class WaitlistDuplicate extends DomainException
{
    public function __construct()
    {
        parent::__construct('You are already waiting for this resource and time range.');
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'waitlist_duplicate';
    }
}
