<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use Illuminate\Http\Request;

class HotelController extends Controller
{
    private function applyApprovedScope($query)
    {
        return $query->whereIn('status', ['active', 'approved'])
            ->whereHas('owner', function($oq) {
                $oq->whereRaw('is_verified IS TRUE')
                   ->whereHas('ownerProfile', function($pq) {
                       $pq->where('status', 'approved');
                   });
            });
    }

    public function search(Request $request)
    {
        // 1. Normalize Amenities input (can be array, comma-separated string, or JSON string)
        $rawAmenities = $request->input('amenities');
        $amenities = [];
        if (!empty($rawAmenities)) {
            if (is_array($rawAmenities)) {
                $amenities = $rawAmenities;
            } elseif (is_string($rawAmenities)) {
                $decoded = json_decode($rawAmenities, true);
                if (is_array($decoded)) {
                    $amenities = $decoded;
                } else {
                    $amenities = array_filter(array_map('trim', explode(',', $rawAmenities)));
                }
            }
        }

        // 2. Extract Latitude & Longitude parameters flexibly (snake_case, camelCase, origin/dest)
        $fromLat = $request->input('from_lat') ?? $request->input('fromLat') ?? $request->input('origin_lat') ?? $request->input('pickup_lat');
        $fromLng = $request->input('from_lng') ?? $request->input('fromLng') ?? $request->input('origin_lng') ?? $request->input('pickup_lng');
        $toLat   = $request->input('to_lat')   ?? $request->input('toLat')   ?? $request->input('dest_lat')   ?? $request->input('drop_lat');
        $toLng   = $request->input('to_lng')   ?? $request->input('toLng')   ?? $request->input('dest_lng')   ?? $request->input('drop_lng');

        // 3. Determine Sort Order Direction (forward: Origin -> Destination, reverse: Destination -> Origin)
        $sortParam = strtolower(trim($request->input('sort') ?? $request->input('sort_order') ?? $request->input('order') ?? $request->input('direction') ?? $request->input('sort_by') ?? 'asc'));
        $isReverse = in_array($sortParam, ['desc', 'reverse', 'destination_first', 'to_first', 'reverse_flow']);

        $hasCoords = is_numeric($fromLat) && is_numeric($fromLng) && is_numeric($toLat) && is_numeric($toLng);

        if ($hasCoords) {
            $fromLat = (float) $fromLat;
            $fromLng = (float) $fromLng;
            $toLat   = (float) $toLat;
            $toLng   = (float) $toLng;

            $midLat = ($fromLat + $toLat) / 2;
            $midLng = ($fromLng + $toLng) / 2;

            $routeDistance = $this->haversine($fromLat, $fromLng, $toLat, $toLng);
            $radius = ($routeDistance / 2) + 50;

            // Distance SQL relative to Origin (From location, e.g., Bharuch)
            $fromDistSql = "(6371 * acos(
                GREATEST(-1.0, LEAST(1.0, cos(radians(?)) * cos(radians(latitude))
                * cos(radians(longitude) - radians(?))
                + sin(radians(?)) * sin(radians(latitude))))
            ))";

            $query = $this->applyApprovedScope(Hotel::query())
                ->selectRaw("*, {$fromDistSql} AS distance", [$fromLat, $fromLng, $fromLat])
                ->whereRaw("{$fromDistSql} <= ?", [$fromLat, $fromLng, $fromLat, $radius + 50])
                ->with(['images', 'primaryImage', 'amenities', 'owner.ownerProfile']);

            if (!empty($amenities)) {
                foreach ($amenities as $amenityName) {
                    $query->whereHas('amenities', function ($q) use ($amenityName) {
                        $q->where('name', $amenityName);
                    });
                }
            }

