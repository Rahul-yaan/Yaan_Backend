<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Hotel;
use App\Models\OwnerProfile;
use App\Models\Booking;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\AmenitySeeder;

class CleanTestData extends Command
{
    protected $signature   = 'db:clean-test-data';
    protected $description = 'Safely wipe all test hotels, owner profiles, non-admin users, bookings, transactions, and reviews while retaining Super Admin and default amenities.';

    public function handle(): int
    {
        // 1. Refuse execution in production environment
        if (app()->environment('production')) {
            $this->error('Operation aborted: db:clean-test-data is strictly prohibited in production environments.');
            return 1;
        }

        $this->info('Starting database cleanup for fresh end-to-end testing...');

        try {
            // 2. Delete Bookings and related status overrides
            try {
                DB::table('transaction_status_overrides')->delete();
            } catch (\Throwable $e) {}

            $deletedBookings = Booking::query()->delete();
            $this->info("Cleared {$deletedBookings} booking(s).");

            // 3. Delete Reviews if table exists
            try {
                $deletedReviews = DB::table('reviews')->delete();
                $this->info("Cleared {$deletedReviews} review(s).");
            } catch (\Throwable $e) {}

            // 4. Delete Hotel relationships (Images & Amenities pivot)
            try {
                DB::table('hotel_images')->delete();
            } catch (\Throwable $e) {}

            try {
                DB::table('hotel_amenity')->delete();
            } catch (\Throwable $e) {}

            // 5. Delete Hotels
            $deletedHotels = Hotel::query()->delete();
            $this->info("Cleared {$deletedHotels} hotel(s).");

            // 6. Delete Owner Profiles
            $deletedOwners = OwnerProfile::query()->delete();
            $this->info("Cleared {$deletedOwners} owner profile(s).");

            // 7. Delete non-admin Users (Keep role=admin)
            $deletedUsers = User::where(function($q) {
                $q->whereNull('role')
                  ->orWhere('role', '!=', 'admin');
            })->delete();

            $this->info("Cleared {$deletedUsers} non-admin user(s).");

            // 8. Re-run Admin seeder (respects environment without hardcoding backdoor)
            $adminSeeder = new AdminUserSeeder();
            $adminSeeder->run();

            // 9. Re-seed default amenities if missing
            $amenitySeeder = new AmenitySeeder();
            $amenitySeeder->run();
            $this->info("Default amenities verified & seeded.");

            $this->info('Database cleanup complete! Fresh state ready for full testing.');
            return 0;
        } catch (\Throwable $e) {
            $this->error("Cleanup failed: " . $e->getMessage());
            return 1;
        }
    }
}
