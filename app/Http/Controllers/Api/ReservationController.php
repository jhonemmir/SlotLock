<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\IdempotencyService;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class ReservationController extends Controller
{
    public function store(
        StoreReservationRequest $request,
        ReservationService $reservations,
        IdempotencyService $idempotency,
    ): JsonResponse {
        $key = (string) $request->header('Idempotency-Key', '');

        $validated = $request->validated();
        $resource = Resource::query()->findOrFail($validated['resource_id']);

        // Sin offset explícito, la hora se interpreta en la zona del recurso.
        $start = CarbonImmutable::parse($validated['start_at'], $resource->timezone);
        $end = CarbonImmutable::parse($validated['end_at'], $resource->timezone);

        // La clave de idempotencia se ata al recurso y a los instantes en UTC,
        // así un reintento con el mismo body produce el mismo hash.
        $payload = [
            'resource_id' => $resource->getKey(),
            'start_at' => $start->utc()->toIso8601String(),
            'end_at' => $end->utc()->toIso8601String(),
        ];

        $result = $idempotency->run(
            key: $key,
            endpoint: 'POST /api/reservations',
            payload: $payload,
            userId: $request->user()?->getKey(),
            operation: function () use ($reservations, $resource, $start, $end, $request) {
                $reservation = $reservations->hold($resource, $start, $end, $request->user());

                return [201, (new ReservationResource($reservation))->resolve()];
            },
        );

        return response()->json(
            $result->body,
            $result->status,
            $result->replayed ? ['Idempotency-Replayed' => 'true'] : [],
        );
    }

    public function confirm(
        Reservation $reservation,
        ReservationService $reservations,
    ): JsonResponse {
        $reservation = $reservations->confirm($reservation);

        return response()->json((new ReservationResource($reservation))->resolve());
    }

    public function destroy(
        Reservation $reservation,
        ReservationService $reservations,
    ): JsonResponse {
        $reservations->cancel($reservation);

        return response()->json(null, 204);
    }
}
