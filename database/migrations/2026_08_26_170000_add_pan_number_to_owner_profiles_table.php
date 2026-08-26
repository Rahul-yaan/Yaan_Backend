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
        Schema::table('owner_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('owner_profiles', 'pan_number')) {
                $table->string('pan_number', 10)->nullable()->after('aadhaar_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('owner_profiles', 'pan_number')) {
                $table->dropColumn('pan_number');
            }
        });
    }
};
