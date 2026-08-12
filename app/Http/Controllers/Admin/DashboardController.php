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
        $totalUsers     = User::where('role', 'user')->count();
        $totalOwners    = User::where('role', 'owner')->count();
        $verifiedOwners = OwnerProfile::whereRaw('("is_profile_complete" = true OR "is_profile_complete" IS TRUE)')->count();
        $pendingOwners  = User::where('role', 'owner')
            ->where(function($q) {
                $q->whereRaw('("is_verified" = false OR "is_verified" IS FALSE OR "is_verified" IS NULL)')
                  ->orWhereHas('ownerProfile', function($sq) {
                      $sq->whereRaw('("is_profile_complete" = false OR "is_profile_complete" IS FALSE OR "is_profile_complete" IS NULL)');
                  })
                  ->orDoesntHave('ownerProfile');
            })
            ->count();

        $totalHotels    = Hotel::count();
        $approvedHotels = Hotel::where('status', 'approved')->count();
        $pendingHotels  = Hotel::where('status', 'pending')->count();
        $rejectedHotels = Hotel::where('status', 'rejected')->count();

        $allBookingsCount = Booking::count();

        // Confirmed / Active Bookings: Only valid bookings that are paid and confirmed/completed (excluding cancelled & refunded)
        $confirmedBookingsCount = Booking::where(function($q) {
            $q->where('payment_status', 'paid')
              ->orWhereIn('status', ['confirmed', 'completed']);
        })
        ->whereNotIn('status', ['cancelled'])
        ->whereNotIn('payment_status', ['refunded', 'refund_initiated'])
        ->where(function($q) {
            $q->whereNull('cancellation_reason')
              ->orWhere('cancellation_reason', 'not like', '%refund%');
        })
        ->count();

        // Cancelled or Refunded Bookings count
        $cancelledBookingsCount = Booking::where('status', 'cancelled')
            ->orWhereIn('payment_status', ['refunded', 'refund_initiated'])
            ->orWhere('cancellation_reason', 'like', '%refund%')
            ->count();

        // Pending Bookings (temporary/incomplete)
        $pendingBookingsCount = Booking::where('status', 'pending')
            ->whereIn('payment_status', ['pending', 'failed'])
            ->count();

        // Total Revenue: Sum of payable amounts for confirmed/completed non-refunded bookings
        $totalRevenue = Booking::where(function($q) {
            $q->where('payment_status', 'paid')
              ->orWhereIn('status', ['confirmed', 'completed']);
        })
        ->whereNotIn('status', ['cancelled'])
        ->whereNotIn('payment_status', ['refunded', 'refund_initiated'])
        ->where(function($q) {
            $q->whereNull('cancellation_reason')
              ->orWhere('cancellation_reason', 'not like', '%refund%');
        })
        ->sum(DB::raw('COALESCE(total_payable, total_amount)'));

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
                'users_count'        => $totalUsers,
                'owners_count'       => $totalOwners,
                'verified_owners'    => $verifiedOwners,
                'pending_owners'     => $pendingOwners,
                'total_hotels'       => $totalHotels,
                'approved_hotels'    => $approvedHotels,
                'pending_hotels'     => $pendingHotels,
                'rejected_hotels'    => $rejectedHotels,
                'total_bookings'     => $confirmedBookingsCount, // Auto-updates to show valid active/confirmed bookings count
                'all_bookings'       => $allBookingsCount,
                'confirmed_bookings' => $confirmedBookingsCount,
                'active_bookings'    => $confirmedBookingsCount,
                'pending_bookings'   => $pendingBookingsCount,
                'cancelled_bookings' => $cancelledBookingsCount,
                'total_revenue'      => (float) $totalRevenue,
            ],
            'recent_bookings' => $recentBookings,
            'recent_hotels'   => $recentHotels,
        ]);
    }
}
