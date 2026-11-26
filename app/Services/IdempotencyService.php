<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflict;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Idempotencia por clave de cliente.
 *
 * Flujo (todo en una transacción):
 *  1. Si la clave existe pero venció, se descarta.
 *  2. INSERT ... ON CONFLICT DO NOTHING; si otra transacción está ejecutando
 *     la misma clave, este INSERT espera y luego no inserta nada.
 *  3. SELECT ... FOR UPDATE y comparación del hash canónico del request.
 *  4. Si ya hay respuesta (response_status != NULL) → replay.
 *     Si no, se ejecuta la operación y se guarda el resultado.
 *
 * Si la operación falla, la transacción se revierte y la clave desaparece:
 * el reintento vuelve a ejecutar la lógica (sin efectos parciales).
 */
final class IdempotencyService
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): array{0: int, 1: array<string, mixed>}  $operation
     */
    public function run(
        string $key,
        string $endpoint,
        array $payload,
        ?string $userId,
        Closure $operation,
    ): IdempotencyResult {
        $hash = $this->hash($endpoint, $userId, $payload);

        return DB::transaction(function () use ($key, $endpoint, $hash, $operation) {
            DB::table('idempotency_keys')
                ->where('key', $key)
                ->whereRaw('expires_at <= now()')
                ->delete();

            DB::table('idempotency_keys')->insertOrIgnore([
                'key' => $key,
                'endpoint' => $endpoint,
                'request_hash' => $hash,
                'response_status' => null,
                'response_body' => null,
                'created_at' => now()->utc(),
                'expires_at' => now()->utc()->addHours((int) config('slotlock.idempotency_ttl_hours')),
            ]);

            $row = DB::table('idempotency_keys')
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException('Could not acquire the idempotency key row.');
            }

            if (! hash_equals($row->request_hash, $hash)) {
                throw new IdempotencyConflict($key);
            }

            if ($row->response_status !== null) {
                return new IdempotencyResult(
                    status: (int) $row->response_status,
                    body: json_decode((string) $row->response_body, true) ?? [],
                    replayed: true,
                );
            }

            [$status, $body] = $operation();

            DB::table('idempotency_keys')
                ->where('key', $key)
                ->update([
                    'response_status' => $status,
                    'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
                ]);

            return new IdempotencyResult($status, $body, replayed: false);
        }, attempts: 5);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hash(string $endpoint, ?string $userId, array $payload): string
    {
        $canonical = json_encode([
            'endpoint' => $endpoint,
            'user' => $userId,
            'payload' => $this->canonicalize($payload),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }
}
