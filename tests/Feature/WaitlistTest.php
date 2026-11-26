<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\WaitlistStatus;
use App\Mail\WaitlistPromoted;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\ReservationService;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WaitlistTest extends TestCase
{
    use DatabaseTruncation;

    private Resource $resource;

    private User $owner;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->resource = Resource::create([
            'name' => 'Sala A',
            'type' => 'room',
            'capacity' => 1,
            'timezone' => 'America/Argentina/Buenos_Aires',
            'max_duration_minutes' => 240,
            'opening_hours' => null,
        ]);

        $this->owner = User::factory()->create();
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
    }

    public function test_registers_interest_in_a_busy_slot(): void
    {
        [$start, $end] = $this->slot();
        $this->confirmedReservation($start, $end, $this->owner);

        $response = $this->postJson('/api/waitlist', $this->payload($this->userA, $start, $end));

        $response->assertCreated()
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('user_id', $this->userA->id);

        $this->assertDatabaseHas('waitlist_entries', [
            'resource_id' => $this->resource->id,
            'user_id' => $this->userA->id,
            'status' => 'waiting',
        ]);
    }

    public function test_rejects_a_free_slot(): void
    {
        [$start, $end] = $this->slot();

        $this->postJson('/api/waitlist', $this->payload($this->userA, $start, $end))
            ->assertStatus(409)
            ->assertJsonPath('error', 'slot_is_free');
    }

    public function test_rejects_a_duplicate_waiting_entry(): void
    {
        [$start, $end] = $this->slot();
        $this->confirmedReservation($start, $end, $this->owner);

        $this->postJson('/api/waitlist', $this->payload($this->userA, $start, $end))
            ->assertCreated();

        $this->postJson('/api/waitlist', $this->payload($this->userA, $start, $end))
            ->assertStatus(409)
            ->assertJsonPath('error', 'waitlist_duplicate');
    }

    public function test_rejects_a_misaligned_range(): void
    {
        [$start] = $this->slot();
        $start = $start->addMinutes(7);

        $this->postJson('/api/waitlist', $this->payload($this->userA, $start, $start->addHour()))
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_reservation_window');
    }

    public function test_cancellation_promotes_the_first_entry_with_a_short_hold_and_mail(): void
    {
        [$start, $end] = $this->slot();
        $reservation = $this->confirmedReservation($start, $end, $this->owner);

        $this->postJson('/api/waitlist', $this->payload($this->userA, $start, $end))->assertCreated();
        $this->postJson('/api/waitlist', $this->payload($this->userB, $start, $end))->assertCreated();

        Mail::fake();

        $this->deleteJson("/api/reservations/{$reservation->id}")->assertNoContent();

        $promoted = WaitlistEntry::query()
            ->where('user_id', $this->userA->id)
            ->firstOrFail();

        $this->assertSame(WaitlistStatus::Notified, $promoted->status);
        $this->assertNotNull($promoted->promoted_reservation_id);

        $hold = $promoted->promotedReservation;
        $this->assertSame(ReservationStatus::Held, $hold->status);
        $this->assertSame($this->userA->id, $hold->user_id);
        $this->assertTrue($hold->expires_at->between(now()->addMinutes(4), now()->addMinutes(6)));

        $second = WaitlistEntry::query()->where('user_id', $this->userB->id)->firstOrFail();
        $this->assertSame(WaitlistStatus::Waiting, $second->status);
        $this->assertNull($second->promoted_reservation_id);

        Mail::assertSent(
            WaitlistPromoted::class,
            fn (WaitlistPromoted $mail) => $mail->hasTo($this->userA->email),
        );
        Mail::assertNotSent(
            WaitlistPromoted::class,
            fn (WaitlistPromoted $mail) => $mail->hasTo($this->userB->email),
        );

        $html = (new WaitlistPromoted($promoted->load('resource', 'user'), $hold))->render();
        $this->assertStringContainsString($this->resource->name, $html);
        $this->assertStringContainsString($hold->id, $html);
    }

    public function test_promotion_waits_until_the_whole_desired_range_is_free(): void
    {
        [$start] = $this->slot();
        $firstHalfEnd = $start->addHour();
        $secondHalfEnd = $start->addHours(2);

        $first = $this->confirmedReservation($start, $firstHalfEnd, $this->owner);
        $second = $this->confirmedReservation($firstHalfEnd, $secondHalfEnd, $this->owner);

        // El rango deseado es más amplio que cada slot individual.
        $this->postJson('/api/waitlist', $this->payload($this->userA, $start, $secondHalfEnd))
            ->assertCreated();

        Mail::fake();

        $this->deleteJson("/api/reservations/{$first->id}")->assertNoContent();

        $entry = WaitlistEntry::query()->where('user_id', $this->userA->id)->firstOrFail();
        $this->assertSame(WaitlistStatus::Waiting, $entry->status);
        $this->assertNull($entry->promoted_reservation_id);

        $this->deleteJson("/api/reservations/{$second->id}")->assertNoContent();

        $entry->refresh();
        $this->assertSame(WaitlistStatus::Notified, $entry->status);
        $this->assertNotNull($entry->promoted_reservation_id);
        $this->assertDatabaseHas('reservations', [
            'id' => $entry->promoted_reservation_id,
            'user_id' => $this->userA->id,
            'status' => 'held',
        ]);
    }

    public function test_different_cancellations_promote_distinct_users(): void
    {
        [$start] = $this->slot();
        $firstEnd = $start->addHour();
        $secondEnd = $start->addHours(2);

        $first = $this->confirmedReservation($start, $firstEnd, $this->owner);
        $second = $this->confirmedReservation($firstEnd, $secondEnd, $this->owner);

        $this->postJson('/api/waitlist', $this->payload($this->userA, $start, $firstEnd))->assertCreated();
        $this->postJson('/api/waitlist', $this->payload($this->userB, $firstEnd, $secondEnd))->assertCreated();

        Mail::fake();

        $this->deleteJson("/api/reservations/{$first->id}")->assertNoContent();
        $this->deleteJson("/api/reservations/{$second->id}")->assertNoContent();

        $this->assertSame(
            2,
            WaitlistEntry::query()->where('status', 'notified')->count(),
        );

        $this->assertDatabaseHas('reservations', [
            'user_id' => $this->userA->id,
            'status' => 'held',
        ]);
        $this->assertDatabaseHas('reservations', [
            'user_id' => $this->userB->id,
            'status' => 'held',
        ]);
    }

    public function test_promotion_is_not_duplicated(): void
    {
        [$start, $end] = $this->slot();
        $reservation = $this->confirmedReservation($start, $end, $this->owner);

        $this->postJson('/api/waitlist', $this->payload($this->userA, $start, $end))->assertCreated();

        Mail::fake();

        $this->deleteJson("/api/reservations/{$reservation->id}")->assertNoContent();
        $this->deleteJson("/api/reservations/{$reservation->id}")->assertStatus(409);

        $cancelled = $reservation->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $cancelled->status);

        // Simula una segunda cancelación/promoción sobre el mismo evento.
        $promotedAgain = app(WaitlistService::class)->promote($cancelled);
        $this->assertNull($promotedAgain);

        $this->assertSame(
            1,
            Reservation::query()
                ->where('user_id', $this->userA->id)
                ->where('status', 'held')
                ->count(),
        );
        $this->assertSame(
            1,
            WaitlistEntry::query()->where('status', 'notified')->count(),
        );
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

    private function confirmedReservation(
        CarbonImmutable $start,
        CarbonImmutable $end,
        User $user,
    ): Reservation {
        $reservations = app(ReservationService::class);
        $reservation = $reservations->hold($this->resource, $start, $end, $user);

        return $reservations->confirm($reservation);
    }

    /**
     * @return array<string, string>
     */
    private function payload(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'resource_id' => $this->resource->id,
            'user_id' => $user->id,
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
        ];
    }
}
