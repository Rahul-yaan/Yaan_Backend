<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use Illuminate\Http\Request;

class HotelController extends Controller
{
    public function index(Request $request)
    {
        $query = Hotel::with(['owner:id,name,email,phone', 'images', 'amenities']);

        if ($request->has('status') && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $hotels = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json($hotels);
    }

    public function show($id)
    {
        $hotel = Hotel::with(['owner', 'images', 'amenities', 'reviews.user', 'bookings.user'])->findOrFail($id);
        return response()->json(['hotel' => $hotel]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,pending,rejected,suspended',
            'reason' => 'nullable|string|max:255',
        ]);

        $hotel = Hotel::findOrFail($id);
        $hotel->status = $request->status;
        $hotel->save();

        return response()->json([
            'message' => "Hotel status successfully updated to {$request->status}.",
            'hotel'   => $hotel,
        ]);
    }
}
