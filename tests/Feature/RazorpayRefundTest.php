<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\YaanFixtures;
use Tests\TestCase;

/**
 * B4: a booking is only marked refunded when Razorpay really refunds it.
 */
class RazorpayRefundTest extends TestCase
{
    use RefreshDatabase, YaanFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createYaanFixtures();
        config(['services.razorpay.key_id' => 'rzp_test_key', 'services.razorpay.key_secret' => 'test_secret']);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function refund(Booking $booking)
    {
        return $this->postJson("/api/admin/transactions/{$booking->id}/refund", ['reason' => 'Customer requested refund']);
    }

    public function test_processed_refund_marks_booking_refunded(): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'rfnd_real123', 'status' => 'processed', 'amount' => 5000], 200)]);
        $booking = $this->paidOnlineBooking();

        $this->refund($booking)->assertOk()->assertJson(['success' => true]);

        $booking->refresh();
        $this->assertEquals('refunded', $booking->payment_status);
        $this->assertEquals('cancelled', $booking->status);
        $this->assertStringContainsString('rfnd_real123', $booking->cancellation_reason);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/payments/pay_test123/refund') && $req['amount'] === 5000);
    }

    public function test_pending_refund_marks_booking_refund_initiated(): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'rfnd_pend456', 'status' => 'pending'], 200)]);
        $booking = $this->paidOnlineBooking();

        $this->refund($booking)->assertOk();

        $this->assertEquals('refund_initiated', $booking->fresh()->payment_status);
    }

    public function test_razorpay_failure_returns_502_and_changes_nothing(): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['error' => ['description' => 'The payment has been fully refunded already']], 400)]);
        $booking = $this->paidOnlineBooking();

        $res = $this->refund($booking)->assertStatus(502)->assertJson(['success' => false]);
        $this->assertStringContainsString('fully refunded already', $res->json('message'));

        $booking->refresh();
        $this->assertEquals('paid', $booking->payment_status);
        $this->assertEquals('confirmed', $booking->status);
        $this->assertNull($booking->cancellation_reason);
        $this->assertEquals(0, \App\Models\TransactionStatusOverride::count());
    }

    public function test_razorpay_unreachable_returns_502_and_changes_nothing(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        $booking = $this->paidOnlineBooking();

        $this->refund($booking)->assertStatus(502);
        $this->assertEquals('paid', $booking->fresh()->payment_status);
    }

    public function test_booking_without_razorpay_payment_id_is_rejected(): void
    {
        Http::fake();
        $booking = $this->bookPayAtHotel();
        Sanctum::actingAs($this->admin, ['*']);

        $this->refund($booking)->assertStatus(422);

        Http::assertNothingSent();
        $this->assertNotEquals('refunded', $booking->fresh()->payment_status);
    }

    public function test_already_refunded_or_initiated_is_rejected(): void
    {
        Http::fake();
        foreach (['refunded', 'refund_initiated'] as $status) {
            $booking = $this->paidOnlineBooking();
            $booking->update(['payment_status' => $status]);

            $this->refund($booking)->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_refund_pending_after_customer_cancel_can_be_refunded(): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'rfnd_ok789', 'status' => 'processed'], 200)]);
        $booking = $this->paidOnlineBooking();
        $booking->update(['status' => 'cancelled', 'payment_status' => Booking::REFUND_PENDING]);

        $this->refund($booking)->assertOk();
        $this->assertEquals('refunded', $booking->fresh()->payment_status);
    }
}
