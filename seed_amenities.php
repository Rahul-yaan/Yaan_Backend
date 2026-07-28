<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$amenityIds = [
    1 => "Free WiFi", 2 => "Air Conditioning", 3 => "Room Service", 4 => "Swimming Pool", 5 => "Free Parking",
    6 => "Wifi", 7 => "Rest Rooms", 8 => "Fuel Stations", 9 => "Dining Facilities", 10 => "Comfortable Rooms",
    11 => "ATM", 12 => "Convenience Stores", 13 => "First Aid", 14 => "Fitness center", 15 => "Food Outlets",
    16 => "Showers", 17 => "Laundry Services", 18 => "Seating Areas", 19 => "Men", 20 => "Women"
];

foreach ($amenityIds as $id => $name) {
    App\Models\Amenity::updateOrCreate(
        ['id' => $id],
        ['name' => $name]
    );
}

echo "Seeded 20 amenities successfully.\n";
