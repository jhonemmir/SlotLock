<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Exceptions\HoldExpired;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationFlowTest extends TestCase
{
    use DatabaseTruncation;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resource = Resource::create([
            'name' => 'Sala A',
            'type' => 'room',
            'capacity' => 1,
            'timezone' => 'America/Argentina/Buenos_Aires',
            'max_duration_minutes' => 240,
            'opening_hours' => null,
        ]);
    }

    public function test_the_landing_page_loads(): void
    {
        $this->get('/')->assertOk()->assertSee('SlotLock');
    }

    public function test_health_checks_the_database(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('database', 'ok');
    }

    public function test_creates_a_hold_through_the_api(): void
    {
        $response = $this->postJson('/api/reservations', $this->payload(), [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'held')
            ->assertJsonPath('resource_id', $this->resource->id);

        $this->assertNotNull($response->json('expires_at'));
        $this->assertDatabaseCount('reservations', 1);
    }

    /**
     * La prueba que importa: la restricción de PostgreSQL rechaza el
     * solapamiento aunque no pase por el servicio de aplicación.
     *
     * La suite usa DatabaseTruncation (no RefreshDatabase): el INSERT falla
     * en autocommit, sin una transacción de test envolvente que quede
     * abortada por el 23P01.
     */
    public function test_postgres_rejects_overlapping_reservations(): void
    {
        [$start, $end] = $this->slot();

        $this->insertReservation($start, $end, ReservationStatus::Held, $start->addMinutes(10));

        try {
            $this->insertReservation($start, $end, ReservationStatus::Confirmed);

            $this->fail('Se esperaba una violación de la restricción de exclusión.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->errorInfo[0] ?? null);
        }

        // La conexión sigue usable después del error.
        $this->assertSame(1, Reservation::count());

        $this->insertReservation($end, $end->addHour(), ReservationStatus::Confirmed);

        $this->assertSame(2, Reservation::count());
    }

    public function test_a_second_hold_for_the_same_slot_returns_409(): void
    {
        [$start, $end] = $this->slot();

        app(ReservationService::class)->hold($this->resource, $start, $end);

        $this->postJson('/api/reservations', $this->payload(), [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('error', 'slot_already_taken');

        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_expired_hold_is_expired_opportunistically(): void
    {
        [$start, $end] = $this->slot();

        $oldId = $this->insertReservation(
            $start,
            $end,
            ReservationStatus::Held,
            CarbonImmutable::now()->subMinute(),
        );

        $reservation = app(ReservationService::class)->hold($this->resource, $start, $end);

        $this->assertSame(ReservationStatus::Held, $reservation->status);
        $this->assertSame('expired', DB::table('reservations')->where('id', $oldId)->value('status'));
        $this->assertSame(2, Reservation::count());
    }

    public function test_confirming_an_expired_hold_returns_410(): void
    {
        [$start, $end] = $this->slot();

        $reservation = Reservation::create([
            'resource_id' => $this->resource->id,
            'start_at' => $start,
            'end_at' => $end,
            'status' => ReservationStatus::Held,
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);

        $this->expectException(HoldExpired::class);

        app(ReservationService::class)->confirm($reservation);
    }

    /**
     * El mapeo HTTP de HoldExpired vive en DomainException::render().
     * Este test lo verifica de punta a punta (y que withExceptions no
     * lo pise).
     */
    public function test_confirming_an_expired_hold_through_the_api_returns_410(): void
    {
        [$start, $end] = $this->slot();

        $reservation = Reservation::create([
            'resource_id' => $this->resource->id,
            'start_at' => $start,
            'end_at' => $end,
            'status' => ReservationStatus::Held,
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);

        $this->postJson("/api/reservations/{$reservation->id}/confirm")
            ->assertStatus(410)
            ->assertJsonPath('error', 'hold_expired');
    }

    public function test_cancelling_frees_the_slot(): void
    {
        [$start, $end] = $this->slot();

        $service = app(ReservationService::class);
        $reservation = $service->hold($this->resource, $start, $end);

        $this->deleteJson("/api/reservations/{$reservation->id}")->assertNoContent();

        $replacement = $service->hold($this->resource, $start, $end);

        $this->assertTrue($replacement->isNot($reservation));
        $this->assertSame(ReservationStatus::Cancelled, $reservation->refresh()->status);
    }

    public function test_cancelling_twice_through_the_api_returns_409(): void
    {
        [$start, $end] = $this->slot();

        $reservation = app(ReservationService::class)->hold($this->resource, $start, $end);

        $this->deleteJson("/api/reservations/{$reservation->id}")->assertNoContent();

        $this->deleteJson("/api/reservations/{$reservation->id}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'reservation_not_cancellable');
    }

    public function test_retry_with_the_same_idempotency_key_returns_the_same_reservation(): void
    {
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/reservations', $this->payload(), ['Idempotency-Key' => $key])
            ->assertCreated();

        $second = $this->postJson('/api/reservations', $this->payload(), ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_reusing_a_key_with_a_different_payload_is_a_conflict(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->payload();

        $this->postJson('/api/reservations', $payload, ['Idempotency-Key' => $key])
            ->assertCreated();

        $payload['end_at'] = CarbonImmutable::parse($payload['end_at'])
            ->addMinutes(30)
            ->toIso8601String();

        $this->postJson('/api/reservations', $payload, ['Idempotency-Key' => $key])
            ->assertStatus(409)
            ->assertJsonPath('error', 'idempotency_conflict');
    }

    public function test_an_expired_idempotency_key_can_be_reused(): void
    {
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/reservations', $this->payload(), [
            'Idempotency-Key' => $key,
        ])->assertCreated();

        DB::table('idempotency_keys')->where('key', $key)->update([
            // El CHECK exige expires_at > created_at: movemos ambos al pasado.
            'created_at' => DB::raw("now() - interval '25 hours'"),
            'expires_at' => DB::raw("now() - interval '1 minute'"),
        ]);

        [$start, $end] = $this->slot();
        [$start, $end] = [$start->addDay(), $end->addDay()];

        $second = $this->postJson('/api/reservations', [
            'resource_id' => $this->resource->id,
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
        ], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertHeaderMissing('Idempotency-Replayed');

        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('reservations', 2);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        $this->postJson('/api/reservations', $this->payload())
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_idempotency_key');
    }

    public function test_missing_idempotency_key_wins_over_payload_validation(): void
    {
        // Sin clave debe ser 400 aunque el body también sea inválido.
        $this->postJson('/api/reservations', [])
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_idempotency_key');
    }

    public function test_start_at_must_be_in_the_future(): void
    {
        $payload = [
            'resource_id' => $this->resource->id,
            'start_at' => '2020-03-01T10:00:00-03:00',
            'end_at' => '2020-03-01T11:00:00-03:00',
        ];

        $this->postJson('/api/reservations', $payload, [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonPath('error', 'invalid_reservation_window');
    }

    public function test_slot_must_be_aligned_to_15_minutes(): void
    {
        $start = CarbonImmutable::now($this->resource->timezone)
            ->addDays(30)
            ->startOfDay()
            ->addHours(10)
            ->addMinutes(7);

        $payload = [
            'resource_id' => $this->resource->id,
            'start_at' => $start->toIso8601String(),
            'end_at' => $start->addHour()->toIso8601String(),
        ];

        $this->postJson('/api/reservations', $payload, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_reservation_window');
    }

    public function test_availability_reports_confirmed_and_ignores_expired_holds(): void
    {
        [$start, $end] = $this->slot();

        $service = app(ReservationService::class);
        $confirmed = $service->hold($this->resource, $start, $end);
        $service->confirm($confirmed);

        $this->insertReservation(
            $end,
            $end->addHour(),
            ReservationStatus::Held,
            CarbonImmutable::now()->subMinute(),
        );

        $response = $this->getJson(
            "/api/resources/{$this->resource->id}/availability?date={$start->toDateString()}"
        )->assertOk();

        $slots = collect($response->json('slots'))->keyBy('start');

        $this->assertSame('confirmed', $slots[$start->toIso8601String()]['status']);
        $this->assertSame('free', $slots[$end->toIso8601String()]['status']);
    }

    /**
     * Slot futuro y alineado a 15 min, relativo a hoy (evita tests
     * que se rompan cuando pase una fecha fija).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function slot(): array
    {
        $start = CarbonImmutable::now($this->resource->timezone)
            ->addDays(30)
            ->startOfDay()
            ->addHours(10);

        return [$start, $start->addHour()];
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        [$start, $end] = $this->slot();

        return [
            'resource_id' => $this->resource->id,
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
        ];
    }

    private function insertReservation(
        CarbonImmutable $start,
        CarbonImmutable $end,
        ReservationStatus $status,
        ?CarbonImmutable $expiresAt = null,
    ): string {
        $id = (string) Str::uuid();

        DB::table('reservations')->insert([
            'id' => $id,
            'resource_id' => $this->resource->id,
            'start_at' => $start->utc()->toIso8601String(),
            'end_at' => $end->utc()->toIso8601String(),
            'status' => $status->value,
            'expires_at' => $expiresAt?->utc()->toIso8601String(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
