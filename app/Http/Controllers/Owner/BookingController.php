<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Booking;
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
        $today = today()->toDateString();

        $query = Booking::whereIn('hotel_id', $hotelIds)
            ->with(['hotel:id,name,city', 'user:id,name,phone,email'])
            ->orderBy('created_at', 'desc');

        if ($filter === 'today') {
            $query->whereDate('created_at', $today);
        } elseif ($filter === 'upcoming') {
            $query->where('check_in', '>', $today)
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

        $booking = Booking::whereIn('hotel_id', $hotelIds)->findOrFail($id);

        $booking->update(['status' => $request->status]);

        // Restore room if cancelled and status was actually changed
        if ($request->status === 'cancelled' && $booking->wasChanged('status')) {
            $booking->hotel->increment('available_rooms');
        }

        return response()->json([
            'message' => 'Booking status updated.',
            'booking' => $booking,
        ]);
    }
}