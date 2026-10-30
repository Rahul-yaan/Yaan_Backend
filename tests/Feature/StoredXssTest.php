<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\YaanFixtures;
use Tests\TestCase;

/**
 * B1: text typed in the apps must not carry HTML/scripts into the admin panel.
 */
class StoredXssTest extends TestCase
{
    use RefreshDatabase, YaanFixtures;

    private const XSS = '<img src=x onerror=alert(1)>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createYaanFixtures();
    }

    public function test_register_strips_html_from_name(): void
    {
        $res = $this->postJson('/api/register', [
            'name' => 'Ramesh' . self::XSS, 'email' => 'ramesh@test.com', 'phone' => '9822222222', 'role' => 'user',
        ])->assertStatus(201);

        $this->assertEquals('Ramesh', User::find($res->json('user_id'))->name);
    }

    public function test_register_with_only_html_name_is_rejected(): void
    {
        $this->postJson('/api/register', [
            'name' => self::XSS, 'email' => 'x@test.com', 'phone' => '9833333333', 'role' => 'user',
        ])->assertStatus(422);
    }

    public function test_update_profile_strips_html_from_name(): void
    {
        Sanctum::actingAs($this->customer, ['*']);
        $this->postJson('/api/user/update-profile', ['name' => 'Suresh<script>alert(1)</script>'])->assertOk();

        $this->assertEquals('Sureshalert(1)', $this->customer->fresh()->name);
    }

    public function test_review_comment_is_stored_without_html(): void
    {
        $booking = $this->bookPayAtHotel();
        Booking::whereKey($booking->id)->update(['status' => 'completed']);

        $this->postJson('/api/reviews', [
            'hotel_id' => $this->hotel->id, 'booking_id' => $booking->id, 'rating' => 5,
            'comment' => 'Great parking' . self::XSS,
        ])->assertSuccessful();

        $this->assertEquals('Great parking', Review::first()->comment);
    }

    public function test_hotel_text_is_stored_without_html(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $this->putJson("/api/owner/hotels/{$this->hotel->id}", [
            'name' => 'Highway Dhaba' . self::XSS,
            'address' => 'NH 48 <b>KM 51</b>',
            'description' => '<script>steal()</script>Clean rooms',
        ])->assertOk();

        $hotel = $this->hotel->fresh();
        $this->assertEquals('Highway Dhaba', $hotel->name);
        $this->assertEquals('NH 48 KM 51', $hotel->address);
        $this->assertEquals('steal()Clean rooms', $hotel->description);
    }

    public function test_hotel_name_that_is_only_html_keeps_old_name(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $this->putJson("/api/owner/hotels/{$this->hotel->id}", ['name' => self::XSS])->assertOk();

        $this->assertEquals('Highway Dhaba', $this->hotel->fresh()->name);
    }

    public function test_owner_profile_strips_html_from_names_and_address(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $this->postJson('/api/owner/profile', [
            'hotel_name' => 'Dhaba' . self::XSS . ' One', 'owner_name' => 'Ram<b>esh</b>',
            'address' => 'NH 48 Near Toll<script>x()</script>', 'city' => 'Bharuch', 'state' => 'Gujarat', 'pincode' => '392001',
            'aadhaar_number' => '234567890123', 'pan_number' => 'ABCDE1234F',
            'bank_name' => 'State Bank of India', 'account_number' => '123456789012', 'ifsc_code' => 'SBIN0001234',
            'aadhaar_front' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'aadhaar_back' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'pan_card' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'business_proof' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
        ])->assertOk();

        $profile = $this->owner->fresh()->ownerProfile;
        $this->assertEquals('Dhaba One', $profile->hotel_name);
        $this->assertEquals('Ramesh', $profile->owner_name);
        $this->assertEquals('NH 48 Near Tollx()', $profile->address);
    }
}
