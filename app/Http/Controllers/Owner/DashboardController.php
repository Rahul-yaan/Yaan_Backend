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

        // Financial Calculation Model (34% Platform Fee on Base Hotel Price, 66% Owner Share, 18% GST breakdown):
        $baseRevenueSum       = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_amount, price_per_night)'));
        $grossCustomerPaid    = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_payable, round(total_amount * 1.18, 2))'));
        $totalDiscountApplied = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(promotion_applied, 0)'));

        if ($grossCustomerPaid <= 0 && $baseRevenueSum > 0) {
            $grossCustomerPaid = round($baseRevenueSum * 1.18, 2);
        }

        // Base room price total before GST (e.g. ₹50.00 for ₹59.00 gross)
        $baseRoomAmount       = $baseRevenueSum > 0 ? $baseRevenueSum : ($grossCustomerPaid > 0 ? round($grossCustomerPaid / 1.18, 2) : 0.00);
        // Total GST paid by customer (e.g. ₹9.00 for ₹59.00 gross)
        $customerGstTotal     = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(gst_amount, 0)'));
        if ($customerGstTotal <= 0 && $grossCustomerPaid > 0) {
            $customerGstTotal = round($grossCustomerPaid - $baseRoomAmount, 2);
        }

        // 34% Platform Fee calculated on Base Hotel Price (e.g. 34% of ₹50.00 = ₹17.00)
        $platformFeeCollected = round($baseRoomAmount * 0.34, 2);

        // Owner Profit / Payable Amount (66% of Base Price, e.g. ₹50.00 * 0.66 = ₹33.00)
        $ownerPayableEarnings = round($baseRoomAmount * 0.66, 2);

        // 18% GST on Owner Profit (e.g. 18% of ₹33.00 = ₹5.94)
        $ownerGstAmount       = round($ownerPayableEarnings * 0.18, 2);

        // 18% GST on Platform Fee (e.g. 18% of ₹17.00 = ₹3.06)
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
            'total_order'            => (string) $totalBookings,
            'today_bookings'         => $todayBookings,
            'today_orders'           => $todayBookings,
            'today_order'            => (string) $todayBookings,

            // Financial Summary Keys
            'total_amount'           => number_format($grossCustomerPaid, 2, '.', ''), // Total Paid by Customer (₹59.00)
            'base_amount'            => number_format($baseRoomAmount, 2, '.', ''),    // Base room price (₹50.00)
            'total_revenue'          => number_format($grossCustomerPaid, 2, '.', ''),
            'total_customer_paid'    => number_format($grossCustomerPaid, 2, '.', ''),
            'total_payable'          => number_format($grossCustomerPaid, 2, '.', ''),
            'total_discount_applied' => $totalDiscountApplied,

            'platform_fee'           => number_format($platformFeeCollected, 2, '.', ''), // 34% Platform Fee (₹17.00)
            'platform_fee_collected' => number_format($platformFeeCollected, 2, '.', ''),
            'admin_platform_fee'     => number_format($platformFeeCollected, 2, '.', ''),

            'payable_amount'         => number_format($ownerPayableEarnings, 2, '.', ''), // 66% Owner Profit (₹33.00)
            'total_payableamount'    => number_format($ownerPayableEarnings, 2, '.', ''),
            'owner_payable_revenue'  => number_format($ownerPayableEarnings, 2, '.', ''),
            'owner_net_share'        => number_format($ownerPayableEarnings, 2, '.', ''),
            'total_earnings'         => number_format($ownerPayableEarnings, 2, '.', ''),

            'gst_amount'             => number_format($ownerGstAmount, 2, '.', ''),       // 18% GST on Owner Profit (₹5.94)
            'total_gst'              => number_format($ownerGstAmount, 2, '.', ''),
            'owner_gst_amount'       => number_format($ownerGstAmount, 2, '.', ''),
            'customer_gst_amount'    => number_format($customerGstTotal, 2, '.', ''),     // Total Customer GST (₹9.00)
            'platform_gst_amount'    => number_format($platformGstAmount, 2, '.', ''),

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
            // Top-level aliases for direct property mapping (used by Android DashBoardModel)
            'total_amount'           => number_format($grossCustomerPaid, 2, '.', ''),      // Total Customer Paid (e.g. "59.00")
            'total_payable'          => number_format($grossCustomerPaid, 2, '.', ''),
            'total_customer_paid'    => number_format($grossCustomerPaid, 2, '.', ''),
            'platform_fee'           => number_format($platformFeeCollected, 2, '.', ''),   // Platform Fee 34% (e.g. "17.00")
            'payable_amount'         => number_format($ownerPayableEarnings, 2, '.', ''),   // Owner 66% Share (e.g. "33.00")
            'total_payableamount'    => number_format($ownerPayableEarnings, 2, '.', ''),   // Android Vendor field alias
            'gst_amount'             => number_format($ownerGstAmount, 2, '.', ''),         // Owner GST 18% (e.g. "5.94")
            'total_gst'              => number_format($ownerGstAmount, 2, '.', ''),         // Android Vendor field alias
            'total_order'            => (string) $totalBookings,
            'today_order'            => (string) $todayBookings,
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