<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('owner_profiles', 'status')) {
                $table->string('status', 50)->default('pending')->after('is_profile_complete');
            }
            if (!Schema::hasColumn('owner_profiles', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('status');
            }
        });

        Schema::table('hotels', function (Blueprint $table) {
            if (!Schema::hasColumn('hotels', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('owner_profiles', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
            if (Schema::hasColumn('owner_profiles', 'status')) {
                $table->dropColumn('status');
            }
        });

        Schema::table('hotels', function (Blueprint $table) {
            if (Schema::hasColumn('hotels', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }
        });
    }
};
