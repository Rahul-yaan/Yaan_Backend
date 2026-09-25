<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            $amenityIds = DB::table('amenities')
                ->whereIn(DB::raw('LOWER(name)'), ['men', 'women'])
                ->orWhereIn('id', [19, 20])
                ->pluck('id')
                ->toArray();

            if (!empty($amenityIds)) {
                DB::table('hotel_amenities')
                    ->whereIn('amenity_id', $amenityIds)
                    ->delete();

                DB::table('amenities')
                    ->whereIn('id', $amenityIds)
                    ->delete();
            }
        } catch (\Throwable $e) {
            // Log or ignore if table does not exist
        }
    }

    public function down(): void
    {
        // No rollback action needed for removed obsolete amenities
    }
};
