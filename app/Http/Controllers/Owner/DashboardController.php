<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // ============================================================
    // GET /api/owner/dashboard (Hotel Owner App Insights Endpoint)
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

        // Owner Confirmed & Paid Bookings Query
        $ownerBookingsQuery = Booking::whereIn('hotel_id', $hotelIds)
            ->where(function($q) {
                $q->where('payment_status', 'paid')
                  ->orWhereIn('status', ['confirmed', 'completed']);
            })
            ->whereNotIn('status', ['cancelled'])
            ->whereNotIn('payment_status', ['refunded', 'refund_initiated']);

        // Financial Calculation Logic:
        // Owner Base Amount (e.g. ₹100 set by owner) -> Owner Net Earnings (total_amount / price_per_night)
        // Platform Fee (GST 18% = ₹18) -> Collected by platform (gst_amount)
        // Customer Total Paid -> ₹118 (total_payable)
        $ownerPayableEarnings = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_amount, price_per_night)'));
        $totalCustomerPaid    = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_payable, total_amount)'));
        $platformFeeCollected = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(gst_amount, 0)'));
        $totalDiscountApplied = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(promotion_applied, 0)'));

        $pendingBookings = Booking::whereIn('hotel_id', $hotelIds)
            ->where('status', 'pending')
            ->count();

        $confirmedBookings = Booking::whereIn('hotel_id', $hotelIds)
            ->whereIn('status', ['confirmed', 'completed'])
            ->count();

        $recentBookings = Booking::whereIn('hotel_id', $hotelIds)
            ->with(['hotel:id,name', 'user:id,name,phone'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return response()->json([
            'stats' => [
                'total_hotels'           => $totalHotels,
                'total_bookings'         => $totalBookings,
                'today_bookings'         => $todayBookings,
                'total_earnings'         => $ownerPayableEarnings, // Base Owner Revenue (e.g. ₹100 or ₹95 net)
                'owner_payable_revenue'  => $ownerPayableEarnings, // Base Owner Revenue
                'total_customer_paid'    => $totalCustomerPaid,    // Total Customer Paid (e.g. ₹118)
                'platform_fee_collected' => $platformFeeCollected, // Platform Fee / GST (e.g. ₹18)
                'total_discount_applied' => $totalDiscountApplied, // Discount (e.g. 5% discount)
                'pending_bookings'       => $pendingBookings,
                'confirmed_bookings'     => $confirmedBookings,
            ],
            'financial_breakdown' => [
                'owner_base_price'       => $ownerPayableEarnings,
                'total_discount_applied' => $totalDiscountApplied,
                'gst_platform_fee'       => $platformFeeCollected,
                'total_paid_by_customer' => $totalCustomerPaid,
            ],
            'recent_bookings' => $recentBookings,
        ]);
    }
}