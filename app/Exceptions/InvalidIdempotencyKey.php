<?php

namespace App\Exceptions;

final class InvalidIdempotencyKey extends DomainException
{
    public function __construct()
    {
        parent::__construct('The Idempotency-Key header must be a UUID (v4 recommended).');
    }

    public function status(): int
    {
        return 400;
    }

    public function errorCode(): string
    {
        return 'invalid_idempotency_key';
    }
}
