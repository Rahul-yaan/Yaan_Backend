<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Hotel;
use App\Models\Booking;
use App\Models\OwnerProfile;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $totalUsers = User::where('role', 'user')->count();
        $totalOwners = User::where('role', 'owner')->count();
        $verifiedOwners = OwnerProfile::where('is_verified', true)->count();
        $pendingOwners = OwnerProfile::where('is_verified', false)->count();

        $totalHotels = Hotel::count();
        $approvedHotels = Hotel::where('status', 'approved')->count();
        $pendingHotels = Hotel::where('status', 'pending')->count();
        $rejectedHotels = Hotel::where('status', 'rejected')->count();

        $totalBookings = Booking::count();
        $activeBookings = Booking::whereIn('status', ['confirmed', 'pending'])->count();
        $completedBookings = Booking::where('status', 'completed')->count();
        $cancelledBookings = Booking::where('status', 'cancelled')->count();

        $totalRevenue = Booking::where('payment_status', 'paid')
            ->orWhere('status', 'confirmed')
            ->sum('total_amount');

        $recentBookings = Booking::with(['user:id,name,email,phone', 'hotel:id,name,city'])
            ->latest()
            ->take(5)
            ->get();

        $recentHotels = Hotel::with(['owner:id,name,email'])
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'metrics' => [
                'users_count'       => $totalUsers,
                'owners_count'      => $totalOwners,
                'verified_owners'   => $verifiedOwners,
                'pending_owners'    => $pendingOwners,
                'total_hotels'      => $totalHotels,
                'approved_hotels'   => $approvedHotels,
                'pending_hotels'    => $pendingHotels,
                'rejected_hotels'   => $rejectedHotels,
                'total_bookings'    => $totalBookings,
                'active_bookings'   => $activeBookings,
                'completed_bookings'=> $completedBookings,
                'cancelled_bookings'=> $cancelledBookings,
                'total_revenue'     => (float) $totalRevenue,
            ],
            'recent_bookings' => $recentBookings,
            'recent_hotels'   => $recentHotels,
        ]);
    }
}
