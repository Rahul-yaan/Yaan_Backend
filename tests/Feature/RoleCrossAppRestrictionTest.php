<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleCrossAppRestrictionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test registering in Customer App, then attempting to register in Hotel Owner App with same phone.
     */
    public function test_customer_phone_cannot_be_registered_in_owner_app(): void
    {
        // 1. Register as Customer (User App)
        $registerCustomerResponse = $this->postJson('/api/register', [
            'name'     => 'Customer Test User',
            'email'    => 'customer@example.com',
            'phone'    => '8780215229',
            'role'     => 'user',
            'password' => 'password123',
        ]);

        $registerCustomerResponse->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'phone' => '8780215229',
            'role'  => 'user',
        ]);

        // 2. Attempt to register in Hotel Owner App with same phone number
        $registerOwnerResponse = $this->postJson('/api/register', [
            'name'     => 'Owner Impostor',
            'email'    => 'owner_impostor@example.com',
            'phone'    => '8780215229',
            'role'     => 'owner',
            'password' => 'password123',
        ]);

        $registerOwnerResponse->assertStatus(422)
            ->assertJson([
                'error'   => 'Role restriction error.',
                'message' => 'This mobile number is already registered as a Customer account. It cannot be used to register a Hotel Owner account.',
            ]);
    }

    /**
     * Test registering in Customer App with raw phone, then attempting to register with +91 prefix in Owner App.
     */
    public function test_formatted_phone_cannot_bypass_role_restriction(): void
    {
        // 1. Register as Customer
        $this->postJson('/api/register', [
            'name'     => 'Customer Format Test',
            'email'    => 'customer_fmt@example.com',
            'phone'    => '8780215229',
            'role'     => 'user',
            'password' => 'password123',
        ]);

        // 2. Attempt to register with +91 prefix in Owner App
        $response = $this->postJson('/api/register', [
            'name'     => 'Owner Format Test',
            'email'    => 'owner_fmt@example.com',
            'phone'    => '+918780215229',
            'role'     => 'owner',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Role restriction error.',
            ]);
    }

    /**
     * Test Customer account attempting to login in Hotel Owner App.
     */
    public function test_customer_account_cannot_login_in_owner_app(): void
    {
        // Create verified customer user
        $user = User::create([
            'name'        => 'Customer One',
            'email'       => 'customer1@example.com',
            'phone'       => '8780215229',
            'role'        => 'user',
            'password'    => Hash::make('password123'),
            'is_verified' => true,
        ]);

        // Attempt login in Hotel Owner App
        $response = $this->postJson('/api/login', [
            'phone'    => '8780215229',
            'password' => 'password123',
            'role'     => 'owner',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error'   => 'Role restriction error.',
                'message' => 'This mobile number is registered as a Customer account. Please log in using the Customer App.',
            ]);
    }

    /**
     * Test registering in Hotel Owner App, then attempting to register in Customer App with same phone.
     */
    public function test_owner_phone_cannot_be_registered_in_customer_app(): void
    {
        // 1. Register as Hotel Owner
        $registerOwnerResponse = $this->postJson('/api/register', [
            'name'     => 'Hotel Owner Test',
            'email'    => 'hotelowner@example.com',
            'phone'    => '9876543210',
            'role'     => 'owner',
            'password' => 'password123',
        ]);

        $registerOwnerResponse->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'phone' => '9876543210',
            'role'  => 'owner',
        ]);

        // 2. Attempt to register in Customer App with same phone
        $registerCustomerResponse = $this->postJson('/api/register', [
            'name'     => 'Customer Impostor',
            'email'    => 'customer_impostor@example.com',
            'phone'    => '9876543210',
            'role'     => 'user',
            'password' => 'password123',
        ]);

        $registerCustomerResponse->assertStatus(422)
            ->assertJson([
                'error'   => 'Role restriction error.',
                'message' => 'This mobile number is already registered as a Hotel Owner account. It cannot be used to register a Customer account.',
            ]);
    }

    /**
     * Test Hotel Owner account attempting to login in Customer App.
     */
    public function test_owner_account_cannot_login_in_customer_app(): void
    {
        // Create verified owner user
        $user = User::create([
            'name'        => 'Owner One',
            'email'       => 'owner1@example.com',
            'phone'       => '9876543210',
            'role'        => 'owner',
            'password'    => Hash::make('password123'),
            'is_verified' => true,
        ]);

        // Attempt login in Customer App
        $response = $this->postJson('/api/login', [
            'phone'    => '9876543210',
            'password' => 'password123',
            'role'     => 'user',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error'   => 'Role restriction error.',
                'message' => 'This mobile number is registered as a Hotel Owner account. Please log in using the Hotel Owner App.',
            ]);
    }
}
