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
            'hotel_id'         => 'required|exists:hotels,id',
            'booking_date'     => 'required|date|after_or_equal:today',
            'truck_type'       => 'required|string',
            'truck_no'         => 'required|string',
            'logistics_name'   => 'required|string',
            'logistics_number' => 'required|string',
            'payment_method'   => 'required|string',
        ]);

        $hotel = Hotel::where('id', $request->hotel_id)
            ->where('status', 'active')
            ->firstOrFail();

        // Check availability
        if ($hotel->available_rooms < 1) {
            return response()->json([
                'error' => 'No parking slots available for this location.',
            ], 422);
        }

        $price = $hotel->price_per_night;
        $gstAmount = $price * 0.18;
        $totalPayable = $price + $gstAmount;

        $booking = Booking::create([
            'user_id'          => $request->user()->id,
            'hotel_id'         => $request->hotel_id,
            'booking_date'     => $request->booking_date,
            'truck_type'       => $request->truck_type,
            'truck_no'         => $request->truck_no,
            'logistics_name'   => $request->logistics_name,
            'logistics_number' => $request->logistics_number,
            'payment_method'   => $request->payment_method,
            
            'price_per_night'  => $price,
            'total_amount'     => $price,
            'promotion_applied'=> 0.00,
            'gst_amount'       => $gstAmount,
            'total_payable'    => $totalPayable,
            
            'status'           => 'pending',
            'payment_status'   => 'pending',
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