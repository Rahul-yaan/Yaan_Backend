<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Hotel;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BookingController extends Controller
{
    // ============================================================
    // 1. CREATE BOOKING
    //    URL:    POST /api/bookings
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function store(Request $request)
    {
        $request->validate([
            'hotel_id'   => 'required|exists:hotels,id',
            'check_in'   => 'required|date|after_or_equal:today',
            'check_out'  => 'required|date|after:check_in',
            'guests'     => 'required|integer|min:1|max:10',
        ]);

        $hotel = Hotel::where('id', $request->hotel_id)
            ->where('status', 'active')
            ->firstOrFail();

        // Check availability
        if ($hotel->available_rooms < 1) {
            return response()->json([
                'error' => 'No rooms available for this hotel.',
            ], 422);
        }

        // Check for overlapping bookings
        $overlap = Booking::where('hotel_id', $request->hotel_id)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($request) {
                $q->whereBetween('check_in', [$request->check_in, $request->check_out])
                  ->orWhereBetween('check_out', [$request->check_in, $request->check_out]);
            })->exists();

        if ($overlap) {
            return response()->json([
                'error' => 'Hotel is already booked for selected dates.',
            ], 422);
        }

        $checkIn    = Carbon::parse($request->check_in);
        $checkOut   = Carbon::parse($request->check_out);
        $totalNights = $checkIn->diffInDays($checkOut);
        $totalAmount = $totalNights * $hotel->price_per_night;

        $booking = Booking::create([
            'user_id'         => $request->user()->id,
            'hotel_id'        => $request->hotel_id,
            'check_in'        => $request->check_in,
            'check_out'       => $request->check_out,
            'total_nights'    => $totalNights,
            'guests'          => $request->guests,
            'price_per_night' => $hotel->price_per_night,
            'total_amount'    => $totalAmount,
            'status'          => 'pending',
            'payment_status'  => 'pending',
        ]);

        // Decrease available rooms
        $hotel->decrement('available_rooms');

        return response()->json([
            'message' => 'Booking created successfully.',
            'booking' => $booking->load('hotel'),
        ], 201);
    }

    // ============================================================
    // 2. GET MY BOOKINGS
    //    URL:    GET /api/bookings/my
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function myBookings(Request $request)
    {
        $bookings = Booking::where('user_id', $request->user()->id)
            ->with(['hotel.primaryImage'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['bookings' => $bookings]);
    }

    // ============================================================
    // 3. CANCEL BOOKING
    //    URL:    POST /api/bookings/{id}/cancel
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function cancel(Request $request, $id)
    {
        $booking = Booking::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($booking->status === 'cancelled') {
            return response()->json([
                'error' => 'Booking is already cancelled.',
            ], 422);
        }

        if ($booking->status === 'completed') {
            return response()->json([
                'error' => 'Cannot cancel a completed booking.',
            ], 422);
        }

        $booking->update([
            'status' => 'cancelled',
        ]);

        // Restore available rooms
        $booking->hotel->increment('available_rooms');

        return response()->json([
            'message' => 'Booking cancelled successfully.',
        ]);
    }
}