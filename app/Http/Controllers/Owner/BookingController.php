<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Booking;
use App\Support\BusinessDate;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    // ============================================================
    // GET /api/owner/bookings
    // ============================================================
    public function index(Request $request)
    {
        $ownerId = $request->user()->id;
        $hotelIds = Hotel::where('owner_id', $ownerId)->pluck('id');

        $filter = $request->query('filter', 'all');
        $today = BusinessDate::today();

        $query = Booking::whereIn('hotel_id', $hotelIds)
            ->with(['hotel:id,name,city', 'user:id,name,phone,email'])
            ->orderBy('created_at', 'desc');

        if ($filter === 'today') {
            [$startUtc, $endUtc] = BusinessDate::dayRangeUtc($today);
            $query->whereBetween('created_at', [$startUtc, $endUtc]);
        } elseif ($filter === 'upcoming') {
            $query->where('check_in', '>=', $today)
                  ->where('status', '!=', 'cancelled');
        } elseif ($filter === 'older') {
            $query->where('check_out', '<', $today);
        }

        $bookings = $query->get();

        return response()->json(['bookings' => $bookings]);
    }

    // ============================================================
    // GET /api/owner/bookings/{id}
    // ============================================================
    public function show(Request $request, $id)
    {
        $ownerId = $request->user()->id;
        $hotelIds = Hotel::where('owner_id', $ownerId)->pluck('id');

        $booking = Booking::whereIn('hotel_id', $hotelIds)
            ->with(['hotel', 'user:id,name,phone,email'])
            ->findOrFail($id);

        return response()->json(['booking' => $booking]);
    }

    // ============================================================
    // PUT /api/owner/bookings/{id}/status
    // ============================================================
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:confirmed,completed,cancelled',
        ]);

        $ownerId = $request->user()->id;
        $hotelIds = Hotel::where('owner_id', $ownerId)->pluck('id');

        /** @var Booking $booking */
        $booking = Booking::whereIn('hotel_id', $hotelIds)->findOrFail($id);

        // Security Fix (1.24): Disallow confirming or completing unpaid online bookings
        $isOnline = in_array(strtolower($booking->payment_method ?? ''), ['online', 'razorpay'], true);
        $isUnpaid = $booking->payment_status !== 'paid';

        if ($isOnline && $isUnpaid && in_array($request->status, ['confirmed', 'completed'], true)) {
            return response()->json([
                'error'   => 'Cannot confirm or complete an unpaid online booking.',
                'message' => 'Cannot confirm or complete an online booking until payment is received.',
            ], 422);
        }

        $booking->update(['status' => $request->status]);

        // Recompute available rooms accurately
        $booking->hotel->refreshAvailableRooms($booking->booking_date ? $booking->booking_date->toDateString() : null);

        return response()->json([
            'message' => 'Booking status updated.',
            'booking' => $booking,
        ]);
    }
}