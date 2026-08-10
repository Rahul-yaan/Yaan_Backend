<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            $table->text('business_proof')->nullable()->change();
            $table->text('aadhaar_front')->nullable()->change();
            $table->text('aadhaar_back')->nullable()->change();
            $table->text('pan_card')->nullable()->change();
            $table->text('fssai_license')->nullable()->change();
            $table->text('gst_image')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            $table->string('business_proof')->nullable()->change();
            $table->string('aadhaar_front')->nullable()->change();
            $table->string('aadhaar_back')->nullable()->change();
            $table->string('pan_card')->nullable()->change();
            $table->string('fssai_license')->nullable()->change();
            $table->string('gst_image')->nullable()->change();
        });
    }
};
