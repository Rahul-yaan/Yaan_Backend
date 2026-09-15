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

        // 34% Platform Base Fee (e.g. 34% of ₹60.00 = ₹20.40)
        $platformFeeBase      = round($baseRoomAmount * 0.34, 2);
        $platformGstAmount    = round($platformFeeBase * 0.18, 2); // e.g. 18% of ₹20.40 = ₹3.67
        $platformFeeCollected = round($platformFeeBase + $platformGstAmount, 2); // e.g. ₹24.07 Total Platform Collection

        // Owner Share Base (e.g. 66% of ₹60.00 = ₹39.60)
        $ownerBaseShare       = round($baseRoomAmount * 0.66, 2);
        $ownerGstAmount       = round($ownerBaseShare * 0.18, 2);  // e.g. 18% of ₹39.60 = ₹7.13
        $ownerTotalPayout     = round($ownerBaseShare + $ownerGstAmount, 2); // e.g. ₹46.73 Total Owner Net Payout

        $totalGst             = round($ownerGstAmount + $platformGstAmount, 2); // e.g. ₹10.80 Total GST

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

            // Financial Summary Keys (Base = ₹60.00, Customer Paid = ₹70.80, 66% Owner Share = ₹39.60, 34% Platform Fee = ₹20.40, GST = ₹10.80)
            'total_amount'           => number_format($grossCustomerPaid, 2, '.', ''), // Total Paid by Customer (₹70.80)
            'base_amount'            => number_format($baseRoomAmount, 2, '.', ''),    // Base room price (₹60.00)
            'total_revenue'          => number_format($grossCustomerPaid, 2, '.', ''),
            'total_customer_paid'    => number_format($grossCustomerPaid, 2, '.', ''),
            'total_payable'          => number_format($grossCustomerPaid, 2, '.', ''),
            'total_discount_applied' => $totalDiscountApplied,

            'platform_fee'           => number_format($platformFeeBase, 2, '.', ''),      // 34% Platform Base Fee (₹20.40)
            'platform_fee_collected' => number_format($platformFeeCollected, 2, '.', ''), // ₹24.07
            'platform_fee_base'      => number_format($platformFeeBase, 2, '.', ''),      // Base 34% Fee (₹20.40)
            'platform_fee_gst'       => number_format($platformGstAmount, 2, '.', ''),   // Platform GST 18% (₹3.67)
            'admin_platform_fee'     => number_format($platformFeeCollected, 2, '.', ''),

            'payable_amount'         => number_format($ownerBaseShare, 2, '.', ''),        // Owner Base Share 66% (₹39.60)
            'total_payableamount'    => number_format($ownerBaseShare, 2, '.', ''),        // Mobile App vendor field alias
            'owner_payable_revenue'  => number_format($ownerTotalPayout, 2, '.', ''),
            'owner_net_share'        => number_format($ownerBaseShare, 2, '.', ''),        // Owner Base Share 66% (₹39.60)
            'owner_total_payout'     => number_format($ownerTotalPayout, 2, '.', ''),
            'total_earnings'         => number_format($ownerTotalPayout, 2, '.', ''),

            'gst_amount'             => number_format($totalGst, 2, '.', ''),             // Total Customer GST (₹10.80)
            'total_gst'              => number_format($totalGst, 2, '.', ''),
            'owner_gst_amount'       => number_format($ownerGstAmount, 2, '.', ''),       // Owner GST 18% (₹7.13)
            'customer_gst_amount'    => number_format($totalGst, 2, '.', ''),
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
                'owner_net_share'        => $ownerBaseShare,
                'owner_gst_18_percent'   => $ownerGstAmount,
                'owner_total_payout'     => $ownerTotalPayout,
                'admin_platform_fee_34'  => $platformFeeBase,
                'platform_gst_18'        => $platformGstAmount,
                'platform_total_fee'     => $platformFeeCollected,
                'total_paid_by_customer' => $grossCustomerPaid,
                'customer_gst_total'     => $totalGst,
            ],
            // Top-level aliases for direct property mapping (used by Android / Flutter Owner App)
            'total_amount'           => number_format($grossCustomerPaid, 2, '.', ''),      // Total Customer Paid (e.g. "70.80")
            'total_payable'          => number_format($grossCustomerPaid, 2, '.', ''),
            'total_customer_paid'    => number_format($grossCustomerPaid, 2, '.', ''),
            'platform_fee'           => number_format($platformFeeBase, 2, '.', ''),          // Base 34% Platform Fee (e.g. "20.40")
            'platform_fee_base'      => number_format($platformFeeBase, 2, '.', ''),
            'platform_fee_collected' => number_format($platformFeeCollected, 2, '.', ''),
            'payable_amount'         => number_format($ownerBaseShare, 2, '.', ''),         // Base 66% Owner Share (e.g. "39.60")
            'total_payableamount'    => number_format($ownerBaseShare, 2, '.', ''),         // Android Vendor field alias
            'owner_net_share'        => number_format($ownerBaseShare, 2, '.', ''),
            'owner_total_payout'     => number_format($ownerTotalPayout, 2, '.', ''),
            'gst_amount'             => number_format($totalGst, 2, '.', ''),              // Total GST (e.g. "10.80")
            'total_gst'              => number_format($totalGst, 2, '.', ''),              // Android Vendor field alias
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