<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_payment_status_check;");
                DB::statement("ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_status_check;");
                DB::statement("ALTER TABLE bookings ALTER COLUMN payment_status TYPE VARCHAR(50);");
                DB::statement("ALTER TABLE bookings ALTER COLUMN status TYPE VARCHAR(50);");
                DB::statement("ALTER TABLE bookings ALTER COLUMN payment_status SET DEFAULT 'pending';");
                DB::statement("ALTER TABLE bookings ALTER COLUMN status SET DEFAULT 'pending';");
            }
        } catch (\Throwable $e) {
            // Ignore if already dropped
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
