<?php

namespace Tests\Feature;

use App\Models\HotelImage;
use App\Models\KycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\YaanFixtures;
use Tests\TestCase;

/**
 * B2: hotel photos must be real JPG/PNG/WEBP images (no HTML/SVG pages on /storage).
 */
class SafeHotelPhotoUploadTest extends TestCase
{
    use RefreshDatabase, YaanFixtures;

    // 1x1 PNG image
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createYaanFixtures();
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_owner_real_photo_is_saved_with_database_copy(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $this->post("/api/owner/hotels/{$this->hotel->id}/images", [
            'images' => [UploadedFile::fake()->createWithContent('room.png', base64_decode(self::PNG))],
        ], ['Accept' => 'application/json'])->assertOk();

        $img = HotelImage::where('hotel_id', $this->hotel->id)->first();
        $this->assertNotNull($img);
        $this->assertStringStartsWith('hotels/', $img->getRawOriginal('image_path'));
        $this->assertStringStartsWith('data:image/png;base64,', $img->image_data);
        Storage::disk('public')->assertExists($img->getRawOriginal('image_path'));
    }

    public function test_owner_html_disguised_as_photo_is_skipped(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $this->post("/api/owner/hotels/{$this->hotel->id}/images", [
            'images' => [
                UploadedFile::fake()->createWithContent('room.jpg', '<html><script>alert(1)</script></html>'),
                UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
            ],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertEquals(0, HotelImage::where('hotel_id', $this->hotel->id)->count());
        $this->assertEmpty(Storage::disk('public')->allFiles('hotels'));
    }

    public function test_owner_image_strings_only_data_images_and_https(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $this->putJson("/api/owner/hotels/{$this->hotel->id}", [
            'images' => [
                'data:image/png;base64,' . self::PNG,                                   // saved
                'https://images.example.com/hotel.jpg',                                // kept as link
                'data:image/svg+xml;base64,' . base64_encode('<svg onload="alert(1)"/>'), // skipped
                'http://evil.example.com/x.jpg',                                       // skipped
                'javascript:alert(1)',                                                 // skipped
            ],
        ])->assertOk();

        $paths = HotelImage::where('hotel_id', $this->hotel->id)->get()->map(fn ($i) => $i->getRawOriginal('image_path'))->all();
        $this->assertCount(2, $paths);
        $this->assertContains('https://images.example.com/hotel.jpg', $paths);
        $this->assertTrue(collect($paths)->contains(fn ($p) => str_starts_with($p, 'hotels/')));
    }

    public function test_admin_upload_accepts_real_photo_and_rejects_html(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->post("/api/admin/hotels/{$this->hotel->id}/images", [
            'image' => UploadedFile::fake()->createWithContent('evil.png', '<html><script>alert(1)</script></html>'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->postJson("/api/admin/hotels/{$this->hotel->id}/images", [
            'image_url' => 'javascript:alert(1)',
        ])->assertStatus(422);

        $this->post("/api/admin/hotels/{$this->hotel->id}/images", [
            'image' => UploadedFile::fake()->createWithContent('room.png', base64_decode(self::PNG)),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertEquals(1, HotelImage::where('hotel_id', $this->hotel->id)->count());
    }

    public function test_kyc_sent_with_hotel_form_is_private_and_copied_to_database(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $this->post('/api/owner/hotels', [
            'name' => 'Highway Dhaba', 'city' => 'Surat', 'address' => 'NH 48 KM 50',
            'latitude' => 21.17, 'longitude' => 72.83, 'price_per_night' => 42.37, 'total_rooms' => 5,
            'pan_card' => UploadedFile::fake()->createWithContent('pan.png', base64_decode(self::PNG)),
            'gst_image' => UploadedFile::fake()->createWithContent('gst.png', '<html>not an image</html>'),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $this->assertEmpty(Storage::disk('public')->allFiles('kyc_docs'), 'KYC must never be on the public disk');
        $this->assertNotEmpty(Storage::disk('local')->allFiles('kyc_docs'));
        $this->assertEquals(1, KycDocument::where('user_id', $this->owner->id)->where('field', 'pan_card')->count());
        $this->assertEquals(0, KycDocument::where('user_id', $this->owner->id)->where('field', 'gst_image')->count());
    }
}
