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
            ->where('status', 'active')
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

        $booking = Booking::create([
            'user_id'          => $request->user()->id,
            'hotel_id'         => $request->hotel_id,
            'booking_date'     => $request->booking_date,
            'check_in'         => $request->booking_date, // Defaulting check_in to avoid DB constraint error
            'check_out'        => \Carbon\Carbon::parse($request->booking_date)->addDay()->toDateString(), // Default 1 day
            'total_nights'     => 1, // Default 1 night
            'truck_type'       => $request->truck_type,
            'truck_no'         => $request->truck_no,
            'logistics_name'   => $request->logistics_name,
            'logistics_number' => $request->logistics_number,
            'payment_method'   => $request->payment_method,
            
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

        // Generate Razorpay Order if Online Payment
        if ($booking->payment_method === 'Online Payment') {
            $razorpayKeyId = env('RAZORPAY_KEY_ID');
            $razorpayKeySecret = env('RAZORPAY_KEY_SECRET');

            $response = Http::withBasicAuth($razorpayKeyId, $razorpayKeySecret)
                ->post('https://api.razorpay.com/v1/orders', [
                    'amount' => (int) ($totalPayable * 100), // in paise
                    'currency' => 'INR',
                    'receipt' => (string) $booking->id,
                ]);

            if ($response->successful()) {
                $booking->razorpay_order_id = $response->json('id');
                $booking->save();
            }
        }

        return response()->json([
            'message' => 'Booking created successfully.',
            'booking' => $booking->load('hotel'),
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

        $booking->update([
            'status' => 'cancelled',
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