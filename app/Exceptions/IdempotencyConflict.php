<?php

namespace App\Exceptions;

final class IdempotencyConflict extends DomainException
{
    public function __construct(string $key)
    {
        parent::__construct("The Idempotency-Key [{$key}] was already used with a different request.");
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'idempotency_conflict';
    }
}
