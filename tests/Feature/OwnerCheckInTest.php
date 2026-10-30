<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\YaanFixtures;
use Tests\TestCase;

/**
 * A7: owner status changes allowed: pending -> cancelled, confirmed -> completed, confirmed -> cancelled.
 */
class OwnerCheckInTest extends TestCase
{
    use RefreshDatabase, YaanFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createYaanFixtures();
    }

    public function test_owner_check_in_completes_pay_at_hotel_booking_and_marks_cash_paid(): void
    {
        $booking = $this->bookPayAtHotel();
        $this->assertEquals('confirmed', $booking->status);

        Sanctum::actingAs($this->owner, ['*']);
        $this->putJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'completed'])->assertOk();

        $booking->refresh();
        $this->assertEquals('completed', $booking->status);
        $this->assertEquals('paid', $booking->payment_status);

        // A completed booking cannot go back
        $this->putJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'confirmed'])->assertStatus(422);
        $this->putJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'cancelled'])->assertStatus(422);
    }

    public function test_owner_check_in_of_paid_online_booking_keeps_paid(): void
    {
        $booking = $this->paidOnlineBooking();

        Sanctum::actingAs($this->owner, ['*']);
        $this->putJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'completed'])->assertOk();

        $booking->refresh();
        $this->assertEquals('completed', $booking->status);
        $this->assertEquals('paid', $booking->payment_status);
    }

    public function test_owner_cannot_reconfirm_cancelled_booking(): void
    {
        $booking = $this->bookPayAtHotel();
        $booking->update(['status' => 'cancelled']);

        Sanctum::actingAs($this->owner, ['*']);
        $res = $this->putJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'confirmed'])->assertStatus(422);
        $this->assertStringContainsString('cannot be changed', $res->json('message'));
    }

    public function test_pending_booking_can_be_cancelled_but_not_checked_in(): void
    {
        $booking = $this->bookPayAtHotel();
        $booking->update(['status' => 'pending']);

        Sanctum::actingAs($this->owner, ['*']);
        $this->putJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'completed'])->assertStatus(422);
        $this->putJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertEquals('cancelled', $booking->fresh()->status);
    }

    public function test_owner_cancelling_paid_booking_sets_refund_pending(): void
    {
        $booking = $this->paidOnlineBooking();

        Sanctum::actingAs($this->owner, ['*']);
        $this->putJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'cancelled'])->assertOk();

        $booking->refresh();
        $this->assertEquals('cancelled', $booking->status);
        $this->assertEquals(Booking::REFUND_PENDING, $booking->payment_status);
        $this->assertEquals('Cancelled by hotel. Refund pending admin approval.', $booking->cancellation_reason);
    }

    public function test_owner_cannot_change_another_owners_booking(): void
    {
        $booking = $this->bookPayAtHotel();

        $otherOwner = \App\Models\User::create([
            'name' => 'Other', 'email' => 'other@test.com', 'phone' => '9811111111',
            'role' => 'owner', 'password' => bcrypt('password123'), 'is_verified' => true,
        ]);

        Sanctum::actingAs($otherOwner, ['*']);
        $this->putJson("/api/owner/bookings/{$booking->id}/status", ['status' => 'completed'])->assertNotFound();
        $this->assertEquals('confirmed', $booking->fresh()->status);
    }
}
