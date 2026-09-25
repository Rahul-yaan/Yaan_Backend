<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Hotel;
use App\Models\HotelImage;
use App\Models\OwnerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HotelImagePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_hotel_images_do_not_contain_kyc_docs()
    {
        $owner = User::create([
            'name'     => 'Hotel Owner',
            'email'    => 'owner@example.com',
            'phone'    => '9876543210',
            'role'     => 'owner',
            'password' => bcrypt('password123'),
        ]);

        $hotel = Hotel::create([
            'owner_id'        => $owner->id,
            'name'            => 'Test Hotel',
            'city'            => 'Vadodara',
            'address'         => 'Test Address',
            'latitude'        => 22.3072,
            'longitude'       => 73.1812,
            'price_per_night' => 1000,
            'total_rooms'     => 10,
            'available_rooms' => 10,
            'status'          => 'approved',
        ]);

        $hotelImage = HotelImage::create([
            'hotel_id'   => $hotel->id,
            'image_path' => 'hotels/test_hotel.jpg',
            'image_data' => 'data:image/jpeg;base64,' . base64_encode('fake image binary content'),
            'is_primary' => true,
        ]);

        $images = $hotel->images()->get();
        foreach ($images as $img) {
            $this->assertStringNotContainsString('kyc_docs/', $img->image_path, 'HotelImage should not point to kyc_docs/');
        }

        $primary = $hotel->primary_image;
        $this->assertNotNull($primary);
        $this->assertStringNotContainsString('kyc_docs/', $primary->image_path, 'Primary image should not be a KYC document');
        $this->assertNotEmpty($primary->url);
        $this->assertStringStartsWith('http', $primary->url);
    }

    public function test_hotel_image_url_preserves_external_urls()
    {
        $externalUrl = 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1000&q=80';
        $hotelImage = new HotelImage([
            'hotel_id'   => 1,
            'image_path' => $externalUrl,
            'is_primary' => true,
        ]);

        $this->assertEquals($externalUrl, $hotelImage->url);
    }
}
