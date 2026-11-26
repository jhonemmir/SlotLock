<?php

namespace Tests\Feature;

use App\Exceptions\InvalidReservationWindow;
use App\Models\Resource;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class OpeningHoursTest extends TestCase
{
    use DatabaseTruncation;

    public function test_rejects_a_reservation_before_opening(): void
    {
        $resource = $this->resource();
        $day = $this->date();

        $this->expectException(InvalidReservationWindow::class);

        app(ReservationService::class)->hold(
            $resource,
            $day->setTime(8, 0),
            $day->setTime(9, 0),
        );
    }

    public function test_rejects_a_reservation_after_closing(): void
    {
        $resource = $this->resource();
        $day = $this->date();

        $this->expectException(InvalidReservationWindow::class);

        app(ReservationService::class)->hold(
            $resource,
            $day->setTime(16, 30),
            $day->setTime(17, 30),
        );
    }

    public function test_accepts_a_reservation_ending_at_closing_time(): void
    {
        $resource = $this->resource();
        $day = $this->date();

        $reservation = app(ReservationService::class)->hold(
            $resource,
            $day->setTime(16, 0),
            $day->setTime(17, 0),
        );

        $this->assertSame('held', $reservation->status->value);
    }

    public function test_availability_only_lists_slots_inside_opening_hours(): void
    {
        $resource = $this->resource();
        $day = $this->date();

        $response = $this->getJson(
            "/api/resources/{$resource->id}/availability?date={$day->toDateString()}"
        )->assertOk();

        $slots = collect($response->json('slots'));

        $this->assertCount(32, $slots);
        $this->assertSame($day->setTime(9, 0)->toIso8601String(), $slots->first()['start']);
        $this->assertSame($day->setTime(16, 45)->toIso8601String(), $slots->last()['start']);
    }

    public function test_a_closed_day_has_no_slots(): void
    {
        $day = $this->date();
        $closed = strtolower($day->format('D'));

        $hours = collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
            ->reject(fn (string $key) => $key === $closed)
            ->mapWithKeys(fn (string $key) => [$key => ['09:00', '17:00']])
            ->all();

        $resource = $this->resource($hours);

        $response = $this->getJson(
            "/api/resources/{$resource->id}/availability?date={$day->toDateString()}"
        )->assertOk();

        $this->assertSame([], $response->json('slots'));
    }

    public function test_an_overnight_window_covers_the_next_morning(): void
    {
        $resource = $this->resource([
            'mon' => ['22:00', '02:00'],
            'tue' => ['22:00', '02:00'],
            'wed' => ['22:00', '02:00'],
            'thu' => ['22:00', '02:00'],
            'fri' => ['22:00', '02:00'],
            'sat' => ['22:00', '02:00'],
            'sun' => ['22:00', '02:00'],
        ]);

        $night = $this->date()->setTime(23, 0);

        $hold = app(ReservationService::class)->hold($resource, $night, $night->addHours(2));

        $this->assertSame('held', $hold->status->value);

        $nextMorning = $this->date()->addDay();
        $response = $this->getJson(
            "/api/resources/{$resource->id}/availability?date={$nextMorning->toDateString()}"
        )->assertOk();

        $slots = collect($response->json('slots'));

        $this->assertCount(16, $slots);
        $this->assertSame($nextMorning->toIso8601String(), $slots->first()['start']);
        $this->assertTrue($slots->contains(
            fn (array $slot) => str_starts_with($slot['start'], $nextMorning->setTime(2, 0)->toIso8601String()),
        ) === false);

        $this->expectException(InvalidReservationWindow::class);

        app(ReservationService::class)->hold(
            $resource,
            $nextMorning->setTime(1, 30),
            $nextMorning->setTime(2, 30),
        );
    }

    private function resource(?array $hours = null): Resource
    {
        return Resource::create([
            'name' => 'Sala horarios',
            'type' => 'room',
            'capacity' => 1,
            'timezone' => 'UTC',
            'max_duration_minutes' => 240,
            'opening_hours' => $hours ?? [
                'mon' => ['09:00', '17:00'],
                'tue' => ['09:00', '17:00'],
                'wed' => ['09:00', '17:00'],
                'thu' => ['09:00', '17:00'],
                'fri' => ['09:00', '17:00'],
                'sat' => ['09:00', '17:00'],
                'sun' => ['09:00', '17:00'],
            ],
        ]);
    }

    private function date(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays(30)->startOfDay();
    }
}
