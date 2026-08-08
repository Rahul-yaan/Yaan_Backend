<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

class ClearBookings extends Command
{
    protected $signature   = 'bookings:clear';
    protected $description = 'Clear all existing booking records for testing';

    public function handle(): void
    {
        $count = Booking::query()->delete();
        $this->info("Successfully cleared {$count} booking record(s).");
    }
}
