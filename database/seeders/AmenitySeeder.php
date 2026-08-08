<?php

namespace Database\Seeders;

use App\Models\Amenity;
use Illuminate\Database\Seeder;

class AmenitySeeder extends Seeder
{
    public function run(): void
    {
        $amenities = [
            1  => "Free WiFi",
            2  => "Air Conditioning",
            3  => "Room Service",
            4  => "Swimming Pool",
            5  => "Free Parking",
            6  => "Wifi",
            7  => "Rest Rooms",
            8  => "Fuel Stations",
            9  => "Dining Facilities",
            10 => "Comfortable Rooms",
            11 => "ATM",
            12 => "Convenience Stores",
            13 => "First Aid",
            14 => "Fitness center",
            15 => "Food Outlets",
            16 => "Showers",
            17 => "Laundry Services",
            18 => "Seating Areas",
            19 => "Men",
            20 => "Women",
        ];

        foreach ($amenities as $id => $name) {
            Amenity::updateOrCreate(
                ['id' => $id],
                ['name' => $name]
            );
        }
    }
}