            // Order by distance from origin: ASC = Forward (Bharuch -> Vadodara), DESC = Reverse (Vadodara -> Bharuch)
            $hotels = $query->orderBy('distance', $isReverse ? 'desc' : 'asc')->get();
        } else {
            $query = $this->applyApprovedScope(Hotel::query())
                ->with(['images', 'primaryImage', 'amenities', 'owner.ownerProfile']);

            $fromCity = $request->input('from_city') ?? $request->input('from');
            $toCity   = $request->input('to_city')   ?? $request->input('to') ?? $request->input('destination') ?? $request->input('city') ?? $request->input('location') ?? $request->input('search');

            if (!empty($fromCity) || !empty($toCity)) {
                $query->where(function($q) use ($fromCity, $toCity) {
                    if (!empty($fromCity)) {
                        $q->orWhere('city', 'ILIKE', '%' . $fromCity . '%')
                          ->orWhere('address', 'ILIKE', '%' . $fromCity . '%');
                    }
                    if (!empty($toCity)) {
                        $q->orWhere('city', 'ILIKE', '%' . $toCity . '%')
                          ->orWhere('name', 'ILIKE', '%' . $toCity . '%')
                          ->orWhere('address', 'ILIKE', '%' . $toCity . '%');
                    }
                });
            }

            if (!empty($amenities)) {
                foreach ($amenities as $amenityName) {
                    $query->whereHas('amenities', function ($q) use ($amenityName) {
                        $q->where('name', $amenityName);
                    });
                }
            }

            $hotels = $isReverse ? $query->oldest()->get() : $query->latest()->get();
            $fromLat = $fromLng = $toLat = $toLng = $midLat = $midLng = 0;
            $routeDistance = $radius = 0;
        }

        $today = \Carbon\Carbon::today()->toDateString();

        foreach ($hotels as $h) {
            $h->ensurePrimaryImageExists();

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
            $this->attachActiveDiscountInfo($h);
        }

        return response()->json([
            'hotels'          => $hotels,
            'from'            => ['lat' => $fromLat, 'lng' => $fromLng],
            'to'              => ['lat' => $toLat,   'lng' => $toLng],
            'midpoint'        => ['lat' => $midLat,  'lng' => $midLng],
            'route_km'        => round($routeDistance, 2),
            'search_radius'   => round($radius, 2),
            'sort_order'      => $isReverse ? 'reverse' : 'forward',
            'is_reversed'     => $isReverse,
            'available_sorts' => [
                'forward' => 'Bharuch to Vadodara (Start to Destination)',
                'reverse' => 'Vadodara to Bharuch (Destination to Start)',
            ],
        ]);
    }

    public function show($id)
    {
        $query = $this->applyApprovedScope(Hotel::query())
            ->where('id', $id)
            ->with(['images', 'primaryImage', 'amenities', 'reviews.user:id,name,email', 'owner.ownerProfile']);

        $hotel = $query->firstOrFail();

        $hotel->ensurePrimaryImageExists();
        $this->attachActiveDiscountInfo($hotel);

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

    /**
     * Attach Active Offer Banner Discount Information to Hotel Object
     */
    private function attachActiveDiscountInfo($hotel)
    {
        $activeUserBanner = \App\Models\Banner::whereRaw('is_active IS TRUE')
            ->whereIn('target_audience', ['user', 'all'])
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>=', now());
            })
            ->where('discount_percentage', '>', 0)
            ->latest()
            ->first();

        $discountPct = $activeUserBanner ? (float) $activeUserBanner->discount_percentage : 0;
        $price = (float) $hotel->price_per_night;

        $discountAmount = round($price * ($discountPct / 100), 2);
        $totalPayable = max(0, $price - $discountAmount);
        $baseRoomPrice = round($totalPayable / 1.18, 2);
        $gstAmount = round($totalPayable - $baseRoomPrice, 2);

        $hotel->active_discount_percentage = $discountPct;
        $hotel->active_promo_code           = $activeUserBanner ? ($activeUserBanner->discount_code ?? $activeUserBanner->promo_code ?? null) : null;
        $hotel->banner_title                = $activeUserBanner->title ?? null;
        $hotel->banner_image                = $activeUserBanner->image_url ?? null;
        $hotel->original_price              = $baseRoomPrice;
        $hotel->base_price                  = $baseRoomPrice;
        $hotel->price_per_night             = $baseRoomPrice;
        $hotel->price                       = $baseRoomPrice;
        $hotel->discount_amount             = $discountAmount;
        $hotel->discounted_price            = $baseRoomPrice;
        $hotel->gst_amount                  = $gstAmount;
        $hotel->total_payable               = $totalPayable;
        $hotel->display_total_payable       = $totalPayable;

        return $hotel;
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