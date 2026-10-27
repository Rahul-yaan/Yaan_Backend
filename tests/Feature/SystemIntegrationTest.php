<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\OwnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Test complete user registration, authentication, hotel search and booking creation.
     */
    public function test_user_registration_search_and_booking_lifecycle(): void
    {
        // A. Register Owner & create an approved Hotel with an approved OwnerProfile
        $owner = User::create([
            'name'        => 'Hotel Owner Vikram',
            'email'       => 'owner_vikram@example.com',
            'phone'       => '9876543210',
            'role'        => 'owner',
            'password'    => Hash::make('owner123456'),
            'is_verified' => true,
        ]);

        OwnerProfile::create([
            'user_id'             => $owner->id,
            'status'              => 'approved',
            'is_profile_complete' => true,
        ]);

        $hotel = Hotel::create([
            'owner_id'        => $owner->id,
            'name'            => 'Vikram Highway Dhaba & Rest',
            'description'     => 'Safe parking with 24/7 security',
            'address'         => 'NH 48, Mile 120',
            'city'            => 'Surat',
            'state'           => 'Gujarat',
            'latitude'        => 21.1702,
            'longitude'       => 72.8311,
            'price_per_night' => 150.00,
            'total_rooms'     => 10,
            'available_rooms' => 10,
            'status'          => 'approved',
        ]);

        // B. Register User
        $userReg = $this->postJson('/api/register', [
            'name'     => 'Driver Ramesh',
            'email'    => 'ramesh@logistics.com',
            'phone'    => '9123456780',
            'role'     => 'user',
            'password' => 'driver123456',
        ]);
        $userReg->assertStatus(201);

        $user = User::where('email', 'ramesh@logistics.com')->first();
        $user->is_verified = true;
        $user->save();

        // C. Authenticate User
        Sanctum::actingAs($user, ['*']);

        // D. Search Hotels (Integration Test)
        $searchRes = $this->getJson('/api/hotels/search?city=Surat');
        $searchRes->assertStatus(200);

        // E. Create Booking (Integration Test)
        $bookingRes = $this->postJson('/api/bookings', [
            'hotel_id'         => $hotel->id,
            'booking_date'     => now()->addDay()->toDateString(),
            'truck_type'       => '14 Wheel',
            'truck_no'         => 'GJ16AB7888',
            'logistics_name'   => 'National Logistics Express',
            'logistics_number' => '9898001122',
            'payment_method'   => 'pay_at_hotel',
        ]);

        $bookingRes->assertStatus(201);
        $this->assertDatabaseHas('bookings', [
            'user_id'   => $user->id,
            'hotel_id'  => $hotel->id,
            'truck_no'  => 'GJ16AB7888',
            'status'    => 'confirmed',
        ]);

        // Verify available slots decremented
        $hotel->refresh();
        $this->assertEquals(9, $hotel->available_rooms);
    }

    /**
     * 2. Security Test: Role Enforcement prevents User from accessing Owner and Admin APIs.
     */
    public function test_user_cannot_access_owner_or_admin_endpoints(): void
    {
        $user = User::create([
            'name'        => 'Regular Driver',
            'email'       => 'driver_safe@example.com',
            'phone'       => '9123456789',
            'role'        => 'user',
            'password'    => Hash::make('secret123'),
            'is_verified' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        // Attempt accessing Owner route
        $ownerRes = $this->getJson('/api/owner/hotels');
        $ownerRes->assertStatus(403);

        // Attempt accessing Admin route
        $adminRes = $this->getJson('/api/admin/dashboard');
        $adminRes->assertStatus(403);
    }

    /**
     * 3. Security Test: Role Enforcement prevents Owner from accessing Admin APIs.
     */
    public function test_owner_cannot_access_admin_endpoints(): void
    {
        $owner = User::create([
            'name'        => 'Hotel Owner Rajesh',
            'email'       => 'rajesh_owner@example.com',
            'phone'       => '9876543211',
            'role'        => 'owner',
            'password'    => Hash::make('secret123'),
            'is_verified' => true,
        ]);

        Sanctum::actingAs($owner, ['*']);

        // Attempt accessing Admin routes
        $this->getJson('/api/admin/dashboard')->assertStatus(403);
        $this->getJson('/api/admin/transactions')->assertStatus(403);
        $this->getJson('/api/admin/owners')->assertStatus(403);
    }

    /**
     * 4. Security Test: IDOR Protection prevents User A from cancelling User B's booking.
     */
    public function test_user_cannot_cancel_or_tamper_another_users_booking(): void
    {
        $owner = User::create([
            'name' => 'Owner A', 'email' => 'owner_a@example.com',
            'phone' => '9000000001', 'role' => 'owner', 'password' => Hash::make('sec'), 'is_verified' => true
        ]);
        $hotel = Hotel::create([
            'owner_id' => $owner->id, 'name' => 'Grand Highway', 'address' => 'NH 8',
            'city' => 'Vadodara', 'state' => 'Gujarat', 'latitude' => 22.3072, 'longitude' => 73.1812,
            'price_per_night' => 100, 'total_rooms' => 5, 'available_rooms' => 5, 'status' => 'approved'
        ]);

        $victimUser = User::create([
            'name' => 'Victim User', 'email' => 'victim@example.com',
            'phone' => '9000000002', 'role' => 'user', 'password' => Hash::make('sec'), 'is_verified' => true
        ]);
        $attackerUser = User::create([
            'name' => 'Attacker User', 'email' => 'attacker@example.com',
            'phone' => '9000000003', 'role' => 'user', 'password' => Hash::make('sec'), 'is_verified' => true
        ]);

        $victimBooking = Booking::create([
            'user_id'             => $victimUser->id,
            'hotel_id'            => $hotel->id,
            'booking_date'        => now()->addDays(2)->toDateString(),
            'check_in'            => now()->addDays(2)->toDateString(),
            'check_out'           => now()->addDays(3)->toDateString(),
            'total_nights'        => 1,
            'truck_type'          => '12 Wheel',
            'truck_no'            => 'GJ16BC1111',
            'logistics_name'      => 'Victim Freight',
            'logistics_number'    => '9000000002',
            'payment_method'      => 'Pay at Hotel',
            'temp_transaction_id' => 'TXN-1234',
            'price_per_night'     => 100,
            'total_amount'        => 100,
            'total_payable'       => 118,
            'status'              => 'confirmed',
            'payment_status'      => 'pay_at_hotel',
        ]);

        // Attacker attempts to cancel Victim's booking
        Sanctum::actingAs($attackerUser, ['*']);
        $cancelRes = $this->postJson("/api/bookings/{$victimBooking->id}/cancel", [
            'reason' => 'Malicious cancellation',
        ]);

        // Expect 404 (IDOR blocked by scoping where('user_id', $user->id)->firstOrFail())
        $cancelRes->assertStatus(404);

        $victimBooking->refresh();
        $this->assertEquals('confirmed', $victimBooking->status);
    }

    /**
     * 5. Security Test: IDOR Protection prevents Owner A from deleting or modifying Owner B's hotel.
     */
    public function test_owner_cannot_modify_or_delete_another_owners_hotel(): void
    {
        $owner1 = User::create([
            'name' => 'Owner 1', 'email' => 'o1@test.com', 'phone' => '9111111111',
            'role' => 'owner', 'password' => Hash::make('pass'), 'is_verified' => true
        ]);
        $owner2 = User::create([
            'name' => 'Owner 2', 'email' => 'o2@test.com', 'phone' => '9222222222',
            'role' => 'owner', 'password' => Hash::make('pass'), 'is_verified' => true
        ]);

        $hotel1 = Hotel::create([
            'owner_id' => $owner1->id, 'name' => 'Hotel 1', 'address' => 'NH 48',
            'city' => 'Bharuch', 'state' => 'Gujarat', 'latitude' => 21.7051, 'longitude' => 72.9959,
            'price_per_night' => 200, 'total_rooms' => 10, 'available_rooms' => 10, 'status' => 'approved'
        ]);

        // Owner 2 tries to update Owner 1's hotel
        Sanctum::actingAs($owner2, ['*']);
        $updateRes = $this->putJson("/api/owner/hotels/{$hotel1->id}", [
            'name' => 'Hacked Hotel Name',
        ]);
        $updateRes->assertStatus(404);

        // Owner 2 tries to delete Owner 1's hotel
        $deleteRes = $this->deleteJson("/api/owner/hotels/{$hotel1->id}");
        $deleteRes->assertStatus(404);

        $hotel1->refresh();
        $this->assertEquals('Hotel 1', $hotel1->name);
    }

    /**
     * 6. Security Test: Unauthenticated requests to protected endpoints return 401.
     */
    public function test_unauthenticated_requests_are_rejected_with_401(): void
    {
        $this->getJson('/api/user/dashboard')->assertStatus(401);
        $this->getJson('/api/owner/hotels')->assertStatus(401);
        $this->getJson('/api/admin/dashboard')->assertStatus(401);
        $this->getJson('/api/bookings/my')->assertStatus(401);
    }
}
