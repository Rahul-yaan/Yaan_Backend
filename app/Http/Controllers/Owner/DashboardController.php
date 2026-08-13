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

        // Financial Calculation Model (34% Admin Platform Fee & 66% Owner Net Revenue):
        // Example: Base Price = ₹100 | GST (18%) = ₹18 | User Pays = ₹118
        // Admin Platform Fee (34%) = ₹34
        // Owner Net Revenue (66%) = ₹66
        // Owner GST (18% on ₹66) = ₹11.88
        $baseRevenueSum       = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_amount, price_per_night)'));
        $totalCustomerPaid    = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_payable, total_amount)'));
        $totalDiscountApplied = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(promotion_applied, 0)'));

        $platformFeeCollected = round($baseRevenueSum * 0.34, 2);       // ₹34 per ₹100 base
        $ownerPayableEarnings = round($baseRevenueSum * 0.66, 2);       // ₹66 per ₹100 base
        $ownerGstAmount       = round($ownerPayableEarnings * 0.18, 2);  // ₹11.88 GST on ₹66 net

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
                'total_earnings'         => $ownerPayableEarnings, // ₹66 Owner Net Share per ₹100 base price
                'owner_payable_revenue'  => $ownerPayableEarnings, // ₹66 Owner Net Share per ₹100 base price
                'owner_gst_amount'       => $ownerGstAmount,       // ₹11.88 (18% GST on ₹66)
                'platform_fee_collected' => $platformFeeCollected, // ₹34 Platform Fee (34%)
                'total_customer_paid'    => $totalCustomerPaid,    // ₹118 Total Paid by User
                'total_discount_applied' => $totalDiscountApplied,
                'pending_bookings'       => $pendingBookings,
                'confirmed_bookings'     => $confirmedBookings,
            ],
            'financial_breakdown' => [
                'base_room_price_total'  => $baseRevenueSum,
                'owner_net_share_66'     => $ownerPayableEarnings, // ₹66
                'owner_gst_18_percent'   => $ownerGstAmount,       // ₹11.88
                'admin_platform_fee_34'  => $platformFeeCollected, // ₹34
                'total_paid_by_customer' => $totalCustomerPaid,    // ₹118
            ],
            'recent_bookings' => $recentBookings,
        ]);
    }
}