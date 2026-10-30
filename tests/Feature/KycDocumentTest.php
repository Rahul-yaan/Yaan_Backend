<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\OwnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\YaanFixtures;
use Tests\TestCase;

/**
 * A8: KYC documents are kept in the database (Render deletes disk files on deploy)
 * and only the admin can view them. A5: owner sign-up needs Aadhaar and PAN numbers.
 */
class KycDocumentTest extends TestCase
{
    use RefreshDatabase, YaanFixtures;

    // 1x1 PNG image
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createYaanFixtures();
    }

    private function newOwner(): User
    {
        return User::create([
            'name' => 'New Owner', 'email' => 'new@test.com', 'phone' => '+919812345678',
            'role' => 'owner', 'password' => Hash::make('password123'), 'is_verified' => false,
            'firebase_uid' => 'fb_new',
        ]);
    }

    private function submitOwnerKyc(User $owner)
    {
        $png = fn ($name) => UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));

        Sanctum::actingAs($owner, ['*']);

        return $this->post('/api/owner/profile', [
            'hotel_name' => 'New Dhaba', 'owner_name' => 'Ramesh Patel',
            'address' => 'NH 48 Near Toll Plaza', 'city' => 'Bharuch', 'state' => 'Gujarat', 'pincode' => '392001',
            'aadhaar_number' => '234567890123', 'pan_number' => 'ABCDE1234F',
            'bank_name' => 'State Bank of India', 'account_number' => '123456789012', 'ifsc_code' => 'SBIN0001234',
            'aadhaar_front' => $png('front.png'), 'aadhaar_back' => $png('back.png'),
            'pan_card' => $png('pan.png'), 'business_proof' => $png('proof.png'),
        ], ['Accept' => 'application/json']);
    }

    public function test_kyc_upload_keeps_database_copy_and_admin_can_view_it(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $newOwner = $this->newOwner();

        $this->submitOwnerKyc($newOwner)->assertOk();

        $this->assertEquals(4, KycDocument::where('user_id', $newOwner->id)->count());
        $this->assertEquals('image/png', KycDocument::where('user_id', $newOwner->id)->value('mime'));

        // Simulate Render deleting disk files on deploy
        Storage::fake('local');

        Sanctum::actingAs($this->admin, ['*']);
        $res = $this->get("/api/admin/owners/{$newOwner->id}/documents/aadhaar_front");
        $res->assertOk();
        $this->assertStringStartsWith('image/png', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertEquals(base64_decode(self::PNG), $res->getContent());

        // Not uploaded -> 404, unknown field -> 404
        $this->getJson("/api/admin/owners/{$newOwner->id}/documents/gst_image")->assertNotFound();
        $this->getJson("/api/admin/owners/{$newOwner->id}/documents/password")->assertNotFound();
    }

    public function test_old_base64_document_in_profile_is_still_shown(): void
    {
        OwnerProfile::where('user_id', $this->owner->id)->update([
            'pan_card' => 'data:image/png;base64,' . self::PNG,
        ]);

        Sanctum::actingAs($this->admin, ['*']);
        $res = $this->get("/api/admin/owners/{$this->owner->id}/documents/pan_card")->assertOk();
        $this->assertEquals(base64_decode(self::PNG), $res->getContent());
    }

    public function test_disguised_html_document_is_never_served(): void
    {
        // Claims to be an image, but is a web page with a script
        KycDocument::storeCopy($this->owner->id, 'pan_card', '<html><script>alert(1)</script></html>', 'image/png');

        Sanctum::actingAs($this->admin, ['*']);
        $this->getJson("/api/admin/owners/{$this->owner->id}/documents/pan_card")->assertStatus(415);
    }

    public function test_only_admin_can_view_kyc_documents(): void
    {
        KycDocument::storeCopy($this->owner->id, 'pan_card', base64_decode(self::PNG), 'image/png');

        Sanctum::actingAs($this->admin, ['*']);
        $this->get("/api/admin/owners/{$this->owner->id}/documents/pan_card")->assertOk();

        Sanctum::actingAs($this->owner, ['*']);
        $this->getJson("/api/admin/owners/{$this->owner->id}/documents/pan_card")->assertForbidden();

        Sanctum::actingAs($this->customer, ['*']);
        $this->getJson("/api/admin/owners/{$this->owner->id}/documents/pan_card")->assertForbidden();
    }

    public function test_reset_kyc_removes_database_copies(): void
    {
        KycDocument::storeCopy($this->owner->id, 'pan_card', base64_decode(self::PNG), 'image/png');

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson("/api/admin/owners/{$this->owner->id}/reset-kyc")->assertOk();

        $this->assertEquals(0, KycDocument::where('user_id', $this->owner->id)->count());
        $this->getJson("/api/admin/owners/{$this->owner->id}/documents/pan_card")->assertNotFound();
    }

    public function test_owner_profile_requires_aadhaar_and_pan_numbers(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $res = $this->postJson('/api/owner/profile', [
            'hotel_name' => 'New Dhaba', 'owner_name' => 'Ramesh', 'address' => 'NH 48 Near Toll',
            'city' => 'Bharuch', 'state' => 'Gujarat', 'pincode' => '392001',
        ])->assertStatus(422);

        $this->assertArrayHasKey('aadhaar_number', $res->json('errors'));
        $this->assertEquals(['Aadhaar card number is required.'], $res->json('errors.aadhaar_number'));
        $this->assertArrayHasKey('pan_number', $res->json('errors'));
    }

    public function test_owner_signup_with_aadhaar_and_pan_succeeds(): void
    {
        Storage::fake('local');
        $newOwner = $this->newOwner();

        $this->submitOwnerKyc($newOwner)->assertOk()->assertJson(['success' => true]);

        $profile = OwnerProfile::where('user_id', $newOwner->id)->first();
        $this->assertEquals('234567890123', $profile->aadhaar_number);
        $this->assertEquals('ABCDE1234F', $profile->pan_number);
        $this->assertEquals('pending', $profile->status);
    }
}
