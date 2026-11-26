<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflict;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Idempotencia por clave de cliente.
 *
 * Flujo (todo en una transacción):
 *  1. INSERT ... ON CONFLICT DO NOTHING; si otra transacción está ejecutando
 *     la misma clave, este INSERT espera y luego no inserta nada.
 *  2. SELECT ... FOR UPDATE y comparación del hash canónico del request.
 *  3. Si la fila está vencida, se reutiliza in-place (se resetea) en lugar de
 *     borrarla y reinsertarla: una sola fila bloqueada, sin carrera entre
 *     DELETE e INSERT.
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
            $now = now()->utc();

            DB::table('idempotency_keys')->insertOrIgnore([
                'key' => $key,
                'endpoint' => $endpoint,
                'request_hash' => $hash,
                'response_status' => null,
                'response_body' => null,
                'created_at' => $now,
                'expires_at' => $now->copy()->addHours((int) config('slotlock.idempotency_ttl_hours')),
            ]);

            $row = DB::table('idempotency_keys')
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException('Could not acquire the idempotency key row.');
            }

            if (CarbonImmutable::parse((string) $row->expires_at)->lessThanOrEqualTo($now)) {
                DB::table('idempotency_keys')
                    ->where('key', $key)
                    ->update([
                        'endpoint' => $endpoint,
                        'request_hash' => $hash,
                        'response_status' => null,
                        'response_body' => null,
                        'created_at' => $now,
                        'expires_at' => $now->copy()->addHours((int) config('slotlock.idempotency_ttl_hours')),
                    ]);

                $row->request_hash = $hash;
                $row->response_status = null;
                $row->response_body = null;
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
