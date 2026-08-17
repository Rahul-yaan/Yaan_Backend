<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            DB::statement("ALTER TABLE hotels ALTER COLUMN latitude SET DEFAULT 22.3072");
            DB::statement("ALTER TABLE hotels ALTER COLUMN longitude SET DEFAULT 73.1812");
            DB::statement("ALTER TABLE hotels ALTER COLUMN latitude DROP NOT NULL");
            DB::statement("ALTER TABLE hotels ALTER COLUMN longitude DROP NOT NULL");
        } catch (\Throwable $e) {
            // Silence if already altered on Neon PostgreSQL
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
