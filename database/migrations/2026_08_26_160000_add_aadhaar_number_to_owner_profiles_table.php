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
            if (!Schema::hasColumn('owner_profiles', 'aadhaar_number')) {
                $table->string('aadhaar_number', 12)->nullable()->after('pincode');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('owner_profiles', 'aadhaar_number')) {
                $table->dropColumn('aadhaar_number');
            }
        });
    }
};
