<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Booking;
use App\Models\Hotel;
use App\Support\BusinessDate;
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
            'booking_id' => 'nullable|exists:bookings,id',
            'rating'     => 'required|integer|min:1|max:5',
            'comment'    => 'nullable|string|max:500',
        ]);

        $todayIndia = BusinessDate::today();
        $bookingId = $request->booking_id;

        // Anti-fake review check:
        // Must be completed stay, OR confirmed + paid + booking_date before today in India
        $eligibleQuery = function ($q) use ($todayIndia) {
            $q->where('status', 'completed')
              ->orWhere(function ($sq) use ($todayIndia) {
                  $sq->where('status', 'confirmed')
                     ->where('payment_status', 'paid')
                     ->whereDate('booking_date', '<', $todayIndia);
              });
        };

        if ($bookingId) {
            $booking = Booking::where('id', $bookingId)
                ->where('user_id', $request->user()->id)
                ->where('hotel_id', $request->hotel_id)
                ->where($eligibleQuery)
                ->first();

            if (!$booking) {
                return response()->json([
                    'error'   => 'You can review a hotel after your stay there is complete.',
                    'message' => 'You can review a hotel after your stay there is complete.',
                ], 403);
            }
        } else {
            // Find most recent eligible booking
            $userBooking = Booking::where('user_id', $request->user()->id)
                ->where('hotel_id', $request->hotel_id)
                ->where($eligibleQuery)
                ->latest()
                ->first();

            if (!$userBooking) {
                return response()->json([
                    'error'   => 'You can review a hotel after your stay there is complete.',
                    'message' => 'You can review a hotel after your stay there is complete.',
                ], 403);
            }

            $bookingId = $userBooking->id;
        }

        $review = Review::create([
            'user_id'    => $request->user()->id,
            'hotel_id'   => $request->hotel_id,
            'booking_id' => $bookingId,
            'rating'     => $request->rating,
            'comment'    => $request->comment,
        ]);

        // Update hotel rating & review count
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