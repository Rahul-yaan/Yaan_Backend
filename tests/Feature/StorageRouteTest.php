<?php

namespace Tests\Feature;

use App\Models\HotelImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\YaanFixtures;
use Tests\TestCase;

/**
 * B3: /storage serves only public images (hotels, avatars, banners). KYC files are never
 * downloadable without login, and restoring from the database uses an exact path match.
 */
class StorageRouteTest extends TestCase
{
    use RefreshDatabase, YaanFixtures;

    // 1x1 PNG image
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createYaanFixtures();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** Write a real file into storage/app/<dir>/ and remember it for cleanup. */
    private function putFile(string $relative, string $bytes): void
    {
        $full = storage_path('app/' . $relative);
        @mkdir(dirname($full), 0775, true);
        file_put_contents($full, $bytes);
        $this->created[] = $full;
    }

    public function test_hotel_avatar_and_banner_images_still_load(): void
    {
        foreach (['hotels', 'avatars', 'banners'] as $dir) {
            $name = $dir . '/test_' . Str::random(12) . '.png';
            $this->putFile('public/' . $name, base64_decode(self::PNG));

            $res = $this->get('/storage/' . $name)->assertOk();
            $this->assertStringStartsWith('image/png', $res->headers->get('Content-Type'));
        }
    }

    public function test_kyc_documents_are_never_served_without_login(): void
    {
        $name = 'test_' . Str::random(12) . '.png';
        $this->putFile('public/kyc_docs/' . $name, base64_decode(self::PNG));
        $this->putFile('private/kyc_docs/' . $name, base64_decode(self::PNG));

        $this->get('/storage/kyc_docs/' . $name)->assertForbidden();
        $this->get('/storage/private/kyc_docs/' . $name)->assertForbidden();
        $this->get('/storage/../private/kyc_docs/' . $name)->assertStatus(403);
    }

    public function test_non_image_in_public_folder_is_not_served(): void
    {
        $name = 'hotels/test_' . Str::random(12) . '.png';
        $this->putFile('public/' . $name, '<html><script>alert(1)</script></html>');

        $this->get('/storage/' . $name)->assertForbidden();
    }

    public function test_missing_hotel_photo_is_restored_from_database_by_exact_path(): void
    {
        $name = 'hotels/test_' . Str::random(12) . '.png';
        HotelImage::create([
            'hotel_id' => $this->hotel->id, 'image_path' => $name,
            'image_data' => 'data:image/png;base64,' . self::PNG, 'is_primary' => true,
        ]);
        $this->created[] = storage_path('app/public/' . $name);

        $res = $this->get('/storage/' . $name)->assertOk();
        $this->assertEquals(base64_decode(self::PNG), file_get_contents($res->baseResponse->getFile()->getPathname()));
    }

    public function test_photo_with_same_file_name_in_another_folder_is_not_matched(): void
    {
        $base = 'test_' . Str::random(12) . '.png';
        HotelImage::create([
            'hotel_id' => $this->hotel->id, 'image_path' => 'hotels/' . $base,
            'image_data' => 'data:image/png;base64,' . self::PNG, 'is_primary' => true,
        ]);

        // The old LIKE '%name' lookup returned the hotel photo for any folder
        $this->get('/storage/avatars/' . $base)->assertNotFound();
    }
}
