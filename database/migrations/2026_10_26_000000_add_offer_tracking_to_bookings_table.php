<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'banner_id')) {
                $table->unsignedBigInteger('banner_id')->nullable()->index()->after('hotel_id');
            }
            if (!Schema::hasColumn('bookings', 'discount_code')) {
                $table->string('discount_code', 100)->nullable()->after('banner_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'discount_code')) {
                $table->dropColumn('discount_code');
            }
            if (Schema::hasColumn('bookings', 'banner_id')) {
                $table->dropColumn('banner_id');
            }
        });
    }
};
