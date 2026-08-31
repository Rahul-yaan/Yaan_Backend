<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Hotel;
use App\Models\OwnerProfile;
use App\Models\Booking;
use Illuminate\Support\Facades\Hash;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\AmenitySeeder;

class CleanTestData extends Command
{
    protected $signature   = 'db:clean-test-data';
    protected $description = 'Safely wipe all test hotels, owner profiles, non-admin users, bookings, transactions, and reviews while retaining Super Admin and default amenities.';

    public function handle(): void
    {
        $this->info('Starting database cleanup for fresh end-to-end testing...');

        try {
            // 1. Delete Bookings and related status overrides
            try {
                DB::table('transaction_status_overrides')->delete();
            } catch (\Throwable $e) {}
            
            $deletedBookings = Booking::query()->delete();
            $this->info("Cleared {$deletedBookings} booking(s).");

            // 2. Delete Reviews if table exists
            try {
                $deletedReviews = DB::table('reviews')->delete();
                $this->info("Cleared {$deletedReviews} review(s).");
            } catch (\Throwable $e) {}

            // 3. Delete Hotel relationships (Images & Amenities pivot)
            try {
                DB::table('hotel_images')->delete();
            } catch (\Throwable $e) {}

            try {
                DB::table('hotel_amenity')->delete();
            } catch (\Throwable $e) {}

            // 4. Delete Hotels
            $deletedHotels = Hotel::query()->delete();
            $this->info("Cleared {$deletedHotels} hotel(s).");

            // 5. Delete Owner Profiles
            $deletedOwners = OwnerProfile::query()->delete();
            $this->info("Cleared {$deletedOwners} owner profile(s).");

            // 6. Delete non-admin Users (Keep role=admin or email=admin@yaan.com)
            $deletedUsers = User::where(function($q) {
                $q->whereNull('role')
                  ->orWhere('role', '!=', 'admin');
            })->where('email', '!=', 'admin@yaan.com')->delete();
            
            $this->info("Cleared {$deletedUsers} non-admin user(s).");

            // 7. Ensure Super Admin User exists with correct credentials
            User::updateOrCreate(
                ['email' => 'admin@yaan.com'],
                [
                    'name'         => 'Super Admin',
                    'phone'        => '9999999999',
                    'password'     => Hash::make('admin123456'),
                    'role'         => 'admin',
                    'is_verified'  => true,
                    'firebase_uid' => 'admin_bypass_uid',
                ]
            );
            $this->info("Super Admin account (admin@yaan.com / admin123456) verified & ready.");

            // 8. Re-seed default amenities if missing
            $amenitySeeder = new AmenitySeeder();
            $amenitySeeder->run();
            $this->info("Default amenities verified & seeded.");

            $this->info('Database cleanup complete! Fresh state ready for full testing.');
        } catch (\Throwable $e) {
            $this->error("Cleanup failed: " . $e->getMessage());
        }
    }
}
