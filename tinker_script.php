
\ = App\Models\Hotel::where('name', 'Royal Palace Hotel')->first();
if (\) {
    \ = ['Free WiFi', 'Air Conditioning', 'Room Service', 'Swimming Pool', 'Free Parking'];
    \ = [];
    foreach (\ as \) {
        \ = App\Models\Amenity::firstOrCreate(['name' => \]);
        \[] = \->id;
    }
    \->amenities()->syncWithoutDetaching(\);
    echo 'Successfully added amenities to ' . \->name . '\n';
} else {
    echo 'Hotel not found.\n';
}
