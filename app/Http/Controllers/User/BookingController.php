<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Hotel;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class BookingController extends Controller
{
    // ============================================================
    // 1. CREATE BOOKING
    //    URL:    POST /api/bookings
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function store(Request $request)
    {
        $request->validate([
            'hotel_id'         => 'required|exists:hotels,id',
            'booking_date'     => 'required|date|after_or_equal:today',
            'truck_type'       => 'required|string',
            'truck_no'         => 'required|string',
            'logistics_name'   => 'required|string',
            'logistics_number' => 'required|string',
            'payment_method'   => 'required|string',
        ]);

        $hotel = Hotel::where('id', $request->hotel_id)
            ->where(function($q) {
                $q->where('status', 'approved')->orWhere('status', 'active');
            })
            ->firstOrFail();

        // Check date-specific availability
        $bookedForDate = Booking::where('hotel_id', $hotel->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereDate('booking_date', $request->booking_date)
            ->count();

        $availableForDate = max(0, $hotel->total_rooms - $bookedForDate);

        if ($availableForDate < 1) {
            return response()->json([
                'error' => 'No parking slots available for the selected date.',
            ], 422);
        }

        $price = $hotel->price_per_night;
        $gstAmount = $price * 0.18;
        $totalPayable = $price + $gstAmount;

        $rawPayment = strtolower(trim($request->payment_method ?? 'online'));
        $isOfflinePayment = in_array($rawPayment, ['cash', 'pay_at_hotel', 'pay at hotel', 'offline']);
        $isOnlinePayment = !$isOfflinePayment;

        $tempTxnId = 'TMP-' . strtoupper(\Illuminate\Support\Str::random(8)) . '-' . time();

        $booking = Booking::create([
            'user_id'             => $request->user()->id,
            'hotel_id'            => $request->hotel_id,
            'booking_date'        => $request->booking_date,
            'check_in'            => $request->booking_date, // Defaulting check_in to avoid DB constraint error
            'check_out'           => \Carbon\Carbon::parse($request->booking_date)->addDay()->toDateString(), // Default 1 day
            'total_nights'        => 1, // Default 1 night
            'truck_type'          => $request->truck_type,
            'truck_no'            => $request->truck_no,
            'logistics_name'      => $request->logistics_name,
            'logistics_number'    => $request->logistics_number,
            'payment_method'      => $isOnlinePayment ? 'Online Payment' : $request->payment_method,
            'temp_transaction_id' => $tempTxnId,
            
            'price_per_night'  => $price,
            'total_amount'     => $price,
            'promotion_applied'=> 0.00,
            'gst_amount'       => $gstAmount,
            'total_payable'    => $totalPayable,
            
            'status'           => 'pending',
            'payment_status'   => 'pending',
        ]);

        // Decrease available rooms
        $hotel->decrement('available_rooms');

        $razorpayOrderId = null;

        // Generate Razorpay Order if Online Payment
        if ($isOnlinePayment) {
            $razorpayKeyId = config('services.razorpay.key_id') ?? env('RAZORPAY_KEY_ID');
            $razorpayKeySecret = config('services.razorpay.key_secret') ?? env('RAZORPAY_KEY_SECRET');

            try {
                $response = Http::withBasicAuth($razorpayKeyId, $razorpayKeySecret)
                    ->post('https://api.razorpay.com/v1/orders', [
                        'amount'   => (int) round($totalPayable * 100), // in paise
                        'currency' => 'INR',
                        'receipt'  => 'booking_' . $booking->id,
                    ]);

                if ($response->successful()) {
                    $razorpayOrderId = $response->json('id');
                    $booking->razorpay_order_id = $razorpayOrderId;
                    $booking->save();
                } else {
                    $errorDesc = $response->json('error.description') ?? 'Authentication failed';
                    \Illuminate\Support\Facades\Log::error('Razorpay Order Creation Failed', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);

                    // Rollback room decrement & delete pending booking
                    $hotel->increment('available_rooms');
                    $booking->delete();

                    return response()->json([
                        'error'   => 'Razorpay Error: ' . $errorDesc,
                        'message' => 'Your Razorpay Key ID or Secret is invalid. Please update RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET in server environment.',
                    ], 400);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Razorpay Order Exception: ' . $e->getMessage());

                $hotel->increment('available_rooms');
                $booking->delete();

                return response()->json([
                    'error'   => 'Razorpay Exception',
                    'message' => $e->getMessage(),
                ], 400);
            }
        }

        return response()->json([
            'success'           => true,
            'is_online'         => $isOnlinePayment,
            'message'           => 'Booking created successfully. Proceed to payment.',
            'booking'           => $booking->load('hotel'),
            'order_id'          => $booking->razorpay_order_id,
            'razorpay_order_id' => $booking->razorpay_order_id,
            'key'               => config('services.razorpay.key_id') ?? env('RAZORPAY_KEY_ID'),
            'razorpay_key_id'   => config('services.razorpay.key_id') ?? env('RAZORPAY_KEY_ID'),
            'amount'            => (int) round($totalPayable * 100),
            'amount_in_paise'   => (int) round($totalPayable * 100),
            'amount_in_rupees'  => (float) $totalPayable,
            'currency'          => 'INR',
        ], 201);
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
                'error' => 'Booking is already cancelled.',
            ], 422);
        }

        if ($booking->status === 'completed') {
            return response()->json([
                'error' => 'Cannot cancel a completed booking.',
            ], 422);
        }

        $reason = $request->input('cancellation_reason') ?? $request->input('reason') ?? 'User cancelled payment / Internet slow';

        $booking->update([
            'status'              => 'cancelled',
            'cancellation_reason' => $reason,
        ]);

        // Restore available rooms
        $booking->hotel->increment('available_rooms');

        return response()->json([
            'message' => 'Booking cancelled successfully.',
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
            'transaction_id'      => 'nullable|string',
        ]);

        $booking = Booking::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Verify signature
        $generatedSignature = hash_hmac(
            'sha256',
            $request->razorpay_order_id . '|' . $request->razorpay_payment_id,
            env('RAZORPAY_KEY_SECRET')
        );

        if ($generatedSignature === $request->razorpay_signature) {
            $transactionId = $request->input('transaction_id') ?? $request->razorpay_payment_id;
            $booking->update([
                'payment_status' => 'paid',
                'razorpay_payment_id' => $request->razorpay_payment_id,
                'transaction_id' => $transactionId,
                'status' => 'confirmed'
            ]);

            return response()->json([
                'message' => 'Payment verified successfully',
                'booking' => $booking
            ]);
        }

        return response()->json([
            'error' => 'Payment verification failed'
        ], 400);
    }
}