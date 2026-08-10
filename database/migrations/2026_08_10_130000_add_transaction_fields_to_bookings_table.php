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
            $table->string('temp_transaction_id')->nullable()->after('transaction_id');
            $table->string('cancellation_reason')->nullable()->after('payment_status');
            $table->text('gateway_response')->nullable()->after('cancellation_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['temp_transaction_id', 'cancellation_reason', 'gateway_response']);
        });
    }
};
