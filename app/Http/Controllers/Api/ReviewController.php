<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Review;
use App\Models\Hotel;

class ReviewController extends Controller
{
    public function index($hotel_id)
    {
        return Review::where('hotel_id', $hotel_id)
            ->with('user:id,name,email')
            ->latest()
            ->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'hotel_id'   => 'required|exists:hotels,id',
            'booking_id' => 'nullable|exists:bookings,id',
            'rating'     => 'required|integer|min:1|max:5',
            'comment'    => 'nullable|string|max:500',
        ]);

        $userId = $request->user() ? $request->user()->id : null;

        $review = Review::create([
            'user_id'    => $userId,
            'hotel_id'   => $request->hotel_id,
            'booking_id' => $request->booking_id,
            'rating'     => $request->rating,
            'comment'    => $request->comment,
        ]);

        $hotel = Hotel::find($request->hotel_id);
        if ($hotel) {
            $avgRating = Review::where('hotel_id', $request->hotel_id)->avg('rating') ?? 0;
            $reviewCount = Review::where('hotel_id', $request->hotel_id)->count();

            $hotel->update([
                'rating'       => round((float)$avgRating, 2),
                'review_count' => $reviewCount,
            ]);
        }

        return response()->json([
            'message' => 'Review posted successfully.',
            'review'  => $review->load('user:id,name,email'),
        ], 201);
    }
}