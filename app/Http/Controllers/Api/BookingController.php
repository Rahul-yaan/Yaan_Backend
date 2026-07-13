<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking; 

class BookingController extends Controller
{

public function index(Request $request)
{
    return Booking::with('hotel')->get();
}

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

    $hotel = \App\Models\Hotel::where('id', $request->hotel_id)
        ->where('status', 'active')
        ->firstOrFail();

    if ($hotel->available_rooms < 1) {
        return response()->json([
            'error' => 'No parking slots available for this location.',
        ], 422);
    }

    $price = $hotel->price_per_night;
    $gstAmount = $price * 0.18;
    $totalPayable = $price + $gstAmount;

    $booking = Booking::create([
        'user_id'          => $request->user() ? $request->user()->id : 1, // Fallback if used without auth
        'hotel_id'         => $request->hotel_id,
        'booking_date'     => $request->booking_date,
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

    $hotel->decrement('available_rooms');

    return response()->json([
        'message' => 'Booking Confirmed',
        'booking' => $booking->load('hotel')
    ], 201);
}
}

