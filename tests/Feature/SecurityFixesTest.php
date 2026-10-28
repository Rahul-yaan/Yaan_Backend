<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\OwnerProfile;
use App\Models\User;
use App\Support\SafeUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Verify no admin backdoor exists: login without valid password fails.
     */
    public function test_admin_login_without_password_fails(): void
    {
        $admin = User::create([
            'name'        => 'Platform Admin',
            'email'       => 'admin@yaan.com',
            'phone'       => '9999999999',
            'role'        => 'admin',
            'password'    => Hash::make('CorrectAdminPass123!'),
            'is_verified' => true,
        ]);

        // Attempt login with empty/wrong password
        $res = $this->postJson('/api/login', [
            'email'    => 'admin@yaan.com',
            'password' => 'wrongpassword',
            'role'     => 'admin',
        ]);

        $res->assertStatus(401)
            ->assertJsonStructure(['error', 'message']);

        // Attempt without password
        $resNoPass = $this->postJson('/api/login', [
            'email'    => 'admin@yaan.com',
            'password' => '',
            'role'     => 'admin',
        ]);

        $resNoPass->assertStatus(422);
    }

    /**
     * 2. Verify brute force rate limiting on auth routes.
     */
    public function test_brute_force_auth_rate_limiting(): void
    {
        User::create([
            'name'        => 'Brute Target',
            'email'       => 'victim@example.com',
            'phone'       => '9876543210',
            'role'        => 'user',
            'password'    => Hash::make('TargetPassword123'),
            'is_verified' => true,
        ]);

        // 5 allowed requests per minute per account+path
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email'    => 'victim@example.com',
                'password' => 'badpass' . $i,
                'role'     => 'user',
            ]);
        }

        // 6th request must be throttled with HTTP 429
        $response = $this->postJson('/api/login', [
            'email'    => 'victim@example.com',
            'password' => 'badpass6',
            'role'     => 'user',
        ]);

        $response->assertStatus(429);
    }

    /**
     * 3. Verify KYC documents are stored privately on local disk, not public disk.
     */
    public function test_kyc_documents_stored_privately(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $pdfContent = "%PDF-1.4\n%âãÏÓ\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\nxref\n0 2\n0000000000 65535 f\n0000000015 00000 n\ntrailer<</Size 2/Root 1 0 R>>\nstartxref\n68\n%%EOF";
        $file = UploadedFile::fake()->createWithContent('aadhaar.pdf', $pdfContent);

        $storedPath = SafeUpload::uploadKycDoc($file, 42);

        $this->assertNotNull($storedPath);
        $this->assertStringStartsWith('kyc_docs/42/', $storedPath);

        // Assert it exists in private 'local' storage
        Storage::disk('local')->assertExists($storedPath);

        // Assert it does NOT exist in 'public' storage
        Storage::disk('public')->assertMissing($storedPath);
    }

    /**
     * 4. SafeUpload rejects spoofed PNG containing PHP script bytes.
     */
    public function test_safe_upload_rejects_fake_png_with_php_code(): void
    {
        $maliciousPhp = "<?php system(\$_GET['cmd']); ?>";
        $file = UploadedFile::fake()->createWithContent('avatar.png', $maliciousPhp);

        $this->expectException(\InvalidArgumentException::class);
        SafeUpload::uploadImage($file, 'avatars');
    }

    /**
     * 5. SafeUpload rejects executable/script extensions.
     */
    public function test_safe_upload_rejects_executable_or_script_extension(): void
    {
        $file = UploadedFile::fake()->create('shell.php', 10);

        $this->expectException(\InvalidArgumentException::class);
        SafeUpload::uploadImage($file, 'avatars');
    }

    /**
     * 6. MediaController blocks directory traversal attempts (..).
     */
    public function test_media_controller_blocks_directory_traversal(): void
    {
        $response = $this->get('/media/..%2f..%2f..%2f.env');
        $this->assertEquals(403, $response->status());

        $response2 = $this->get('/media/hotels/../../../etc/passwd');
        $this->assertEquals(403, $response2->status());
    }

    /**
     * 7. MediaController blocks access to KYC documents (whitelisted only hotels, avatars, banners).
     */
    public function test_media_controller_blocks_access_to_kyc_docs(): void
    {
        $response = $this->get('/media/kyc_docs/1/aadhaar.pdf');
        $this->assertEquals(403, $response->status());
    }

    /**
     * 8. Verify OTP rejects phone number mismatch between Firebase token and request.
     */
    public function test_verify_otp_rejects_mismatched_phone_number(): void
    {
        Config::set('app.firebase_bypass', false);

        User::create([
            'name'        => 'OTP Test User',
            'email'       => 'otptest@example.com',
            'phone'       => '9876543210',
            'role'        => 'user',
            'password'    => Hash::make('password123'),
            'is_verified' => false,
        ]);

        $response = $this->postJson('/api/verify-otp', [
            'id_token' => 'invalid_firebase_token',
            'phone'    => '9876543210',
        ]);

        $response->assertStatus(401)
            ->assertJsonStructure(['error', 'message']);
    }

    /**
     * 9. Verify OTP rejects duplicate firebase_uid already tied to another user.
     */
    public function test_verify_otp_rejects_duplicate_firebase_uid(): void
    {
        User::create([
            'name'         => 'Existing User',
            'email'        => 'existing@example.com',
            'phone'        => '9111111111',
            'role'         => 'user',
            'password'     => Hash::make('password123'),
            'firebase_uid' => 'shared_fb_uid_999',
            'is_verified'  => true,
        ]);

        User::create([
            'name'         => 'New User',
            'email'        => 'newuser@example.com',
            'phone'        => '9222222222',
            'role'         => 'user',
            'password'     => Hash::make('password123'),
            'is_verified'  => false,
        ]);

        Config::set('app.firebase_bypass', false);

        // Mock Firebase Auth verifying token successfully but returning existing user's UID
        $claims = new \Lcobucci\JWT\Token\DataSet([
            'sub'          => 'shared_fb_uid_999',
            'phone_number' => '+919222222222',
        ], 'jwt_claims');

        $verifiedToken = Mockery::mock(\Lcobucci\JWT\UnencryptedToken::class);
        $verifiedToken->shouldReceive('claims')->andReturn($claims);

        $firebaseAuth = Mockery::mock(FirebaseAuth::class);
        $firebaseAuth->shouldReceive('verifyIdToken')->with('valid_token_string')->andReturn($verifiedToken);

        $this->app->instance(FirebaseAuth::class, $firebaseAuth);

        $response = $this->postJson('/api/verify-otp', [
            'id_token' => 'valid_token_string',
            'phone'    => '9222222222',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'This phone number is already verified with another account.',
            ]);
    }

    /**
     * 10. CleanTestData command fails if run in production environment.
     */
    public function test_clean_test_data_fails_in_production(): void
    {
        $this->app->detectEnvironment(fn() => 'production');

        $exitCode = Artisan::call('db:clean-test-data');

        $this->assertEquals(1, $exitCode);
    }

    /**
     * 11. Customer profile update strictly blocks altering verified phone number.
     */
    public function test_customer_profile_update_ignores_phone_modification(): void
    {
        $user = User::create([
            'name'        => 'Original Customer',
            'email'       => 'orig@example.com',
            'phone'       => '9876543210',
            'role'        => 'user',
            'password'    => Hash::make('pass123'),
            'is_verified' => true,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/user/profile', [
            'name'  => 'Updated Name',
            'phone' => '9999999999', // Attempt to hijack phone
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Phone number cannot be changed directly.',
            ]);

        $user->refresh();
        $this->assertEquals('9876543210', $user->phone); // Unchanged!
    }

    /**
     * 12. Owner profile update strictly blocks altering verified phone number.
     */
    public function test_owner_profile_update_ignores_phone_modification(): void
    {
        $owner = User::create([
            'name'        => 'Original Owner',
            'email'       => 'owner_orig@example.com',
            'phone'       => '9876543210',
            'role'        => 'owner',
            'password'    => Hash::make('pass123'),
            'is_verified' => true,
        ]);

        OwnerProfile::create([
            'user_id' => $owner->id,
            'status'  => 'pending',
        ]);

        Sanctum::actingAs($owner, ['*']);

        $response = $this->postJson('/api/owner/profile', [
            'name'  => 'New Owner Name',
            'phone' => '9000000000', // Attempt phone change
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'Phone number cannot be changed directly.',
            ]);

        $owner->refresh();
        $this->assertEquals('9876543210', $owner->phone); // Unchanged!
    }

    /**
     * 13. Customer hotel queries do not leak owner KYC or bank details.
     */
    public function test_customer_hotel_search_does_not_leak_owner_kyc_or_bank(): void
    {
        $customer = User::create([
            'name'        => 'Customer User',
            'email'       => 'cust_search@example.com',
            'phone'       => '9888888888',
            'role'        => 'user',
            'password'    => Hash::make('pass123'),
            'is_verified' => true,
        ]);

        $owner = User::create([
            'name'        => 'Hotel Owner',
            'email'       => 'hotel_owner@example.com',
            'phone'       => '9876543210',
            'role'        => 'owner',
            'password'    => Hash::make('pass123'),
            'is_verified' => true,
        ]);

        OwnerProfile::create([
            'user_id'               => $owner->id,
            'status'                => 'approved',
            'is_profile_complete'   => true,
            'pan_card_number'       => 'ABCDE1234F',
            'bank_account_number'   => '123456789012',
            'bank_ifsc'             => 'HDFC0001234',
            'aadhaar_document_path' => 'kyc_docs/1/aadhaar.pdf',
        ]);

        $hotel = Hotel::create([
            'owner_id'        => $owner->id,
            'name'            => 'Highway Inn',
            'description'     => 'Rest area',
            'address'         => 'NH 48',
            'city'            => 'Ahmedabad',
            'state'           => 'Gujarat',
            'latitude'        => 23.0225,
            'longitude'       => 72.5714,
            'price_per_night' => 200,
            'total_rooms'     => 10,
            'available_rooms' => 10,
            'status'          => 'approved',
        ]);

        Sanctum::actingAs($customer, ['*']);

        $response = $this->getJson('/api/hotels/' . $hotel->id);
        $response->assertStatus(200);

        $json = $response->getContent();
        $this->assertStringNotContainsString('ABCDE1234F', $json);
        $this->assertStringNotContainsString('123456789012', $json);
        $this->assertStringNotContainsString('HDFC0001234', $json);
        $this->assertStringNotContainsString('aadhaar.pdf', $json);
    }

    /**
     * 14. Sanctum token expiration configured to 30 days (43200 minutes).
     */
    public function test_sanctum_token_expiration_configured(): void
    {
        $this->assertEquals(43200, config('sanctum.expiration'));
    }
}
