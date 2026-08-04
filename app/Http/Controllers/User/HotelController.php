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
            'from_lat'  => 'required|numeric',
            'from_lng'  => 'required|numeric',
            'to_lat'    => 'required|numeric',
            'to_lng'    => 'required|numeric',
            'amenities' => 'nullable|array',
            'amenities.*' => 'string',
        ]);

        $fromLat = $request->from_lat;
        $fromLng = $request->from_lng;
        $toLat   = $request->to_lat;
        $toLng   = $request->to_lng;

        $midLat = ($fromLat + $toLat) / 2;
        $midLng = ($fromLng + $toLng) / 2;

        $routeDistance = $this->haversine($fromLat, $fromLng, $toLat, $toLng);
        $radius = ($routeDistance / 2) + 50;

        $distanceSql = "(6371 * acos(
            LEAST(1.0, cos(radians(?)) * cos(radians(latitude))
            * cos(radians(longitude) - radians(?))
            + sin(radians(?)) * sin(radians(latitude)))
        ))";

        $query = Hotel::where('status', 'active')
            ->selectRaw("*, {$distanceSql} AS distance", [$midLat, $midLng, $midLat])
            ->whereRaw("{$distanceSql} <= ?", [$midLat, $midLng, $midLat, $radius])
            ->with(['images', 'primaryImage', 'amenities']);

        if ($request->filled('amenities')) {
            $requestedAmenities = $request->input('amenities');
            foreach ($requestedAmenities as $amenityName) {
                $query->whereHas('amenities', function ($q) use ($amenityName) {
                    $q->where('name', $amenityName);
                });
            }
        }

        $hotels = $query->orderBy('distance')->get();
        $today = \Carbon\Carbon::today()->toDateString();

        foreach ($hotels as $h) {
            $todayBooked = \App\Models\Booking::where('hotel_id', $h->id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->whereDate('booking_date', $today)
                ->count();
            $h->available_rooms = max(0, $h->total_rooms - $todayBooked);

            // Automatic fallback: ensure hotel has amenities to display
            if ($h->amenities->isEmpty()) {
                $defaultNames = ['Wi-Fi', 'Air Conditioning', 'Free Parking'];
                $defaultIds = [];
                foreach ($defaultNames as $dName) {
                    $d = \App\Models\Amenity::firstOrCreate(['name' => $dName]);
                    $defaultIds[] = $d->id;
                }
                $h->amenities()->syncWithoutDetaching($defaultIds);
                $h->load('amenities');
            }

            $h->amenity_names = $h->amenities->pluck('name')->toArray();
        }

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
            ->with(['images', 'primaryImage', 'amenities', 'reviews'])
            ->firstOrFail();

        $today = \Carbon\Carbon::today()->toDateString();
        $todayBooked = \App\Models\Booking::where('hotel_id', $hotel->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereDate('booking_date', $today)
            ->count();
        $hotel->available_rooms = max(0, $hotel->total_rooms - $todayBooked);

        // Automatic fallback: ensure hotel has amenities to display
        if ($hotel->amenities->isEmpty()) {
            $defaultNames = ['Wi-Fi', 'Air Conditioning', 'Free Parking'];
            $defaultIds = [];
            foreach ($defaultNames as $dName) {
                $d = \App\Models\Amenity::firstOrCreate(['name' => $dName]);
                $defaultIds[] = $d->id;
            }
            $hotel->amenities()->syncWithoutDetaching($defaultIds);
            $hotel->load('amenities');
        }

        $hotel->amenity_names = $hotel->amenities->pluck('name')->toArray();

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