<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Booking;
use App\Models\Hotel;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    // ============================================================
    // 1. POST REVIEW
    //    URL:    POST /api/reviews
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function store(Request $request)
    {
        $request->validate([
            'hotel_id'   => 'required|exists:hotels,id',
            'booking_id' => 'required|exists:bookings,id',
            'rating'     => 'required|integer|min:1|max:5',
            'comment'    => 'nullable|string|max:500',
        ]);

        // Check booking belongs to user
        $booking = Booking::where('id', $request->booking_id)
            ->where('user_id', $request->user()->id)
            ->where('hotel_id', $request->hotel_id)
            ->where('status', 'completed')
            ->first();

        if (!$booking) {
            return response()->json([
                'error' => 'You can only review hotels you have completed a stay at.',
            ], 403);
        }

        // Check if already reviewed
        $existing = Review::where('booking_id', $request->booking_id)->first();
        if ($existing) {
            return response()->json([
                'error' => 'You have already reviewed this booking.',
            ], 422);
        }

        $review = Review::create([
            'user_id'    => $request->user()->id,
            'hotel_id'   => $request->hotel_id,
            'booking_id' => $request->booking_id,
            'rating'     => $request->rating,
            'comment'    => $request->comment,
        ]);

        // Update hotel rating
        $hotel = Hotel::find($request->hotel_id);
        $avgRating = Review::where('hotel_id', $request->hotel_id)->avg('rating');
        $reviewCount = Review::where('hotel_id', $request->hotel_id)->count();

        $hotel->update([
            'rating'       => round($avgRating, 2),
            'review_count' => $reviewCount,
        ]);

        return response()->json([
            'message' => 'Review posted successfully.',
            'review'  => $review->load('user'),
        ], 201);
    }

    // ============================================================
    // 2. GET HOTEL REVIEWS
    //    URL:    GET /api/hotels/{id}/reviews
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function index($hotelId)
    {
        $reviews = Review::where('hotel_id', $hotelId)
            ->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['reviews' => $reviews]);
    }
}