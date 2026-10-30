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
            [$startUtc, $endUtc] = \App\Support\BusinessDate::dayRangeUtc();
            $bookingsQuery->whereBetween('created_at', [$startUtc, $endUtc]);
        } elseif ($filter === 'last_7_days' || $filter === '7days') {
            $sevenDaysAgo = \App\Support\BusinessDate::now()->subDays(7)->startOfDay()->utc();
            $bookingsQuery->where('created_at', '>=', $sevenDaysAgo);
        } elseif ($filter === 'last_25_days' || $filter === '25days') {
            $twentyFiveDaysAgo = \App\Support\BusinessDate::now()->subDays(25)->startOfDay()->utc();
            $bookingsQuery->where('created_at', '>=', $twentyFiveDaysAgo);
        } elseif (!empty($startDate) && !empty($endDate)) {
            $startUtc = \Carbon\Carbon::parse($startDate, \App\Support\BusinessDate::TIMEZONE)->startOfDay()->utc();
            $endUtc   = \Carbon\Carbon::parse($endDate, \App\Support\BusinessDate::TIMEZONE)->endOfDay()->utc();
            $bookingsQuery->whereBetween('created_at', [$startUtc, $endUtc]);
        }

        $totalBookings = (clone $bookingsQuery)->count();

        [$startToday, $endToday] = \App\Support\BusinessDate::dayRangeUtc();
        $todayBookings = Booking::whereIn('hotel_id', $hotelIds)
            ->whereBetween('created_at', [$startToday, $endToday])
            ->count();

        // Owner Confirmed, Paid & Cash (Pay at hotel) Bookings Query
        $ownerBookingsQuery = (clone $bookingsQuery)
            ->where(function($q) {
                $q->whereIn('payment_status', ['paid', 'pay_at_hotel', 'cash', 'completed'])
                  ->orWhereIn('status', ['confirmed', 'completed']);
            })
            ->whereNotIn('status', ['cancelled'])
            ->whereNotIn('payment_status', ['refunded', 'refund_initiated']);

        // Financial split (66% owner / 34% platform of the room price before GST, 18% GST on each share).
        // Example: base ₹42.37 + GST ₹7.63 = ₹50.00 paid -> owner ₹27.96 + ₹5.03, platform ₹14.41 + ₹2.60
        $split = \App\Support\Payout::sum((clone $ownerBookingsQuery)->get(\App\Support\Payout::COLUMNS));

        $grossCustomerPaid    = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(total_payable, round(total_amount * 1.18, 2))'));
        $totalDiscountApplied = (float) (clone $ownerBookingsQuery)->sum(DB::raw('COALESCE(promotion_applied, 0)'));

        if ($grossCustomerPaid <= 0 && $split['customer_paid'] > 0) {
            $grossCustomerPaid = $split['customer_paid'];
        }

        $baseRoomAmount       = $split['base'];             // Room price total before GST
        $platformFeeBase      = $split['platform_profit'];  // 34% platform fee (without GST)
        $platformGstAmount    = $split['platform_gst'];
        $platformFeeCollected = $split['platform_total'];
        $ownerBaseShare       = $split['owner_profit'];     // 66% owner share (without GST)
        $ownerGstAmount       = $split['owner_gst'];
        $ownerTotalPayout     = $split['owner_total'];
        $totalGst             = $split['gst'];              // Total GST paid by customers

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

            'platform_fee'           => number_format($platformFeeBase, 2, '.', ''),      // 34% Platform Base Fee (cut 18% GST)
            'platform_fee_collected' => number_format($platformFeeBase, 2, '.', ''),      // 34% Platform Fee (cut 18% GST)
            'platform_fee_base'      => number_format($platformFeeBase, 2, '.', ''),      // Base 34% Fee
            'platform_fee_gst'       => number_format($platformGstAmount, 2, '.', ''),   // Platform GST 18%
            'admin_platform_fee'     => number_format($platformFeeBase, 2, '.', ''),

            'payable_amount'         => number_format($ownerBaseShare, 2, '.', ''),        // Owner Final Payout 66% (cut 18% GST)
            'total_payableamount'    => number_format($ownerBaseShare, 2, '.', ''),        // Mobile App vendor field alias
            'owner_payable_revenue'  => number_format($ownerBaseShare, 2, '.', ''),        // Final payout without GST
            'owner_net_share'        => number_format($ownerBaseShare, 2, '.', ''),        // Owner Base Share 66% (cut 18% GST)
            'owner_total_payout'     => number_format($ownerBaseShare, 2, '.', ''),        // Final payout without GST
            'total_earnings'         => number_format($ownerBaseShare, 2, '.', ''),        // Final payout without GST

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
            'platform_fee'           => number_format($platformFeeBase, 2, '.', ''),          // Base 34% Platform Fee (cut 18% GST)
            'platform_fee_base'      => number_format($platformFeeBase, 2, '.', ''),
            'platform_fee_collected' => number_format($platformFeeBase, 2, '.', ''),          // 34% Platform Fee (cut 18% GST)
            'payable_amount'         => number_format($ownerBaseShare, 2, '.', ''),         // Base 66% Owner Share (cut 18% GST)
            'total_payableamount'    => number_format($ownerBaseShare, 2, '.', ''),         // Android Vendor field alias
            'owner_net_share'        => number_format($ownerBaseShare, 2, '.', ''),
            'owner_total_payout'     => number_format($ownerBaseShare, 2, '.', ''),         // Final Owner Payout (cut 18% GST)
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
            // Hotel Yaan ID & QR Code details for Owner App display & download
            'hotel_id'               => $targetHotel ? $targetHotel->id : null,
            'hotel_name'             => $targetHotel ? $targetHotel->name : null,
            'yaan_id'                => $targetHotel ? $targetHotel->yaan_id : null,
            'hotel_code'             => $targetHotel ? $targetHotel->yaan_id : null,
            'qr_code_url'            => $targetHotel ? $targetHotel->qr_code_url : null,
            'qr_code_payload'        => $targetHotel ? $targetHotel->qr_code_payload : null,
            'hotel'                  => $targetHotel,
        ]);
    }
}