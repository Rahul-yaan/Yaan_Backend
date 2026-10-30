<?php

namespace Tests\Feature\Concerns;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\OwnerProfile;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * Shared test data: one admin, one approved owner with a ₹42.37 hotel, one customer.
 */
trait YaanFixtures
{
    protected User $admin;
    protected User $owner;
    protected User $customer;
    protected Hotel $hotel;

    protected function createYaanFixtures(): void
    {
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@test.com', 'phone' => '9000000099',
            'role' => 'admin', 'password' => Hash::make('password123'), 'is_verified' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'owner@test.com', 'phone' => '9876543210',
            'role' => 'owner', 'password' => Hash::make('password123'), 'is_verified' => true,
            'firebase_uid' => 'fb_owner',
        ]);
        OwnerProfile::create(['user_id' => $this->owner->id, 'status' => 'approved', 'is_profile_complete' => true]);

        $this->hotel = Hotel::create([
            'owner_id' => $this->owner->id, 'name' => 'Highway Dhaba', 'address' => 'NH 48 KM 50',
            'city' => 'Surat', 'latitude' => 21.17, 'longitude' => 72.83,
            'price_per_night' => 42.37, 'total_rooms' => 5, 'available_rooms' => 5, 'status' => 'approved',
        ]);

        $this->customer = User::create([
            'name' => 'Driver', 'email' => 'driver@test.com', 'phone' => '9123456789',
            'role' => 'user', 'password' => Hash::make('password123'), 'is_verified' => true,
            'firebase_uid' => 'fb_driver',
        ]);
    }

    /** Customer books the ₹42.37 hotel for today with Pay at Hotel (through the real API). */
    protected function bookPayAtHotel(): Booking
    {
        Sanctum::actingAs($this->customer, ['*']);
        $res = $this->postJson('/api/bookings', [
            'hotel_id'       => $this->hotel->id,
            'booking_date'   => BusinessDate::today(),
            'truck_type'     => '10 Wheel',
            'truck_no'       => 'GJ16AB7888',
            'payment_method' => 'Pay at Hotel',
        ]);
        $res->assertStatus(201);

        return Booking::findOrFail($res->json('booking.id'));
    }

    /** A paid online booking for today (as if Razorpay payment was verified). */
    protected function paidOnlineBooking(): Booking
    {
        return Booking::create([
            'user_id' => $this->customer->id, 'hotel_id' => $this->hotel->id,
            'check_in' => BusinessDate::today(), 'check_out' => BusinessDate::nextDay(), 'total_nights' => 1,
            'booking_date' => BusinessDate::today(), 'status' => 'confirmed', 'payment_status' => 'paid',
            'payment_method' => 'online', 'price_per_night' => 42.37, 'total_amount' => 42.37,
            'gst_amount' => 7.63, 'total_payable' => 50.00, 'razorpay_payment_id' => 'pay_test123',
        ]);
    }
}
