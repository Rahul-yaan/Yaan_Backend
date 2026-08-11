<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $query = Booking::with(['user:id,name,email,phone', 'hotel:id,name,city,address']);

        if ($request->has('status') && !empty($request->status)) {
            $st = strtolower($request->status);
            if ($st === 'refunded' || $st === 'refund_initiated' || $st === 'refund') {
                $query->where(function($q) {
                    $q->whereIn('payment_status', ['refunded', 'refund_initiated'])
                      ->orWhere('cancellation_reason', 'like', '%refund%');
                });
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->has('payment_status') && !empty($request->payment_status)) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                  ->orWhere('razorpay_order_id', 'like', "%{$search}%")
                  ->orWhereHas('user', function($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                  })
                  ->orWhereHas('hotel', function($h) use ($search) {
                      $h->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $bookings = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json($bookings);
    }

    public function show($id)
    {
        $booking = Booking::with(['user', 'hotel.owner'])->findOrFail($id);
        return response()->json(['booking' => $booking]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status'         => 'required|in:pending,confirmed,completed,cancelled',
            'payment_status' => 'nullable|in:pending,paid,failed,refunded',
        ]);

        $booking = Booking::findOrFail($id);
        $booking->status = $request->status;
        if ($request->has('payment_status')) {
            $booking->payment_status = $request->payment_status;
        }
        $booking->save();

        return response()->json([
            'message' => "Booking status updated successfully.",
            'booking' => $booking,
        ]);
    }
}
