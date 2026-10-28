<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\OwnerProfile;
use App\Models\Review;
use App\Models\User;
use App\Support\BusinessDate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingAndAccountFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Hotel $hotel;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup approved owner and hotel
        $this->owner = User::create([
            'name'        => 'Test Owner',
            'email'       => 'test_owner@example.com',
            'phone'       => '9876543210',
            'role'        => 'owner',
            'password'    => Hash::make('password123'),
            'is_verified' => true,
        ]);

        OwnerProfile::create([
            'user_id'             => $this->owner->id,
            'status'              => 'approved',
            'is_profile_complete' => true,
        ]);

        $this->hotel = Hotel::create([
            'owner_id'        => $this->owner->id,
            'name'            => 'Highway Hotel',
            'description'     => 'Safe and secure rest stop',
            'address'         => 'NH 48 KM 50',
            'city'            => 'Surat',
            'state'           => 'Gujarat',
            'latitude'        => 21.1702,
            'longitude'       => 72.8311,
            'price_per_night' => 500.00,
            'total_rooms'     => 1, // 1 room for clear capacity testing
            'available_rooms' => 1,
            'status'          => 'approved',
        ]);

        // Setup verified customer
        $this->customer = User::create([
            'name'        => 'Driver Suresh',
            'email'       => 'suresh@logistics.com',
            'phone'       => '9123456789',
            'role'        => 'user',
            'password'    => Hash::make('password123'),
            'is_verified' => true,
        ]);
    }

    /**
     * 1. Slot holding prevents overbooking during pending checkout.
     */
    public function test_slot_holding_prevents_overbooking_during_pending_checkout(): void
    {
        Sanctum::actingAs($this->customer, ['*']);

        $targetDate = BusinessDate::today();

        // 1st booking holds the only room
        $res1 = $this->postJson('/api/user/bookings', [
            'hotel_id'       => $this->hotel->id,
            'check_in'       => $targetDate,
            'check_out'      => BusinessDate::nextDay($targetDate),
            'booking_date'   => $targetDate,
            'payment_method' => 'Razorpay',
            'truck_no'       => 'GJ05AB1234',
            'truck_type'     => 'Container',
        ]);
        $res1->assertStatus(201);

        // Another customer tries to book the same room on the same date
        $customer2 = User::create([
            'name'        => 'Driver 2',
            'email'       => 'driver2@logistics.com',
            'phone'       => '9123456780',
            'role'        => 'user',
            'password'    => Hash::make('password123'),
            'is_verified' => true,
        ]);
        Sanctum::actingAs($customer2, ['*']);

        $res2 = $this->postJson('/api/user/bookings', [
            'hotel_id'       => $this->hotel->id,
            'check_in'       => $targetDate,
            'check_out'      => BusinessDate::nextDay($targetDate),
            'booking_date'   => $targetDate,
            'payment_method' => 'Razorpay',
            'truck_no'       => 'MH12CD5678',
            'truck_type'     => 'Trailer',
        ]);

        $res2->assertStatus(422)
            ->assertJsonStructure(['error', 'message']);
    }

    /**
     * 2. Slot hold expires after 15 minutes.
     */
    public function test_slot_hold_expires_after_15_minutes(): void
    {
        $targetDate = BusinessDate::today();

        // Create pending booking created 20 minutes ago
        $booking = Booking::create([
            'user_id'          => $this->customer->id,
            'hotel_id'         => $this->hotel->id,
            'check_in'         => $targetDate,
            'check_out'        => BusinessDate::nextDay($targetDate),
            'booking_date'     => $targetDate,
            'status'           => 'pending',
            'payment_status'   => 'pending',
            'payment_method'   => 'Razorpay',
            'price_per_night'  => 500,
            'total_amount'     => 500,
            'total_payable'    => 590,
            'gst_amount'       => 90,
        ]);

        DB::table('bookings')->where('id', $booking->id)->update([
            'created_at' => Carbon::now()->subMinutes(20)->toDateTimeString(),
        ]);

        // Holding slot count should be 0 because 20 mins > 15 mins
        $holdingCount = Booking::holdingSlot($this->hotel->id, $targetDate)->count();
        $this->assertEquals(0, $holdingCount);
    }

    /**
     * 3. Booking::recordPayment secures booking if room is available.
     */
    public function test_record_payment_secures_booking_if_room_available(): void
    {
        $targetDate = BusinessDate::today();

        $booking = Booking::create([
            'user_id'         => $this->customer->id,
            'hotel_id'        => $this->hotel->id,
            'check_in'        => $targetDate,
            'check_out'       => BusinessDate::nextDay($targetDate),
            'booking_date'    => $targetDate,
            'status'          => 'pending',
            'payment_status'  => 'pending',
            'payment_method'  => 'Razorpay',
            'price_per_night' => 500,
            'total_amount'    => 500,
            'total_payable'   => 590,
            'gst_amount'      => 90,
        ]);

        $secured = $booking->recordPayment('pay_test_123', ['gateway_response' => ['status' => 'captured']]);

        $this->assertTrue($secured);
        $booking->refresh();
        $this->assertEquals('confirmed', $booking->status);
        $this->assertEquals('paid', $booking->payment_status);
        $this->assertEquals('pay_test_123', $booking->razorpay_payment_id);
    }

    /**
     * 4. Booking::recordPayment cancels and sets refund_pending if capacity was lost.
     */
    public function test_record_payment_cancels_and_sets_refund_pending_if_capacity_reached(): void
    {
        $targetDate = BusinessDate::today();

        // 1st confirmed booking takes the only available room
        Booking::create([
            'user_id'         => $this->customer->id,
            'hotel_id'        => $this->hotel->id,
            'check_in'        => $targetDate,
            'check_out'       => BusinessDate::nextDay($targetDate),
            'booking_date'    => $targetDate,
            'status'          => 'confirmed',
            'payment_status'  => 'paid',
            'price_per_night' => 500,
            'total_amount'    => 500,
            'total_payable'   => 590,
            'gst_amount'      => 90,
        ]);

        // 2nd booking whose hold expired pays late
        $lateBooking = Booking::create([
            'user_id'         => $this->customer->id,
            'hotel_id'        => $this->hotel->id,
            'check_in'        => $targetDate,
            'check_out'       => BusinessDate::nextDay($targetDate),
            'booking_date'    => $targetDate,
            'status'          => 'pending',
            'payment_status'  => 'pending',
            'payment_method'  => 'Razorpay',
            'price_per_night' => 500,
            'total_amount'    => 500,
            'total_payable'   => 590,
            'gst_amount'      => 90,
        ]);

        $secured = $lateBooking->recordPayment('pay_late_456');

        $this->assertFalse($secured);
        $lateBooking->refresh();
        $this->assertEquals('cancelled', $lateBooking->status);
        $this->assertEquals(Booking::REFUND_PENDING, $lateBooking->payment_status);
    }

    /**
     * 5. verifyPayment returns HTTP 409 Conflict when slot lost after hold expired.
     */
    public function test_verify_payment_returns_409_conflict_when_slot_lost_after_hold_expired(): void
    {
        Config::set('services.razorpay.key_secret', 'test_secret_123');

        $targetDate = BusinessDate::today();

        // Another user took the only room
        Booking::create([
            'user_id'         => $this->customer->id,
            'hotel_id'        => $this->hotel->id,
            'check_in'        => $targetDate,
            'check_out'       => BusinessDate::nextDay($targetDate),
            'booking_date'    => $targetDate,
            'status'          => 'confirmed',
            'payment_status'  => 'paid',
            'price_per_night' => 500,
            'total_amount'    => 500,
            'total_payable'   => 590,
            'gst_amount'      => 90,
        ]);

        $myBooking = Booking::create([
            'user_id'           => $this->customer->id,
            'hotel_id'          => $this->hotel->id,
            'check_in'          => $targetDate,
            'check_out'         => BusinessDate::nextDay($targetDate),
            'booking_date'      => $targetDate,
            'status'            => 'pending',
            'payment_status'    => 'pending',
            'payment_method'    => 'Razorpay',
            'razorpay_order_id' => 'order_test_999',
            'price_per_night'   => 500,
            'total_amount'      => 500,
            'total_payable'     => 590,
            'gst_amount'        => 90,
        ]);

        $paymentId = 'pay_test_999';
        $signature = hash_hmac('sha256', 'order_test_999|' . $paymentId, 'test_secret_123');

        Sanctum::actingAs($this->customer, ['*']);

        $res = $this->postJson("/api/user/bookings/{$myBooking->id}/verify-payment", [
            'razorpay_order_id'   => 'order_test_999',
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature'  => $signature,
        ]);

        $res->assertStatus(409)
            ->assertJson([
                'error'          => 'Payment succeeded, but your slot hold expired and the room was taken. A refund has been initiated.',
                'refund_pending' => true,
            ]);

        $myBooking->refresh();
        $this->assertEquals('cancelled', $myBooking->status);
        $this->assertEquals('refund_pending', $myBooking->payment_status);
    }

    /**
     * 6. verifyPayment rejects tampered Razorpay signature with HTTP 400.
     */
    public function test_verify_payment_rejects_tampered_razorpay_signature(): void
    {
        Config::set('services.razorpay.key_secret', 'test_secret_123');

        $booking = Booking::create([
            'user_id'           => $this->customer->id,
            'hotel_id'          => $this->hotel->id,
            'check_in'          => BusinessDate::today(),
            'check_out'         => BusinessDate::nextDay(BusinessDate::today()),
            'booking_date'      => BusinessDate::today(),
            'status'            => 'pending',
            'payment_status'    => 'pending',
            'razorpay_order_id' => 'order_tamper_1',
            'price_per_night'   => 500,
            'total_amount'      => 500,
            'total_payable'     => 590,
            'gst_amount'        => 90,
        ]);

        Sanctum::actingAs($this->customer, ['*']);

        $res = $this->postJson("/api/user/bookings/{$booking->id}/verify-payment", [
            'razorpay_order_id'   => 'order_tamper_1',
            'razorpay_payment_id' => 'pay_tamper_1',
            'razorpay_signature'  => 'invalid_forged_signature',
        ]);

        $res->assertStatus(400)
            ->assertJsonStructure(['error', 'message']);
    }

    /**
     * 7. Webhook rejects invalid signature with 400.
     */
    public function test_webhook_rejects_invalid_signature_with_400(): void
    {
        Config::set('services.razorpay.webhook_secret', 'wh_secret_xyz');

        $payload = json_encode(['event' => 'payment.captured']);

        $res = $this->postJson('/api/razorpay-webhook', json_decode($payload, true), [
            'X-Razorpay-Signature' => 'bad_signature_abc',
        ]);

        $res->assertStatus(400)
            ->assertJson([
                'error'   => 'Invalid webhook signature',
                'message' => 'The provided signature does not match RAZORPAY_WEBHOOK_SECRET.',
            ]);
    }

    /**
     * 8. Webhook processes valid payment.captured signature atomically.
     */
    public function test_webhook_records_payment_atomically(): void
    {
        Config::set('services.razorpay.webhook_secret', 'wh_secret_xyz');

        $targetDate = BusinessDate::today();

        $booking = Booking::create([
            'user_id'           => $this->customer->id,
            'hotel_id'          => $this->hotel->id,
            'check_in'          => $targetDate,
            'check_out'         => BusinessDate::nextDay($targetDate),
            'booking_date'      => $targetDate,
            'status'            => 'pending',
            'payment_status'    => 'pending',
            'payment_method'    => 'Razorpay',
            'razorpay_order_id' => 'order_wh_123',
            'price_per_night'   => 500,
            'total_amount'      => 500,
            'total_payable'     => 590,
            'gst_amount'        => 90,
        ]);

        $payload = [
            'event'   => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id'       => 'pay_wh_captured_1',
                        'order_id' => 'order_wh_123',
                        'status'   => 'captured',
                        'amount'   => 59000,
                    ]
                ]
            ]
        ];

        $rawJson = json_encode($payload);
        $sig = hash_hmac('sha256', $rawJson, 'wh_secret_xyz');

        $res = $this->call('POST', '/api/razorpay-webhook', [], [], [], [
            'HTTP_X-Razorpay-Signature' => $sig,
            'CONTENT_TYPE'              => 'application/json',
        ], $rawJson);

        $res->assertStatus(200);

        $booking->refresh();
        $this->assertEquals('confirmed', $booking->status);
        $this->assertEquals('paid', $booking->payment_status);
        $this->assertEquals('pay_wh_captured_1', $booking->razorpay_payment_id);
    }

    /**
     * 9. Customer cannot exceed 5 pay_at_hotel bookings per date.
     */
    public function test_customer_cannot_exceed_5_pay_at_hotel_bookings_per_date(): void
    {
        // Increase rooms to allow 5 bookings
        $this->hotel->update(['total_rooms' => 10, 'available_rooms' => 10]);

        $targetDate = BusinessDate::today();

        // Create 5 pay at hotel bookings
        for ($i = 0; $i < 5; $i++) {
            Booking::create([
                'user_id'         => $this->customer->id,
                'hotel_id'        => $this->hotel->id,
                'check_in'        => $targetDate,
                'check_out'       => BusinessDate::nextDay($targetDate),
                'booking_date'    => $targetDate,
                'status'          => 'confirmed',
                'payment_status'  => 'pending',
                'payment_method'  => 'pay_at_hotel',
                'price_per_night' => 500,
                'total_amount'    => 500,
                'total_payable'   => 590,
                'gst_amount'      => 90,
            ]);
        }

        Sanctum::actingAs($this->customer, ['*']);

        // 6th booking must be blocked
        $res = $this->postJson('/api/user/bookings', [
            'hotel_id'       => $this->hotel->id,
            'check_in'       => $targetDate,
            'check_out'      => BusinessDate::nextDay($targetDate),
            'booking_date'   => $targetDate,
            'payment_method' => 'pay_at_hotel',
            'truck_no'       => 'GJ01AB1111',
            'truck_type'     => 'Container',
        ]);

        $res->assertStatus(422)
            ->assertJson([
                'error'   => 'Booking limit reached.',
                'message' => 'You have reached the maximum limit of 5 Pay at Hotel bookings for this date.',
            ]);
    }

    /**
     * 10. Booking validates Indian vehicle registration number format.
     */
    public function test_customer_booking_validates_indian_vehicle_number_format(): void
    {
        Sanctum::actingAs($this->customer, ['*']);

        $res = $this->postJson('/api/user/bookings', [
            'hotel_id'       => $this->hotel->id,
            'check_in'       => BusinessDate::today(),
            'check_out'      => BusinessDate::nextDay(BusinessDate::today()),
            'booking_date'   => BusinessDate::today(),
            'payment_method' => 'Razorpay',
            'truck_no'       => 'INVALID-TRUCK-NO',
            'truck_type'     => 'Container',
        ]);

        $res->assertStatus(422);
    }

    /**
     * 11. Booking pricing is calculated strictly server-side.
     */
    public function test_booking_pricing_calculated_strictly_server_side(): void
    {
        Sanctum::actingAs($this->customer, ['*']);

        // Attacker attempts to send total_payable = 10 for a ₹500 room
        $res = $this->postJson('/api/user/bookings', [
            'hotel_id'       => $this->hotel->id,
            'check_in'       => BusinessDate::today(),
            'check_out'      => BusinessDate::nextDay(BusinessDate::today()),
            'booking_date'   => BusinessDate::today(),
            'payment_method' => 'Razorpay',
            'truck_no'       => 'GJ05CD9876',
            'truck_type'     => 'Container',
            'total_payable'  => 10, // Tampered!
            'price_per_night'=> 10,
        ]);

        $res->assertStatus(201);

        $booking = Booking::where('user_id', $this->customer->id)->latest()->first();
        // Base: 500, GST 18%: 90 -> total_payable must be 590, NOT 10!
        $this->assertEquals(500, (float)$booking->price_per_night);
        $this->assertEquals(590, (float)$booking->total_payable);
    }

    /**
     * 12. Active coupon applied by exact code match.
     */
    public function test_active_coupon_applied_by_exact_code_match(): void
    {
        Banner::create([
            'title'               => 'Discount 10%',
            'description'         => '10% off',
            'discount_code'       => 'DISCOUNT50',
            'discount_percentage' => 10,
            'target_audience'     => 'user',
            'is_active'           => true,
            'max_uses'            => 10,
            'expires_at'          => Carbon::now()->addDays(10),
        ]);

        Sanctum::actingAs($this->customer, ['*']);

        $res = $this->postJson('/api/user/bookings', [
            'hotel_id'       => $this->hotel->id,
            'check_in'       => BusinessDate::today(),
            'check_out'      => BusinessDate::nextDay(BusinessDate::today()),
            'booking_date'   => BusinessDate::today(),
            'payment_method' => 'Razorpay',
            'truck_no'       => 'GJ05CD9876',
            'truck_type'     => 'Container',
            'discount_code'  => 'DISCOUNT50',
        ]);

        $res->assertStatus(201);

        $booking = Booking::where('user_id', $this->customer->id)->latest()->first();
        // 500 - 50 = 450 base. GST 18% on 450 = 81. Total payable = 531.
        $this->assertEquals('DISCOUNT50', $booking->discount_code);
        $this->assertEquals(531, (float)$booking->total_payable);
    }

    /**
     * 13. Inactive or expired coupon is not applied to booking.
     */
    public function test_inactive_or_expired_coupon_rejected(): void
    {
        Banner::create([
            'title'               => 'Expired 20%',
            'description'         => '20% off',
            'discount_code'       => 'EXPIRED20',
            'discount_percentage' => 20,
            'target_audience'     => 'user',
            'is_active'           => false,
            'expires_at'          => Carbon::now()->subDay(),
        ]);

        Sanctum::actingAs($this->customer, ['*']);

        $res = $this->postJson('/api/user/bookings', [
            'hotel_id'       => $this->hotel->id,
            'check_in'       => BusinessDate::today(),
            'check_out'      => BusinessDate::nextDay(BusinessDate::today()),
            'booking_date'   => BusinessDate::today(),
            'payment_method' => 'Razorpay',
            'truck_no'       => 'GJ05CD9876',
            'truck_type'     => 'Container',
            'discount_code'  => 'EXPIRED20',
        ]);

        $res->assertStatus(201);

        $booking = Booking::where('user_id', $this->customer->id)->latest()->first();
        $this->assertNull($booking->discount_code);
        $this->assertEquals(590, (float)$booking->total_payable);
    }

    /**
     * 14. Customer cannot cancel booking after check-in date.
     */
    public function test_customer_cannot_cancel_past_check_in_booking(): void
    {
        $pastDate = Carbon::now()->subDays(2)->format('Y-m-d');

        $booking = Booking::create([
            'user_id'         => $this->customer->id,
            'hotel_id'        => $this->hotel->id,
            'check_in'        => $pastDate,
            'check_out'       => Carbon::now()->subDay()->format('Y-m-d'),
            'booking_date'    => $pastDate,
            'status'          => 'confirmed',
            'payment_status'  => 'paid',
            'price_per_night' => 500,
            'total_amount'    => 500,
            'total_payable'   => 590,
            'gst_amount'      => 90,
        ]);

        Sanctum::actingAs($this->customer, ['*']);

        $res = $this->postJson("/api/user/bookings/{$booking->id}/cancel", [
            'cancellation_reason' => 'Could not make it',
        ]);

        $res->assertStatus(422)
            ->assertJson([
                'error' => 'Cannot cancel a booking whose date has passed.',
            ]);
    }

    /**
     * 15. Customer cancelling paid booking sets refund_pending.
     */
    public function test_customer_cancelling_paid_booking_sets_refund_pending(): void
    {
        $futureDate = Carbon::now()->addDays(5)->format('Y-m-d');

        $booking = Booking::create([
            'user_id'         => $this->customer->id,
            'hotel_id'        => $this->hotel->id,
            'check_in'        => $futureDate,
            'check_out'       => Carbon::now()->addDays(6)->format('Y-m-d'),
            'booking_date'    => $futureDate,
            'status'          => 'confirmed',
            'payment_status'  => 'paid',
            'price_per_night' => 500,
            'total_amount'    => 500,
            'total_payable'   => 590,
            'gst_amount'      => 90,
        ]);

        Sanctum::actingAs($this->customer, ['*']);

        $res = $this->postJson("/api/user/bookings/{$booking->id}/cancel", [
            'cancellation_reason' => 'Trip plans changed.',
        ]);

        $res->assertStatus(200);

        $booking->refresh();
        $this->assertEquals('cancelled', $booking->status);
        $this->assertEquals('refund_pending', $booking->payment_status);
    }

    /**
     * 16. Customer cancelling unpaid booking sets cancelled without refund.
     */
    public function test_customer_cancelling_unpaid_booking_sets_cancelled(): void
    {
        $futureDate = Carbon::now()->addDays(5)->format('Y-m-d');

        $booking = Booking::create([
            'user_id'         => $this->customer->id,
            'hotel_id'        => $this->hotel->id,
            'check_in'        => $futureDate,
            'check_out'       => Carbon::now()->addDays(6)->format('Y-m-d'),
            'booking_date'    => $futureDate,
            'status'          => 'pending',
            'payment_status'  => 'pending',
            'price_per_night' => 500,
            'total_amount'    => 500,
            'total_payable'   => 590,
            'gst_amount'      => 90,
        ]);

        Sanctum::actingAs($this->customer, ['*']);

        $res = $this->postJson("/api/user/bookings/{$booking->id}/cancel", [
            'cancellation_reason' => 'Changed my mind.',
        ]);

        $res->assertStatus(200);

        $booking->refresh();
        $this->assertEquals('cancelled', $booking->status);
        $this->assertEquals('pending', $booking->payment_status);
    }

    /**
     * 17. Customer cannot review hotel before completed stay.
     */
    public function test_customer_cannot_review_hotel_before_completed_or_past_paid_stay(): void
    {
        $futureDate = Carbon::now()->addDays(3)->format('Y-m-d');

        $booking = Booking::create([
            'user_id'         => $this->customer->id,
            'hotel_id'        => $this->hotel->id,
            'check_in'        => $futureDate,
            'check_out'       => Carbon::now()->addDays(4)->format('Y-m-d'),
            'booking_date'    => $futureDate,
            'status'          => 'confirmed',
            'payment_status'  => 'paid',
            'price_per_night' => 500,
            'total_amount'    => 500,
            'total_payable'   => 590,
            'gst_amount'      => 90,
        ]);

        Sanctum::actingAs($this->customer, ['*']);

        $res = $this->postJson('/api/user/reviews', [
            'hotel_id' => $this->hotel->id,
            'rating'   => 5,
            'comment'  => 'Great place before even staying!',
        ]);

        $res->assertStatus(403)
            ->assertJson([
                'error' => 'You can review a hotel after your stay there is complete.',
            ]);
    }

    /**
     * 18. Customer can review hotel after completed stay.
     */
    public function test_customer_can_review_completed_stay(): void
    {
        $pastDate = Carbon::now()->subDays(3)->format('Y-m-d');

        Booking::create([
            'user_id'         => $this->customer->id,
            'hotel_id'        => $this->hotel->id,
            'check_in'        => $pastDate,
            'check_out'       => Carbon::now()->subDays(2)->format('Y-m-d'),
            'booking_date'    => $pastDate,
            'status'          => 'completed',
            'payment_status'  => 'paid',
            'price_per_night' => 500,
            'total_amount'    => 500,
            'total_payable'   => 590,
            'gst_amount'      => 90,
        ]);

        Sanctum::actingAs($this->customer, ['*']);

        $res = $this->postJson('/api/user/reviews', [
            'hotel_id' => $this->hotel->id,
            'rating'   => 5,
            'comment'  => 'Excellent secure parking and food!',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('reviews', [
            'user_id'  => $this->customer->id,
            'hotel_id' => $this->hotel->id,
            'rating'   => 5,
        ]);
    }

    /**
     * 19. Hotel QR code is random and unguessable (YAAN-XXXXXXXX).
     */
    public function test_hotel_qr_code_is_random_and_unguessable(): void
    {
        $this->hotel->refresh();

        $this->assertNotNull($this->hotel->yaan_id);
        $this->assertStringStartsWith('YAAN-', $this->hotel->yaan_id);
        $this->assertEquals(13, strlen($this->hotel->yaan_id)); // 'YAAN-' (5) + 8 chars = 13
    }

    /**
     * 20. scanQr requires exact yaan_id and rejects plain integer IDs.
     */
    public function test_hotel_scan_qr_requires_exact_yaan_id_not_integer_id(): void
    {
        Sanctum::actingAs($this->customer, ['*']);

        // Attempt scan using plain integer string "1"
        $res = $this->postJson('/api/hotels/scan-qr', [
            'qr_code' => (string)$this->hotel->id,
        ]);

        $res->assertStatus(404)
            ->assertJson([
                'error' => 'Hotel not found.',
            ]);

        // Attempt scan using real yaan_id
        $resValid = $this->postJson('/api/hotels/scan-qr', [
            'qr_code' => $this->hotel->yaan_id,
        ]);

        $resValid->assertStatus(200)
            ->assertJson([
                'status'     => 'verified',
                'registered' => true,
            ]);
    }

    /**
     * 21. Owner cannot delete hotel that has associated bookings.
     */
    public function test_owner_cannot_delete_hotel_with_associated_bookings(): void
    {
        Booking::create([
            'user_id'         => $this->customer->id,
            'hotel_id'        => $this->hotel->id,
            'check_in'        => BusinessDate::today(),
            'check_out'       => BusinessDate::nextDay(BusinessDate::today()),
            'booking_date'    => BusinessDate::today(),
            'status'          => 'confirmed',
            'payment_status'  => 'paid',
            'price_per_night' => 500,
            'total_amount'    => 500,
            'total_payable'   => 590,
            'gst_amount'      => 90,
        ]);

        Sanctum::actingAs($this->owner, ['*']);

        $res = $this->deleteJson("/api/owner/hotels/{$this->hotel->id}");

        $res->assertStatus(422)
            ->assertJson([
                'error' => 'This hotel has bookings, so it cannot be deleted. Set it to inactive to stop new bookings.',
            ]);

        $this->assertDatabaseHas('hotels', ['id' => $this->hotel->id]);
    }

    /**
     * 22. Owner cannot manually confirm unpaid online booking.
     */
    public function test_owner_cannot_manually_confirm_unpaid_online_booking(): void
    {
        $booking = Booking::create([
            'user_id'         => $this->customer->id,
            'hotel_id'        => $this->hotel->id,
            'check_in'        => BusinessDate::today(),
            'check_out'       => BusinessDate::nextDay(BusinessDate::today()),
            'booking_date'    => BusinessDate::today(),
            'status'          => 'pending',
            'payment_status'  => 'pending',
            'payment_method'  => 'Razorpay',
            'price_per_night' => 500,
            'total_amount'    => 500,
            'total_payable'   => 590,
            'gst_amount'      => 90,
        ]);

        Sanctum::actingAs($this->owner, ['*']);

        $res = $this->putJson("/api/owner/bookings/{$booking->id}/status", [
            'status' => 'confirmed',
        ]);

        $res->assertStatus(422)
            ->assertJson([
                'error' => 'Cannot confirm or complete an unpaid online booking.',
            ]);
    }

    /**
     * 23. Abandoned unverified signup can be restarted.
     */
    public function test_abandoned_unverified_signup_can_be_restarted(): void
    {
        // 1. Initial signup that stopped before OTP verification
        $res1 = $this->postJson('/api/register', [
            'name'     => 'Unfinished User',
            'email'    => 'unfinished@example.com',
            'phone'    => '9777777777',
            'role'     => 'user',
            'password' => 'password123',
        ]);
        $res1->assertStatus(201);

        // 2. Restarting signup with new details is permitted for abandoned accounts
        $res2 = $this->postJson('/api/register', [
            'name'     => 'Restarted User',
            'email'    => 'restarted@example.com',
            'phone'    => '9777777777',
            'role'     => 'user',
            'password' => 'newpassword456',
        ]);
        $res2->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'phone' => '9777777777',
            'name'  => 'Restarted User',
        ]);
    }

    /**
     * 24. Purge unverified users only removes abandoned accounts older than 24 hours.
     */
    public function test_purge_unverified_users_only_deletes_abandoned_accounts_older_than_24h(): void
    {
        // Account A: Abandoned, created 2 days ago -> should be purged
        $oldAbandoned = User::create([
            'name'        => 'Old Abandoned',
            'email'       => 'old_abandoned@example.com',
            'phone'       => '9666666661',
            'role'        => 'user',
            'password'    => Hash::make('pass123'),
            'is_verified' => false,
        ]);
        DB::table('users')->where('id', $oldAbandoned->id)->update([
            'created_at' => Carbon::now()->subDays(2)->toDateTimeString(),
        ]);

        // Account B: Fresh unverified, created 2 hours ago -> should NOT be purged
        $recentUnverified = User::create([
            'name'        => 'Recent Unverified',
            'email'       => 'recent_unverified@example.com',
            'phone'       => '9666666662',
            'role'        => 'user',
            'password'    => Hash::make('pass123'),
            'is_verified' => false,
        ]);
        DB::table('users')->where('id', $recentUnverified->id)->update([
            'created_at' => Carbon::now()->subHours(2)->toDateTimeString(),
        ]);

        // Account C: Verified account -> should NOT be purged
        $verifiedUser = User::create([
            'name'         => 'Verified User',
            'email'        => 'verified@example.com',
            'phone'        => '9666666663',
            'role'         => 'user',
            'password'     => Hash::make('pass123'),
            'is_verified'  => true,
            'firebase_uid' => 'fb_verified_999',
        ]);
        DB::table('users')->where('id', $verifiedUser->id)->update([
            'created_at' => Carbon::now()->subDays(5)->toDateTimeString(),
        ]);

        Artisan::call('users:purge-unverified');

        $this->assertDatabaseMissing('users', ['id' => $oldAbandoned->id]);
        $this->assertDatabaseHas('users', ['id' => $recentUnverified->id]);
        $this->assertDatabaseHas('users', ['id' => $verifiedUser->id]);
    }
}
