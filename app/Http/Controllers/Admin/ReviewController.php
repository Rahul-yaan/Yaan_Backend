<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Hotel;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $reviews = Review::with(['user:id,name,email', 'hotel:id,name,city'])
            ->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json($reviews);
    }

    public function destroy($id)
    {
        $review = Review::findOrFail($id);
        $hotelId = $review->hotel_id;
        $review->delete();

        // Sync hotel rating & review count after deletion
        $hotel = Hotel::find($hotelId);
        if ($hotel) {
            $avgRating = Review::where('hotel_id', $hotelId)->avg('rating') ?? 0;
            $reviewCount = Review::where('hotel_id', $hotelId)->count();

            $hotel->update([
                'rating'       => round((float)$avgRating, 2),
                'review_count' => $reviewCount,
            ]);
        }

        return response()->json([
            'message' => 'Review deleted successfully.'
        ]);
    }
}
