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

        // Optional Date Filtering (Today, Last 7 Days, Last 25 Days, Custom)
        $filter = strtolower(trim($request->query('filter', $request->query('period', 'all'))));
        $startDate = $request->query('start_date');
        $endDate   = $request->query('end_date');

        $bookingsQuery = Booking::whereIn('hotel_id', $hotelIds);

        if ($filter === 'today') {
            $bookingsQuery->whereDate('created_at', today());
        } elseif ($filter === 'last_7_days' || $filter === '7days') {
            $bookingsQuery->where('created_at', '>=', now()->subDays(7)->startOfDay());
        } elseif ($filter === 'last_25_days' || $filter === '25days') {
            $bookingsQuery->where('created_at', '>=', now()->subDays(25)->startOfDay());
        } elseif (!empty($startDate) && !empty($endDate)) {
            $bookingsQuery->whereBetween('created_at', [
                \Carbon\Carbon::parse($startDate)->startOfDay(),
                \Carbon\Carbon::parse($endDate)->endOfDay()
            ]);
        }

        $totalBookings = (clone $bookingsQuery)->count();

        $todayBookings = Booking::whereIn('hotel_id', $hotelIds)
            ->whereDate('created_at', today())
            ->count();

        // Owner Confirmed, Paid & Cash (Pay at hotel) Bookings Query
        $ownerBookingsQuery = (clone $bookingsQuery)
            ->where(function($q) {
                $q->whereIn('payment_status', ['paid', 'pay_at_hotel', 'cash', 'completed'])
                  ->orWhereIn('status', ['confirmed', 'completed']);
            })
            ->whereNotIn('status', ['cancelled'])
            ->whereNotIn('payment_status', ['refunded', 'refund_initiated']);

        // Financial Calculation Model (34% Admin Platform Fee & 66% Owner Net Revenue):
        // Example: Base Price = ₹100 | 5% Discount = -₹5 | Discounted Room Price = ₹95 | GST (18%) = ₹17.10 | Customer Pays = ₹112.10
        // Base Room Revenue = ₹95 (or ₹100 base)
        // Admin Platform Fee (34%) = ₹32.30 (or ₹34 on ₹100 base)
        // Owner Net Revenue (66%) = ₹62.70 (or ₹66 on ₹100 base)
        // Owner GST (18% on Net Share) = ₹11.29 (or ₹11.88 on ₹66 net)
        $baseRevenueSum       = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_amount, price_per_night)'));
        $totalCustomerPaid    = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_payable, total_amount)'));
        $totalDiscountApplied = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(promotion_applied, 0)'));
        $customerGstTotal     = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(gst_amount, 0)'));

        $platformFeeCollected = round($baseRevenueSum * 0.34, 2);       // 34% Admin Platform Fee
        $ownerPayableEarnings = round($baseRevenueSum * 0.66, 2);       // 66% Owner Net Share
        $ownerGstAmount       = round($ownerPayableEarnings * 0.18, 2);  // 18% GST on Net Share

        $pendingBookings = (clone $bookingsQuery)
            ->where('status', 'pending')
            ->count();

        $confirmedBookings = (clone $bookingsQuery)
            ->whereIn('status', ['confirmed', 'completed'])
            ->count();

        $recentBookings = Booking::whereIn('hotel_id', $hotelIds)
            ->with(['hotel:id,name', 'user:id,name,phone'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $stats = [
            'total_hotels'           => $totalHotels,
            'total_bookings'         => $totalBookings,
            'total_orders'           => $totalBookings,
            'total_order'            => $totalBookings,
            'today_bookings'         => $todayBookings,
            'today_orders'           => $todayBookings,
            'today_order'            => $todayBookings,

            // Financial Summary Keys (supporting all client card naming variations)
            'total_amount'           => $baseRevenueSum,          // Room Base Amount (₹95.00 / ₹100.00)
            'base_amount'            => $baseRevenueSum,
            'total_revenue'          => $totalCustomerPaid,       // Gross Customer Paid (₹112.10)
            'total_customer_paid'    => $totalCustomerPaid,
            'total_payable'          => $totalCustomerPaid,
            'total_discount_applied' => $totalDiscountApplied,

            'platform_fee'           => $platformFeeCollected,    // 34% Platform Fee (₹32.30 / ₹34.00)
            'platform_fee_collected' => $platformFeeCollected,
            'admin_platform_fee'     => $platformFeeCollected,

            'payable_amount'         => $ownerPayableEarnings,    // 66% Owner Net Share (₹62.70 / ₹66.00)
            'owner_payable_revenue'  => $ownerPayableEarnings,
            'owner_net_share'        => $ownerPayableEarnings,
            'total_earnings'         => $ownerPayableEarnings,

            'gst_amount'             => $ownerGstAmount,          // 18% GST on Owner Net Share (₹11.29 / ₹11.88)
            'owner_gst_amount'       => $ownerGstAmount,
            'customer_gst_amount'    => $customerGstTotal,

            'pending_bookings'       => $pendingBookings,
            'confirmed_bookings'     => $confirmedBookings,
        ];

        return response()->json([
            'stats' => $stats,
            'financial_breakdown' => [
                'base_room_price_total'  => $baseRevenueSum,
                'owner_net_share_66'     => $ownerPayableEarnings,
                'owner_gst_18_percent'   => $ownerGstAmount,
                'admin_platform_fee_34'  => $platformFeeCollected,
                'total_paid_by_customer' => $totalCustomerPaid,
                'customer_gst_total'     => $customerGstTotal,
            ],
            // Top-level aliases for direct property mapping
            'total_amount'           => $baseRevenueSum,
            'platform_fee'           => $platformFeeCollected,
            'payable_amount'         => $ownerPayableEarnings,
            'gst_amount'             => $ownerGstAmount,
            'total_order'            => $totalBookings,
            'today_order'            => $todayBookings,
            'recent_bookings'        => $recentBookings,
        ]);
    }
}