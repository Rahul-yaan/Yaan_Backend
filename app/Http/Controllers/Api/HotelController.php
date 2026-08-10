<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Hotel;

class HotelController extends Controller
{
    /**
     * Helper scope for customer visible hotels:
     * Hotel must be approved/active AND hotel owner must be verified by admin.
     */
    private function applyApprovedScope($query)
    {
        return $query->where(function($q) {
            $q->where('status', 'approved')->orWhere('status', 'active');
        })->whereHas('owner', function($q) {
            $q->where('is_verified', true);
        });
    }

    // GET /api/hotels
    public function index(Request $request)
    {
        $query = Hotel::with(['primaryImage', 'amenities'])->withAvg('reviews', 'rating');

        $query = $this->applyApprovedScope($query);

        if ($request->city) {
            $query->where('city', 'LIKE', '%' . $request->city . '%');
        }

        return response()->json($query->get());
    }

    // GET /api/hotels/{id}
    public function show($id)
    {
        $query = Hotel::with(['reviews', 'amenities']);
        $query = $this->applyApprovedScope($query);
        $hotel = $query->findOrFail($id);

        return response()->json($hotel);
    }

    // GET /api/hotels/search
    public function search(Request $request) 
    {
        $destinationCity = $request->query('destination');
        
        $query = Hotel::with(['primaryImage', 'amenities'])->withAvg('reviews', 'rating');
        $query = $this->applyApprovedScope($query);

        if ($destinationCity) {
            $query->where('city', 'LIKE', '%' . $destinationCity . '%');
        }

        $hotels = $query->get();
        
        return response()->json([
            'success' => true,
            'data'    => $hotels
        ]);
    }
}