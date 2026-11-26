<?php

namespace App\Services;

final readonly class IdempotencyResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
        public bool $replayed,
    ) {}
}
