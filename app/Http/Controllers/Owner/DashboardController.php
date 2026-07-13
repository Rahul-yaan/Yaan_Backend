<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Booking;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    // ============================================================
    // GET /api/owner/dashboard
    // ============================================================
    public function index(Request $request)
    {
        $ownerId = $request->user()->id;

        $hotelIds = Hotel::where('owner_id', $ownerId)->pluck('id');

        $totalHotels = $hotelIds->count();

        $totalBookings = Booking::whereIn('hotel_id', $hotelIds)->count();

        $todayBookings = Booking::whereIn('hotel_id', $hotelIds)
            ->whereDate('created_at', today())
            ->count();

        $totalEarnings = Booking::whereIn('hotel_id', $hotelIds)
            ->where('payment_status', 'paid')
            ->sum('total_amount');

        $pendingBookings = Booking::whereIn('hotel_id', $hotelIds)
            ->where('status', 'pending')
            ->count();

        $confirmedBookings = Booking::whereIn('hotel_id', $hotelIds)
            ->where('status', 'confirmed')
            ->count();

        $recentBookings = Booking::whereIn('hotel_id', $hotelIds)
            ->with(['hotel:id,name', 'user:id,name,phone'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return response()->json([
            'stats' => [
                'total_hotels'     => $totalHotels,
                'total_bookings'   => $totalBookings,
                'today_bookings'   => $todayBookings,
                'total_earnings'   => $totalEarnings,
                'pending_bookings' => $pendingBookings,
                'confirmed_bookings' => $confirmedBookings,
            ],
            'recent_bookings' => $recentBookings,
        ]);
    }
}