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
            $table->date('check_out')->nullable()->change();
            $table->integer('guests')->nullable()->change();
            $table->integer('total_nights')->nullable()->change();

            $table->date('booking_date')->nullable();
            $table->string('truck_type')->nullable();
            $table->string('truck_no')->nullable();
            $table->string('logistics_name')->nullable();
            $table->string('logistics_number')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('promotion_applied', 10, 2)->default(0);
            $table->decimal('gst_amount', 10, 2)->default(0);
            $table->decimal('total_payable', 10, 2)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->date('check_out')->nullable(false)->change();
            $table->integer('guests')->default(1)->nullable(false)->change();
            $table->integer('total_nights')->nullable(false)->change();

            $table->dropColumn([
                'booking_date',
                'truck_type',
                'truck_no',
                'logistics_name',
                'logistics_number',
                'payment_method',
                'promotion_applied',
                'gst_amount',
                'total_payable'
            ]);
        });
    }
};
