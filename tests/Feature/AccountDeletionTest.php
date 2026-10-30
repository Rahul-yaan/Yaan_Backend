<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\KycDocument;
use App\Models\OwnerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\YaanFixtures;
use Tests\TestCase;

/**
 * A4: Google Play requires in-app account deletion. Personal data is anonymised,
 * booking/payment records are kept for tax.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase, YaanFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createYaanFixtures();
    }

    public function test_customer_can_delete_account_and_bookings_are_kept(): void
    {
        $booking = $this->bookPayAtHotel();
        $this->customer->createToken('app');

        $this->deleteJson('/api/account')->assertOk()->assertJson(['success' => true]);

        $user = $this->customer->fresh();
        $this->assertEquals('Deleted User', $user->name);
        $this->assertEquals('deleted_' . $user->id . '@deleted.local', $user->email);
        $this->assertEquals('del_' . $user->id, $user->phone);
        $this->assertNull($user->firebase_uid);
        $this->assertNull($user->avatar);
        $this->assertFalse((bool) $user->is_verified);
        $this->assertEquals(0, $user->tokens()->count());
        $this->assertNotNull(Booking::find($booking->id), 'Booking record must be kept for tax');

        // Old login no longer works
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'driver@test.com', 'password' => 'password123', 'role' => 'user'])
            ->assertStatus(401);
    }

    public function test_deleted_customer_still_shows_in_admin_bookings_as_deleted_user(): void
    {
        $booking = $this->bookPayAtHotel();
        $this->deleteJson('/api/account')->assertOk();

        Sanctum::actingAs($this->admin, ['*']);
        $this->getJson("/api/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertSee('Deleted User');
    }

    public function test_owner_delete_account_takes_hotel_offline_and_removes_kyc(): void
    {
        KycDocument::storeCopy($this->owner->id, 'pan_card', 'fake', 'image/png');

        Sanctum::actingAs($this->owner, ['*']);
        $this->deleteJson('/api/account')->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('inactive', $this->hotel->fresh()->status);
        $this->assertNull(OwnerProfile::where('user_id', $this->owner->id)->first());
        $this->assertEquals(0, KycDocument::where('user_id', $this->owner->id)->count());
        $this->assertEquals('Deleted User', $this->owner->fresh()->name);
    }

    public function test_admin_cannot_delete_own_account_from_app(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $this->deleteJson('/api/account')->assertStatus(403);
        $this->assertEquals('Admin', $this->admin->fresh()->name);
    }

    public function test_delete_account_requires_login(): void
    {
        $this->deleteJson('/api/account')->assertStatus(401);
    }

    public function test_account_deletion_page_loads(): void
    {
        $this->get('/account-deletion')
            ->assertOk()
            ->assertSee('Delete Your Yaan Account')
            ->assertSee('support@yaanapp.com');
    }
}
