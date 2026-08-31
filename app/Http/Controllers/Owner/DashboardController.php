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

        // Financial Calculation Model (34% Platform Fee on Base Hotel Price, 18% GST breakdown):
        // Total actual amount paid by customer (including offer discount & GST, e.g. ₹50.00)
        $grossCustomerPaid    = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_payable, total_amount)'));
        $baseRevenueSum       = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_amount, price_per_night)'));
        $totalDiscountApplied = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(promotion_applied, 0)'));

        if ($grossCustomerPaid <= 0 && $baseRevenueSum > 0) {
            $grossCustomerPaid = round($baseRevenueSum * 1.18, 2);
        }

        // Base room price total before GST (e.g. ₹42.37 for ₹50.00 gross)
        $baseRoomAmount       = $grossCustomerPaid > 0 ? round($grossCustomerPaid / 1.18, 2) : 0.00;
        // Total GST paid by customer (e.g. ₹7.63 for ₹50.00 gross)
        $customerGstTotal     = $grossCustomerPaid > 0 ? round($grossCustomerPaid - $baseRoomAmount, 2) : 0.00;

        // 34% Platform Fee calculated on Base Hotel Price (e.g. 34% of ₹42.37 = ₹14.40)
        $platformFeeCollected = round($baseRoomAmount * 0.34, 2);

        // Owner Profit / Payable Amount = Base Price minus Platform Fee (e.g. ₹42.37 - ₹14.40 = ₹27.97)
        $ownerPayableEarnings = round($baseRoomAmount - $platformFeeCollected, 2);

        // 18% GST on Owner Profit (e.g. 18% of ₹27.97 = ₹5.03)
        $ownerGstAmount       = round($ownerPayableEarnings * 0.18, 2);

        // 18% GST on Platform Fee (e.g. 18% of ₹14.40 = ₹2.59)
        $platformGstAmount    = round($platformFeeCollected * 0.18, 2);

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
            'total_amount'           => $grossCustomerPaid,       // Total Paid by Customer (₹50.00)
            'base_amount'            => $baseRoomAmount,          // Base room price (₹42.37)
            'total_revenue'          => $grossCustomerPaid,       // Gross Customer Paid (₹50.00)
            'total_customer_paid'    => $grossCustomerPaid,       // ₹50.00
            'total_payable'          => $grossCustomerPaid,       // ₹50.00
            'total_discount_applied' => $totalDiscountApplied,

            'platform_fee'           => $platformFeeCollected,    // 34% Platform Fee on Base (₹14.40)
            'platform_fee_collected' => $platformFeeCollected,
            'admin_platform_fee'     => $platformFeeCollected,

            'payable_amount'         => $ownerPayableEarnings,    // Owner Profit / Net Share (₹27.97)
            'owner_payable_revenue'  => $ownerPayableEarnings,
            'owner_net_share'        => $ownerPayableEarnings,
            'total_earnings'         => $ownerPayableEarnings,

            'gst_amount'             => $ownerGstAmount,          // 18% GST on Owner Profit (₹5.03)
            'owner_gst_amount'       => $ownerGstAmount,
            'customer_gst_amount'    => $customerGstTotal,        // Total GST (₹7.63)
            'platform_gst_amount'    => $platformGstAmount,       // 18% GST on Platform Fee (₹2.59)

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

        $kycStatus = 'pending';
        if ($isProfileRejected || $isHotelRejected) {
            $kycStatus = 'rejected';
        } elseif ($isVerified || $isProfileApproved) {
            $kycStatus = 'approved';
        }

        $rejectionReason = null;
        if ($isProfileRejected) {
            $rejectionReason = $ownerProfile->rejection_reason ?? 'Your Profile/KYC documents were rejected by Admin.';
        } elseif ($isHotelRejected) {
            $rejectionReason = $targetHotel->rejection_reason ?? 'Your Hotel listing was rejected by Admin.';
        }

        $kycMessage = match ($kycStatus) {
            'approved' => 'Your account is fully verified & active.',
            'rejected' => $rejectionReason ?? 'Your verification failed.',
            default    => 'Your profile or hotel document is under verification by Admin.',
        };

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
                'owner_net_share'        => $ownerPayableEarnings,
                'owner_gst_18_percent'   => $ownerGstAmount,
                'admin_platform_fee_34'  => $platformFeeCollected,
                'platform_gst_18'        => $platformGstAmount,
                'total_paid_by_customer' => $grossCustomerPaid,
                'customer_gst_total'     => $customerGstTotal,
            ],
            // Top-level aliases for direct property mapping
            'total_amount'           => $grossCustomerPaid,      // Total Customer Paid (₹50.00)
            'total_payable'          => $grossCustomerPaid,      // Gross Customer Paid (₹50.00)
            'total_customer_paid'    => $grossCustomerPaid,      // ₹50.00
            'platform_fee'           => $platformFeeCollected,   // ₹14.40
            'payable_amount'         => $ownerPayableEarnings,   // ₹27.97
            'gst_amount'             => $ownerGstAmount,         // ₹5.03
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