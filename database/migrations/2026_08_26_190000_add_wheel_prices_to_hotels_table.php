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
        Schema::table('hotels', function (Blueprint $table) {
            if (!Schema::hasColumn('hotels', 'wheel_prices')) {
                $table->json('wheel_prices')->nullable()->after('price_per_night');
            }
            if (!Schema::hasColumn('hotels', 'discount_price')) {
                $table->decimal('discount_price', 10, 2)->nullable()->after('price_per_night');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            if (Schema::hasColumn('hotels', 'wheel_prices')) {
                $table->dropColumn('wheel_prices');
            }
            if (Schema::hasColumn('hotels', 'discount_price')) {
                $table->dropColumn('discount_price');
            }
        });
    }
};
