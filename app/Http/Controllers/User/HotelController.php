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
                       $pq->where(function($spq) {
                           $spq->where('status', 'approved')
                               ->orWhereNull('status')
                               ->orWhere('status', '!=', 'rejected');
                       });
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

            // Clamped acos formula: GREATEST(-1.0, LEAST(1.0, ...)) to prevent out-of-range SQL math errors
            $distanceSql = "(6371 * acos(
                GREATEST(-1.0, LEAST(1.0, cos(radians(?)) * cos(radians(latitude))
                * cos(radians(longitude) - radians(?))
                + sin(radians(?)) * sin(radians(latitude))))
            ))";

            $query = $this->applyApprovedScope(Hotel::query())
                ->selectRaw("*, {$distanceSql} AS distance", [$midLat, $midLng, $midLat])
                ->whereRaw("{$distanceSql} <= ?", [$midLat, $midLng, $midLat, $radius])
                ->with(['images', 'primaryImage', 'amenities', 'owner.ownerProfile']);

            if (!empty($amenities)) {
                foreach ($amenities as $amenityName) {
                    $query->whereHas('amenities', function ($q) use ($amenityName) {
                        $q->where('name', $amenityName);
                    });
                }
            }

            $hotels = $query->orderBy('distance')->get();
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

            $hotels = $query->latest()->get();
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
        $discountedPrice = max(0, $price - $discountAmount);
        $gstAmount = round($discountedPrice * 0.18, 2);
        $totalPayable = round($discountedPrice + $gstAmount, 2);

        $hotel->active_discount_percentage = $discountPct;
        $hotel->active_promo_code           = $activeUserBanner ? ($activeUserBanner->discount_code ?? $activeUserBanner->promo_code ?? null) : null;
        $hotel->banner_title                = $activeUserBanner->title ?? null;
        $hotel->banner_image                = $activeUserBanner->image_url ?? null;
        $hotel->original_price              = $price;
        $hotel->discount_amount             = $discountAmount;
        $hotel->discounted_price            = $discountedPrice;
        $hotel->gst_amount                  = $gstAmount;
        $hotel->total_payable               = $totalPayable;

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