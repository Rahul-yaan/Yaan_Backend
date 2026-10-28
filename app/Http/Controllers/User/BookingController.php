<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Banner;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Razorpay\Api\Api;

class BookingController extends Controller
{
    // ============================================================
    // 1. CREATE BOOKING
    //    URL:    POST /api/bookings
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function store(Request $request)
    {
        $todayIndia = BusinessDate::today();

        $request->validate([
            'hotel_id'         => 'required|exists:hotels,id',
            'booking_date'     => 'required|date|after_or_equal:' . $todayIndia,
            'truck_type'       => 'required|string',
            'truck_no'         => 'required|string',
            'logistics_name'   => 'nullable|string|max:100',
            'logistics_number' => 'nullable|string|max:20',
            'payment_method'   => 'nullable|string',
            'discount_code'    => 'nullable|string',
            'promo_code'       => 'nullable|string',
        ]);

        $bookingDate = $request->booking_date;

        // Clean & validate Indian vehicle number format
        $cleanTruckNo = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string)$request->truck_no));
        if (strlen($cleanTruckNo) < 6 || strlen($cleanTruckNo) > 13) {
            return response()->json([
                'error'   => 'Validation failed.',
                'message' => 'Please enter a valid vehicle registration number (e.g., GJ16AB7888 or BH series).',
            ], 422);
        }

        $rawPayment = strtolower(trim($request->payment_method ?? 'online'));
        $isOfflinePayment = in_array($rawPayment, ['cash', 'pay_at_hotel', 'pay at hotel', 'offline'], true);
        $isOnlinePayment = !$isOfflinePayment;

        // Anti-fraud: Limit Pay at Hotel bookings to at most 5 per customer per date
        if ($isOfflinePayment) {
            $userPayAtHotelCount = Booking::where('user_id', $request->user()->id)
                ->whereDate('booking_date', $bookingDate)
                ->where('payment_method', 'pay_at_hotel')
                ->where('status', '!=', 'cancelled')
                ->count();

            if ($userPayAtHotelCount >= 5) {
                return response()->json([
                    'error'   => 'Booking limit reached.',
                    'message' => 'You have reached the maximum limit of 5 Pay at Hotel bookings for this date.',
                ], 422);
            }
        }

        return DB::transaction(function () use ($request, $bookingDate, $cleanTruckNo, $isOfflinePayment, $isOnlinePayment) {
            /** @var Hotel $hotel */
            $hotel = Hotel::where('id', $request->hotel_id)->lockForUpdate()->first();

            // Hotel must exist and be bookable (approved hotel, verified owner with approved profile)
            if (!$hotel || !in_array($hotel->status, ['active', 'approved'], true)) {
                return response()->json([
                    'error'   => 'Hotel not available.',
                    'message' => 'This hotel is currently undergoing verification or is inactive.',
                ], 422);
            }

            $owner = $hotel->owner;
            $ownerProfile = $owner ? $owner->ownerProfile : null;
            $isOwnerApproved = $owner && $owner->is_verified && $ownerProfile && $ownerProfile->status === 'approved';

            if (!$isOwnerApproved) {
                return response()->json([
                    'error'   => 'Hotel partner KYC pending.',
                    'message' => 'This hotel partner KYC verification is in progress. Booking cannot be accepted.',
                ], 422);
            }

            // Accurate slot calculation dynamically from active holding bookings
            $holdingCount = Booking::holdingSlot($hotel->id, $bookingDate)->count();
            $availableForDate = max(0, $hotel->total_rooms - $holdingCount);

            if ($availableForDate < 1) {
                return response()->json([
                    'error'   => 'No slots available.',
                    'message' => 'No parking slots available for the selected date.',
                ], 422);
            }

            $price = (float) $hotel->price_per_night;

            // Discount calculation: only exact promo coupon match or active automatic offer
            $discountPct = 0;
            $bannerId = null;
            $appliedCode = null;

            $promoCode = trim((string) ($request->input('discount_code') ?? $request->input('promo_code') ?? ''));
            if (!empty($promoCode)) {
                $coupon = Banner::findActiveCoupon($promoCode, 'user');
                if ($coupon) {
                    $discountPct = (float) $coupon->discount_percentage;
                    $bannerId    = $coupon->id;
                    $appliedCode = $coupon->discount_code;
                }
            }

            // Fallback to active automatic offer if no coupon code provided
            if ($discountPct <= 0) {
                $autoOffer = Banner::activeAutoOffer('user');
                if ($autoOffer) {
                    $discountPct = (float) $autoOffer->discount_percentage;
                    $bannerId    = $autoOffer->id;
                    $appliedCode = $autoOffer->discount_code;
                }
            }

            $discountAmount = $discountPct > 0 ? round($price * ($discountPct / 100), 2) : 0;
            $discountedBasePrice = max(0, $price - $discountAmount);
            $gstAmount = round($discountedBasePrice * 0.18, 2);
            $totalPayable = round($discountedBasePrice + $gstAmount, 2);

            $tempTxnId = 'TMP-' . strtoupper(Str::random(8)) . '-' . time();
            $nextDay = BusinessDate::nextDay($bookingDate);

            $booking = Booking::create([
                'user_id'             => $request->user()->id,
                'hotel_id'            => $hotel->id,
                'banner_id'           => $bannerId,
                'discount_code'       => $appliedCode,
                'booking_date'        => $bookingDate,
                'check_in'            => $bookingDate,
                'check_out'           => $nextDay,
                'total_nights'        => 1,
                'truck_type'          => $request->truck_type,
                'truck_no'            => $cleanTruckNo,
                'logistics_name'      => $request->logistics_name,
                'logistics_number'    => $request->logistics_number,
                'guests'              => 1,
                'price_per_night'     => $price,
                'total_amount'        => $totalPayable,
                'status'              => $isOfflinePayment ? 'confirmed' : 'pending',
                'payment_status'      => 'pending',
                'payment_method'      => $isOfflinePayment ? 'pay_at_hotel' : 'online',
                'promotion_applied'   => $discountAmount,
                'gst_amount'          => $gstAmount,
                'total_payable'       => $totalPayable,
                'temp_transaction_id' => $tempTxnId,
                'booking_type'        => $request->booking_type ?? 'app_booking',
            ]);

            // Recompute available rooms for hotel
            $hotel->refreshAvailableRooms($bookingDate);

            // Pay at Hotel: Instant confirmation
            if ($isOfflinePayment) {
                return response()->json([
                    'success'  => true,
                    'message'  => 'Booking confirmed successfully. Please pay upon arrival at the hotel.',
                    'booking'  => $booking->load('hotel'),
                    'order_id' => null,
                    'amount'   => (int) round($totalPayable * 100),
                ], 201);
            }

            // Online Payment: Generate Razorpay Order
            $orderId = 'order_' . Str::random(14);
            $razorpayKeyId = config('services.razorpay.key_id') ?? env('RAZORPAY_KEY_ID');
            $razorpaySecret = config('services.razorpay.key_secret') ?? env('RAZORPAY_KEY_SECRET');

            if (!empty($razorpayKeyId) && !empty($razorpaySecret) && !str_starts_with($razorpayKeyId, 'your_')) {
                try {
                    $api = new Api($razorpayKeyId, $razorpaySecret);
                    $order = $api->order->create([
                        'receipt'  => (string) $booking->id,
                        'amount'   => (int) round($totalPayable * 100),
                        'currency' => 'INR',
                        'notes'    => [
                            'booking_id' => (string) $booking->id,
                            'hotel_name' => $hotel->name,
                            'truck_no'   => $cleanTruckNo,
                        ],
                    ]);
                    $orderId = $order['id'];
                } catch (\Throwable $e) {
                    \Log::error('Razorpay order creation error: ' . $e->getMessage());
                }
            }

            $booking->update([
                'razorpay_order_id' => $orderId,
            ]);

            return response()->json([
                'success'                => true,
                'message'                => 'Booking created successfully. Proceed to payment.',
                'booking'                => $booking->load('hotel'),
                'order_id'               => $orderId,
                'razorpay_order_id'      => $orderId,
                'key'                    => $razorpayKeyId,
                'razorpay_key_id'        => $razorpayKeyId,
                'amount'                 => (int) round($totalPayable * 100),
                'amount_in_paise'        => (int) round($totalPayable * 100),
                'amount_in_rupees'       => (float) $totalPayable,
                'currency'               => 'INR',
                'payment_timeout_seconds'=> Booking::PENDING_HOLD_MINUTES * 60, // 900 seconds
            ], 201);
        });
    }

    // ============================================================
    // 2. GET MY BOOKINGS
    //    URL:    GET /api/bookings/my
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function myBookings(Request $request)
    {
        $bookings = Booking::where('user_id', $request->user()->id)
            ->with(['hotel.primaryImage'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['bookings' => $bookings]);
    }

    // ============================================================
    // 3. CANCEL BOOKING
    //    URL:    POST /api/bookings/{id}/cancel
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function cancel(Request $request, $id)
    {
        $booking = Booking::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($booking->status === 'cancelled') {
            return response()->json([
                'error'   => 'Booking is already cancelled.',
                'message' => 'Booking is already cancelled.',
            ], 422);
        }

        if ($booking->status === 'completed') {
            return response()->json([
                'error'   => 'Cannot cancel a completed booking.',
                'message' => 'Cannot cancel a completed booking.',
            ], 422);
        }

        // Confirmed bookings whose date has passed cannot be cancelled
        $todayIndia = BusinessDate::today();
        if ($booking->status === 'confirmed' && $booking->booking_date && $booking->booking_date->toDateString() < $todayIndia) {
            return response()->json([
                'error'   => 'Cannot cancel a booking whose date has passed.',
                'message' => 'Cannot cancel a booking whose date has passed.',
            ], 422);
        }

        $reason = $request->input('cancellation_reason') ?? $request->input('reason') ?? 'User cancelled booking';

        // Check if payment was made online
        $isPaid = in_array($booking->payment_status, ['paid', 'authorized'], true);

        if ($isPaid) {
            $booking->update([
                'status'              => 'cancelled',
                'payment_status'      => Booking::REFUND_PENDING,
                'cancellation_reason' => 'Cancelled by customer after payment (waiting for admin approval to return the money). Customer reason: ' . $reason,
            ]);

            $booking->hotel->refreshAvailableRooms($booking->booking_date ? $booking->booking_date->toDateString() : null);

            return response()->json([
                'message'        => 'Booking cancelled successfully. Your refund request is pending admin approval and will be processed within 5-7 business days.',
                'refund_pending' => true,
                'booking'        => $booking,
            ]);
        }

        $booking->update([
            'status'              => 'cancelled',
            'cancellation_reason' => $reason,
        ]);

        $booking->hotel->refreshAvailableRooms($booking->booking_date ? $booking->booking_date->toDateString() : null);

        return response()->json([
            'message'        => 'Booking cancelled successfully.',
            'refund_pending' => false,
            'booking'        => $booking,
        ]);
    }

    // ============================================================
    // 4. VERIFY PAYMENT
    //    URL:    POST /api/bookings/{id}/verify-payment
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function verifyPayment(Request $request, $id)
    {
        $request->validate([
            'razorpay_payment_id' => 'required|string',
            'razorpay_order_id'   => 'required|string',
            'razorpay_signature'  => 'required|string',
        ]);

        $booking = Booking::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Prevent cross-order signature replay
        if (!empty($booking->razorpay_order_id) && $booking->razorpay_order_id !== $request->razorpay_order_id) {
            return response()->json([
                'error'   => 'Razorpay Order ID mismatch for this booking.',
                'message' => 'Razorpay Order ID mismatch for this booking.',
            ], 422);
        }

        // Verify cryptographic HMAC signature
        $secret = config('services.razorpay.key_secret') ?? env('RAZORPAY_KEY_SECRET');
        $expectedSignature = hash_hmac(
            'sha256',
            $request->razorpay_order_id . '|' . $request->razorpay_payment_id,
            $secret
        );

        if (!hash_equals($expectedSignature, $request->razorpay_signature)) {
            return response()->json([
                'error'   => 'Payment verification failed.',
                'message' => 'Payment verification failed.',
            ], 400);
        }

        // Delegate to atomic slot allocation
        $confirmed = $booking->recordPayment($request->razorpay_payment_id, [
            'gateway_response' => [
                'razorpay_payment_id' => $request->razorpay_payment_id,
                'razorpay_order_id'   => $request->razorpay_order_id,
            ],
        ]);

        if (!$confirmed) {
            return response()->json([
                'success'        => false,
                'refund_pending' => true,
                'error'          => 'Payment succeeded, but your slot hold expired and the room was taken. A refund has been initiated.',
                'message'        => 'Payment succeeded, but your slot hold expired and the room was taken. A refund has been initiated and will be processed within 5-7 business days.',
                'booking'        => $booking->fresh(),
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment verified successfully.',
            'booking' => $booking->fresh()->load('hotel'),
        ]);
    }
}