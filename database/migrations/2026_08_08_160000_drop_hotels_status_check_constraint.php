<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE hotels DROP CONSTRAINT IF EXISTS hotels_status_check;");
                DB::statement("ALTER TABLE hotels ALTER COLUMN status TYPE VARCHAR(50);");
                DB::statement("ALTER TABLE hotels ALTER COLUMN status SET DEFAULT 'pending';");
                DB::statement("DROP TYPE IF EXISTS hotels_status_enum CASCADE;");
            }
        } catch (\Throwable $e) {
            // Ignore if already dropped
        }
    }

    public function down(): void
    {
    }
};
