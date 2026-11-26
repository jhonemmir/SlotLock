<?php

namespace App\Services;

use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lanza N intentos concurrentes contra la API real (no simula la DB).
 *
 * Cada intento usa una Idempotency-Key distinta: si compartieran clave, el
 * servidor devolvería replays y el test mediría idempotencia, no exclusión.
 * El pool de Guzzle/curl-multi dispara las requests en paralelo.
 */
final class SimulationService
{
    /**
     * @return array{n: int, succeeded: int, rejected: int, errors: list<array<string, mixed>>, duration_ms: int}
     */
    public function run(Resource $resource, CarbonImmutable $start, CarbonImmutable $end, int $n): array
    {
        $base = rtrim((string) config('slotlock.simulate_base_url'), '/');
        $timeout = max(1, (int) config('slotlock.simulate_timeout_seconds'));

        $payload = [
            'resource_id' => $resource->getKey(),
            'start_at' => $start->utc()->toIso8601String(),
            'end_at' => $end->utc()->toIso8601String(),
        ];

        $began = hrtime(true);

        /** @var array<int, Response|Throwable> $responses */
        $responses = Http::pool(function (Pool $pool) use ($base, $payload, $n, $timeout) {
            for ($i = 0; $i < $n; $i++) {
                $pool->as((string) $i)
                    ->timeout($timeout)
                    ->asJson()
                    ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
                    ->post("{$base}/api/reservations", $payload);
            }
        });

        $duration = (int) round((hrtime(true) - $began) / 1_000_000);

        $succeeded = 0;
        $rejected = 0;
        $errors = [];

        foreach ($responses as $key => $response) {
            if ($response instanceof Throwable) {
                $errors[] = [
                    'request' => $key,
                    'error' => 'connection_error',
                    'message' => $response->getMessage(),
                ];

                continue;
            }

            $status = $response->status();

            if ($status === 201) {
                $succeeded++;
            } elseif ($status === 409) {
                $rejected++;
            } else {
                $errors[] = [
                    'request' => $key,
                    'status' => $status,
                    'body' => $response->json(),
                ];
            }
        }

        return [
            'n' => $n,
            'succeeded' => $succeeded,
            'rejected' => $rejected,
            'errors' => $errors,
            'duration_ms' => $duration,
        ];
    }
}
