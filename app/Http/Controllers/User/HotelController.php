<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use Illuminate\Http\Request;

class HotelController extends Controller
{
    public function search(Request $request)
    {
        $request->validate([
            'from_lat' => 'required|numeric',
            'from_lng' => 'required|numeric',
            'to_lat'   => 'required|numeric',
            'to_lng'   => 'required|numeric',
        ]);

        $fromLat = $request->from_lat;
        $fromLng = $request->from_lng;
        $toLat   = $request->to_lat;
        $toLng   = $request->to_lng;

        $midLat = ($fromLat + $toLat) / 2;
        $midLng = ($fromLng + $toLng) / 2;

        $routeDistance = $this->haversine($fromLat, $fromLng, $toLat, $toLng);
        $radius = ($routeDistance / 2) + 50;

        $hotels = Hotel::where('status', 'active')
            ->selectRaw("
                *,
                (6371 * acos(
                    LEAST(1.0, cos(radians(?)) * cos(radians(latitude))
                    * cos(radians(longitude) - radians(?))
                    + sin(radians(?)) * sin(radians(latitude)))
                )) AS distance
            ", [$midLat, $midLng, $midLat])
            ->having('distance', '<=', $radius)
            ->orderBy('distance')
            ->with(['primaryImage', 'amenities'])
            ->get();

        return response()->json([
            'hotels'        => $hotels,
            'from'          => ['lat' => $fromLat, 'lng' => $fromLng],
            'to'            => ['lat' => $toLat,   'lng' => $toLng],
            'midpoint'      => ['lat' => $midLat,  'lng' => $midLng],
            'route_km'      => round($routeDistance, 2),
            'search_radius' => round($radius, 2),
        ]);
    }

    public function show($id)
    {
        $hotel = Hotel::where('id', $id)
            ->where('status', 'active')
            ->with(['images', 'amenities', 'reviews'])
            ->firstOrFail();

        return response()->json(['hotel' => $hotel]);
    }

    private function haversine($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}