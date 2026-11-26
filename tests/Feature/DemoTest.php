<?php

namespace Tests\Feature;

use App\Models\Resource;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoTest extends TestCase
{
    use DatabaseTruncation;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        // Los POST del demo son formularios web; en tests no hay token CSRF.
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->resource = Resource::create([
            'name' => 'Sala A',
            'type' => 'room',
            'capacity' => 1,
            'timezone' => 'America/Argentina/Buenos_Aires',
            'max_duration_minutes' => 240,
            'opening_hours' => null,
        ]);
    }

    public function test_demo_page_loads(): void
    {
        $this->get('/demo')
            ->assertOk()
            ->assertSee('Simulación de concurrencia')
            ->assertSee('Sala A');
    }

    public function test_availability_partial_returns_slots(): void
    {
        [$start] = $this->slot();

        $this->get("/demo/availability?resource={$this->resource->id}&date={$start->toDateString()}")
            ->assertOk()
            ->assertSee('data-slot', false)
            ->assertSee('slot-grid', false);
    }

    public function test_booking_form_creates_a_hold(): void
    {
        [$start, $end] = $this->slot();

        $this->post('/demo/reservations', [
            'resource_id' => $this->resource->id,
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
        ])
            ->assertOk()
            ->assertSee('Hold creado')
            ->assertSee('hx-swap-oob', false);

        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_booking_conflict_shows_the_domain_error(): void
    {
        [$start, $end] = $this->slot();

        app(ReservationService::class)->hold($this->resource, $start, $end);

        $this->post('/demo/reservations', [
            'resource_id' => $this->resource->id,
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
        ])
            ->assertOk()
            ->assertSee('slot_already_taken')
            ->assertDontSee('Hold creado');
    }

    public function test_simulation_endpoint_aggregates_concurrent_attempts(): void
    {
        Http::fake(function (ClientRequest $request) {
            static $calls = 0;
            $calls++;

            return $calls === 1
                ? Http::response(['id' => 'winning-reservation'], 201)
                : Http::response(['error' => 'slot_already_taken'], 409);
        });

        [$start, $end] = $this->slot();

        $response = $this->postJson('/api/simulate/reservations', [
            'resource_id' => $this->resource->id,
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
            'n' => 5,
        ]);

        $response->assertOk()
            ->assertJson([
                'n' => 5,
                'succeeded' => 1,
                'rejected' => 4,
                'errors' => [],
            ]);

        $this->assertIsInt($response->json('duration_ms'));
        Http::assertSentCount(5);

        $keys = [];
        Http::assertSent(function (ClientRequest $request) use (&$keys) {
            $keys[] = $request->header('Idempotency-Key')[0] ?? null;

            return true;
        });

        $this->assertCount(5, array_unique(array_filter($keys)));
    }

    public function test_simulation_rejects_more_than_the_configured_max(): void
    {
        [$start, $end] = $this->slot();

        $this->postJson('/api/simulate/reservations', [
            'resource_id' => $this->resource->id,
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
            'n' => (int) config('slotlock.simulate_max_n') + 1,
        ])->assertStatus(422)->assertJsonValidationErrors('n');
    }

    public function test_demo_simulation_renders_the_result(): void
    {
        Http::fake(fn () => Http::response(['error' => 'slot_already_taken'], 409));

        [$start, $end] = $this->slot();

        $this->post('/demo/simulate', [
            'resource_id' => $this->resource->id,
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
            'n' => 4,
        ])
            ->assertOk()
            ->assertSee('creadas')
            ->assertSee('rechazadas (409)')
            ->assertSee('sim-bar', false);
    }

    /**
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
}
