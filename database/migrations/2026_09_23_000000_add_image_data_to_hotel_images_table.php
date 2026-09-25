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
        if (Schema::hasTable('hotel_images') && !Schema::hasColumn('hotel_images', 'image_data')) {
            Schema::table('hotel_images', function (Blueprint $table) {
                $table->text('image_data')->nullable()->after('image_path');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('hotel_images') && Schema::hasColumn('hotel_images', 'image_data')) {
            Schema::table('hotel_images', function (Blueprint $table) {
                $table->dropColumn('image_data');
            });
        }
    }
};
