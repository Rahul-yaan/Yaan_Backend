<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Old bookings saved total_amount WITH GST (same as total_payable).
     * total_amount must be the room price before GST, so fix those rows once.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE bookings
            SET total_amount = total_payable - gst_amount
            WHERE total_payable IS NOT NULL
              AND gst_amount IS NOT NULL
              AND gst_amount > 0
              AND total_amount = total_payable
        ");
    }

    public function down(): void
    {
        // Not reversible: the old value was wrong.
    }
};
