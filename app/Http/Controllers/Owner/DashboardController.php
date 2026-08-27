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
        // Total actual amount paid by customer (including offer discount & GST)
        $totalCustomerPaid    = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_payable, total_amount)'));
        $baseRevenueSum       = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_amount, price_per_night)'));
        $totalDiscountApplied = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(promotion_applied, 0)'));
        $customerGstTotal     = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(gst_amount, 0)'));

        // Gross customer paid total (₹118 for ₹100 room + 18% GST)
        $grossCustomerPaid    = $totalCustomerPaid > 0 ? $totalCustomerPaid : ($baseRevenueSum > 0 ? round($baseRevenueSum * 1.18, 2) : 0.00);
        // Base room price total before GST (₹100)
        $baseRoomAmount       = $baseRevenueSum > 0 ? $baseRevenueSum : ($grossCustomerPaid > 0 ? round($grossCustomerPaid / 1.18, 2) : 0.00);

        $platformFeeCollected = round($grossCustomerPaid * 0.34, 2);       // 34% Platform Fee (e.g. ₹40.12)
        $ownerPayableEarnings = round($grossCustomerPaid * 0.66, 2);       // 66% Owner Net Share (e.g. ₹77.88)
        $ownerGstAmount       = round($ownerPayableEarnings * 0.18, 2);  // 18% GST on Net Share (e.g. ₹14.02)

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

            // Financial Summary Keys
            'total_amount'           => $baseRoomAmount,          // Base room price (₹100.00) so frontend total_amount * 1.18 = ₹118.00
            'base_amount'            => $baseRoomAmount,
            'total_revenue'          => $grossCustomerPaid,       // Gross Customer Paid (₹118.00)
            'total_customer_paid'    => $grossCustomerPaid,       // ₹118.00
            'total_payable'          => $grossCustomerPaid,       // ₹118.00
            'total_discount_applied' => $totalDiscountApplied,

            'platform_fee'           => $platformFeeCollected,    // 34% Platform Fee (₹40.12)
            'platform_fee_collected' => $platformFeeCollected,
            'admin_platform_fee'     => $platformFeeCollected,

            'payable_amount'         => $ownerPayableEarnings,    // 66% Owner Net Share (₹77.88)
            'owner_payable_revenue'  => $ownerPayableEarnings,
            'owner_net_share'        => $ownerPayableEarnings,
            'total_earnings'         => $ownerPayableEarnings,

            'gst_amount'             => $ownerGstAmount,          // 18% GST on Owner Net Share (₹14.02)
            'owner_gst_amount'       => $ownerGstAmount,
            'customer_gst_amount'    => $customerGstTotal,        // 18% GST paid by customer (₹18.00)

            'pending_bookings'       => $pendingBookings,
            'confirmed_bookings'     => $confirmedBookings,
        ];

        $user = $request->user();
        $ownerProfile = \App\Models\OwnerProfile::where('user_id', $user->id)->first();
        $targetHotel = Hotel::where('owner_id', $user->id)->first();

        $isVerified = (bool) $user->is_verified;
        $isProfileRejected = $ownerProfile && $ownerProfile->status === 'rejected';
        $isHotelRejected = $targetHotel && $targetHotel->status === 'rejected';
        $isProfileApproved = $ownerProfile && $ownerProfile->status === 'approved';
        $isHotelApproved = $targetHotel && in_array($targetHotel->status, ['approved', 'active']);

        if ($isVerified && $isProfileApproved && $isHotelApproved) {
            $kycStatus = 'approved';
            $rejectionReason = null;
            $kycMessage = 'Your Owner KYC and hotel profile are fully verified and active.';
        } elseif ($isProfileRejected || $isHotelRejected) {
            $kycStatus = 'rejected';
            $rejectionReason = ($ownerProfile && !empty($ownerProfile->rejection_reason))
                ? $ownerProfile->rejection_reason
                : (($targetHotel && !empty($targetHotel->rejection_reason)) ? $targetHotel->rejection_reason : 'Admin rejected your application.');
            $kycMessage = "Admin rejected your application for this reason: {$rejectionReason}";
        } else {
            $kycStatus = 'pending_approval';
            $rejectionReason = null;
            $kycMessage = 'Please wait for approval by the admin.';
        }

        $dashNotification = [
            'show'             => true,
            'type'             => $kycStatus === 'rejected' ? 'danger' : ($kycStatus === 'approved' ? 'success' : 'warning'),
            'title'            => $kycStatus === 'rejected' ? 'Application Rejected by Admin' : ($kycStatus === 'approved' ? 'Account Verified' : 'Approval Pending'),
            'message'          => $kycMessage,
            'kyc_message'      => $kycMessage,
            'rejection_reason' => $rejectionReason,
        ];

        return response()->json([
            'stats' => $stats,
            'financial_breakdown' => [
                'base_room_price_total'  => $baseRoomAmount,
                'owner_net_share_66'     => $ownerPayableEarnings,
                'owner_gst_18_percent'   => $ownerGstAmount,
                'admin_platform_fee_34'  => $platformFeeCollected,
                'total_paid_by_customer' => $grossCustomerPaid,
                'customer_gst_total'     => $customerGstTotal,
            ],
            // Top-level aliases for direct property mapping
            'total_amount'           => $baseRoomAmount,         // Base Room Amount (₹100.00)
            'total_payable'          => $grossCustomerPaid,      // Gross Customer Paid (₹118.00)
            'total_customer_paid'    => $grossCustomerPaid,      // ₹118.00
            'platform_fee'           => $platformFeeCollected,   // ₹40.12
            'payable_amount'         => $ownerPayableEarnings,   // ₹77.88
            'gst_amount'             => $ownerGstAmount,         // ₹14.02
            'total_order'            => $totalBookings,
            'today_order'            => $todayBookings,
            'recent_bookings'        => $recentBookings,
            'kyc_status'             => $kycStatus,
            'rejection_reason'       => $rejectionReason,
            'kyc_message'            => $kycMessage,
            'admin_message'          => $rejectionReason ?? $kycMessage,
            'notification'           => $dashNotification,
            'notification_bar'       => $dashNotification,
        ]);
    }
}