<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$bookings = App\Models\Booking::all();
foreach ($bookings as $b) {
    echo "ID: {$b->id}, User: {$b->user_id}, Hotel: {$b->hotel_id}, Date: {$b->booking_date}\n";
}
