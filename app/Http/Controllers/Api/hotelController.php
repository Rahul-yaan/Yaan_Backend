<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Hotel;

class HotelController extends Controller
{
    // GET /api/hotels
    public function index(Request $request)
    {
        $query = Hotel::withAvg('reviews', 'rating');

        if ($request->city) {
            $query->where('city', $request->city);
        }

        return response()->json($query->get());
    }

    // GET /api/hotels/{id}
    public function show($id)
    {
        $hotel = Hotel::with('reviews')->findOrFail($id);
        return response()->json($hotel);
    }
}