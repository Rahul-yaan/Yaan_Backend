<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\YaanFixtures;
use Tests\TestCase;

/**
 * A6: the customer app treats a cancel as successful only when the reply has success = true.
 */
class BookingCancelReplyTest extends TestCase
{
    use RefreshDatabase, YaanFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createYaanFixtures();
    }

    public function test_cancel_reply_has_success_flag(): void
    {
        $booking = $this->bookPayAtHotel();

        $this->postJson("/api/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJson(['success' => true, 'refund_pending' => false]);

        // Cancelling again is an error and must NOT say success
        $again = $this->postJson("/api/bookings/{$booking->id}/cancel")->assertStatus(422);
        $this->assertNull($again->json('success'));
        $this->assertNotEmpty($again->json('message'));
    }

    public function test_cancel_of_paid_booking_says_success_and_refund_pending(): void
    {
        $booking = $this->paidOnlineBooking();
        Sanctum::actingAs($this->customer, ['*']);

        $res = $this->postJson("/api/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJson(['success' => true, 'refund_pending' => true]);
        $this->assertStringContainsString('refund', strtolower($res->json('message')));
    }

    public function test_cancel_of_past_booking_is_an_error_without_success(): void
    {
        $booking = $this->bookPayAtHotel();
        Booking::whereKey($booking->id)->update(['booking_date' => BusinessDate::now()->subDays(2)->toDateString()]);

        $res = $this->postJson("/api/bookings/{$booking->id}/cancel")->assertStatus(422);
        $this->assertNull($res->json('success'));
        $this->assertEquals('Cannot cancel a booking whose date has passed.', $res->json('message'));
    }
}
