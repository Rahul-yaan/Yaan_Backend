<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$hotel = App\Models\Hotel::where('name', 'Royal Palace Hotel')->first();
if ($hotel) {
    $amenities = ['Free WiFi', 'Air Conditioning', 'Room Service', 'Swimming Pool', 'Free Parking'];
    $amenityIds = [];
    foreach ($amenities as $amenityName) {
        $amenity = App\Models\Amenity::firstOrCreate(['name' => $amenityName]);
        $amenityIds[] = $amenity->id;
    }
    $hotel->amenities()->syncWithoutDetaching($amenityIds);
    echo "Successfully added amenities to " . $hotel->name . "\n";
} else {
    echo "Hotel not found.\n";
}
