<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Only alters columns if on MySQL/SQLite, does nothing on PostgreSQL.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            return;
        }

        // On MySQL or SQLite test database, ensure columns can accommodate extra values
        try {
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->string('role', 50)->default('user')->change();
                });
            }
        } catch (\Throwable $e) {}

        try {
            if (Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'payment_status')) {
                Schema::table('bookings', function (Blueprint $table) {
                    $table->string('payment_status', 50)->default('pending')->change();
                });
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse action needed
    }
};
