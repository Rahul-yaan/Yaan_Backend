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
        // 1. Add yaan_id & qr_code_url to hotels table if not present
        Schema::table('hotels', function (Blueprint $table) {
            if (!Schema::hasColumn('hotels', 'yaan_id')) {
                $table->string('yaan_id', 50)->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('hotels', 'qr_code_url')) {
                $table->text('qr_code_url')->nullable()->after('status');
            }
        });

        // 2. Add booking_type to bookings table if not present
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'booking_type')) {
                $table->string('booking_type', 30)->default('app_search')->after('payment_method');
            }
        });

        // 3. Automatically backfill existing hotels with unique yaan_id
        $hotels = DB::table('hotels')->whereNull('yaan_id')->orWhere('yaan_id', '')->get(['id']);
        foreach ($hotels as $h) {
            $code = 'YAAN-H' . str_pad((string)$h->id, 4, '0', STR_PAD_LEFT);
            // Ensure uniqueness
            $counter = 1;
            while (DB::table('hotels')->where('yaan_id', $code)->where('id', '!=', $h->id)->exists()) {
                $code = 'YAAN-H' . str_pad((string)($h->id + $counter * 100), 4, '0', STR_PAD_LEFT);
                $counter++;
            }
            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' . urlencode($code);
            DB::table('hotels')->where('id', $h->id)->update([
                'yaan_id'     => $code,
                'qr_code_url' => $qrUrl,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            if (Schema::hasColumn('hotels', 'yaan_id')) {
                $table->dropColumn('yaan_id');
            }
            if (Schema::hasColumn('hotels', 'qr_code_url')) {
                $table->dropColumn('qr_code_url');
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'booking_type')) {
                $table->dropColumn('booking_type');
            }
        });
    }
};
