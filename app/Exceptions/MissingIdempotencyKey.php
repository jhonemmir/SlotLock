<?php

namespace App\Exceptions;

final class MissingIdempotencyKey extends DomainException
{
    public function __construct()
    {
        parent::__construct('The Idempotency-Key header is required for this endpoint.');
    }

    public function status(): int
    {
        return 400;
    }

    public function errorCode(): string
    {
        return 'missing_idempotency_key';
    }
}
