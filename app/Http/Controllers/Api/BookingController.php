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
        'hotel_id' => 'required|exists:hotels,id',
        'customer_name' => 'required',
        'booking_date' => 'required|date',
        'from_time' => 'required',
        'to_time' => 'required',
    ]);

    $booking = Booking::create($request->all());

    return response()->json([
        'message' => 'Booking Confirmed',
        'data' => $booking
    ]);
}
    // POST /api/bookings
    public function store(Request $request)
    {
        $booking = Booking::create([
            'hotel_id' => $request->hotel_id,
            'customer_name' => $request->customer_name,
            'booking_date' => $request->booking_date,
            'from_time' => $request->from_time,
            'to_time' => $request->to_time,
            'total_price' => $request->total_price,
        ]);

        return response()->json([
            'message' => 'Booking Confirmed',
            'data' => $booking
        ]);
    }
}
