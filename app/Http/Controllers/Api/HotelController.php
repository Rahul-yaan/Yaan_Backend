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
        return $query->where('status', 'approved')
            ->whereHas('owner', function($q) {
                $q->whereRaw('("is_verified" = true OR "is_verified" IS TRUE)');
            });
    }

    // GET /api/hotels
    public function index(Request $request)
    {
        $query = Hotel::with(['images', 'primaryImage', 'amenities', 'owner.ownerProfile'])->withAvg('reviews', 'rating');

        $query = $this->applyApprovedScope($query);

        if ($request->city) {
            $query->where('city', 'LIKE', '%' . $request->city . '%');
        }

        $hotels = $query->get();
        foreach ($hotels as $h) {
            $h->ensurePrimaryImageExists();
        }

        return response()->json($hotels);
    }

    // GET /api/hotels/{id}
    public function show($id)
    {
        $query = Hotel::with(['images', 'primaryImage', 'reviews', 'amenities', 'owner.ownerProfile']);
        $query = $this->applyApprovedScope($query);
        $hotel = $query->findOrFail($id);
        $hotel->ensurePrimaryImageExists();

        return response()->json($hotel);
    }

    // GET /api/hotels/search
    public function search(Request $request) 
    {
        $destinationCity = $request->query('destination') ?? $request->query('city') ?? $request->query('search');
        
        $query = Hotel::with(['images', 'primaryImage', 'amenities', 'owner.ownerProfile'])->withAvg('reviews', 'rating');
        $query = $this->applyApprovedScope($query);

        if ($destinationCity) {
            $query->where('city', 'LIKE', '%' . $destinationCity . '%');
        }

        $hotels = $query->get();
        foreach ($hotels as $h) {
            $h->ensurePrimaryImageExists();
        }
        
        return response()->json([
            'success' => true,
            'data'    => $hotels
        ]);
    }
}