<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE hotels ALTER COLUMN status TYPE VARCHAR(50);");
            DB::statement("ALTER TABLE hotels ALTER COLUMN status SET DEFAULT 'pending';");
            DB::statement("DROP TYPE IF EXISTS hotels_status_enum CASCADE;");
        } else {
            Schema::table('hotels', function (Blueprint $table) {
                $table->string('status', 50)->default('pending')->change();
            });
        }
    }

    public function down(): void
    {
    }
};
