<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Banner;
use App\Models\Booking;
use App\Support\BusinessDate;
use Illuminate\Http\Request;

class HotelController extends Controller
{
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

        // 2. Extract Latitude & Longitude parameters flexibly
        $fromLat = $request->input('from_lat') ?? $request->input('fromLat') ?? $request->input('origin_lat') ?? $request->input('pickup_lat');
        $fromLng = $request->input('from_lng') ?? $request->input('fromLng') ?? $request->input('origin_lng') ?? $request->input('pickup_lng');
        $toLat   = $request->input('to_lat')   ?? $request->input('toLat')   ?? $request->input('dest_lat')   ?? $request->input('drop_lat');
        $toLng   = $request->input('to_lng')   ?? $request->input('toLng')   ?? $request->input('dest_lng')   ?? $request->input('drop_lng');

        $sortParam = strtolower(trim($request->input('sort') ?? $request->input('sort_order') ?? $request->input('order') ?? $request->input('direction') ?? $request->input('sort_by') ?? 'asc'));
        $isReverse = in_array($sortParam, ['desc', 'reverse', 'destination_first', 'to_first', 'reverse_flow']);

        $hasCoords = is_numeric($fromLat) && is_numeric($fromLng) && is_numeric($toLat) && is_numeric($toLng);

        // Security: customer queries never load owner or ownerProfile KYC relations
        if ($hasCoords) {
            $fromLat = (float) $fromLat;
            $fromLng = (float) $fromLng;
            $toLat   = (float) $toLat;
            $toLng   = (float) $toLng;

            $routeDistance = $this->haversine($fromLat, $fromLng, $toLat, $toLng);
            $radius = ($routeDistance / 2) + 50;

            $fromDistSql = "(6371 * acos(
                GREATEST(-1.0, LEAST(1.0, cos(radians(?)) * cos(radians(latitude))
                * cos(radians(longitude) - radians(?))
                + sin(radians(?)) * sin(radians(latitude))))
            ))";

            $query = Hotel::bookable()
                ->selectRaw("*, {$fromDistSql} AS distance", [$fromLat, $fromLng, $fromLat])
                ->whereRaw("{$fromDistSql} <= ?", [$fromLat, $fromLng, $fromLat, $radius + 50])
                ->with(['images', 'primaryImage', 'amenities']);

            if (!empty($amenities)) {
                foreach ($amenities as $amenityName) {
                    $query->whereHas('amenities', function ($q) use ($amenityName) {
                        $q->where('name', $amenityName);
                    });
                }
            }

            if (in_array($sortParam, ['price_low', 'price_asc', 'price_low_to_high'])) {
                $hotels = $query->orderBy('price_per_night', 'asc')->get();
            } elseif (in_array($sortParam, ['price_high', 'price_desc', 'price_high_to_low'])) {
                $hotels = $query->orderBy('price_per_night', 'desc')->get();
            } elseif (in_array($sortParam, ['rating', 'rating_desc', 'top_rated'])) {
                $hotels = $query->orderBy('rating', 'desc')->get();
            } else {
                $hotels = $query->orderBy('distance', $isReverse ? 'desc' : 'asc')->get();
            }
        } else {
            $query = Hotel::bookable()->with(['images', 'primaryImage', 'amenities']);

            $fromCity = $request->input('from_city') ?? $request->input('from');
            $toCity   = $request->input('to_city')   ?? $request->input('to') ?? $request->input('destination') ?? $request->input('city') ?? $request->input('location') ?? $request->input('search');

            if ($fromCity && $toCity) {
                $query->where(function ($q) use ($fromCity, $toCity) {
                    $q->where('city', 'LIKE', "%{$fromCity}%")
                      ->orWhere('city', 'LIKE', "%{$toCity}%")
                      ->orWhere('address', 'LIKE', "%{$fromCity}%")
                      ->orWhere('address', 'LIKE', "%{$toCity}%");
                });
            } elseif ($toCity) {
                $query->where(function ($q) use ($toCity) {
                    $q->where('city', 'LIKE', "%{$toCity}%")
                      ->orWhere('address', 'LIKE', "%{$toCity}%")
                      ->orWhere('name', 'LIKE', "%{$toCity}%");
                });
            }

            if (!empty($amenities)) {
                foreach ($amenities as $amenityName) {
                    $query->whereHas('amenities', function ($q) use ($amenityName) {
                        $q->where('name', $amenityName);
                    });
                }
            }

            if (in_array($sortParam, ['price_low', 'price_asc', 'price_low_to_high'])) {
                $hotels = $query->orderBy('price_per_night', 'asc')->get();
            } elseif (in_array($sortParam, ['price_high', 'price_desc', 'price_high_to_low'])) {
                $hotels = $query->orderBy('price_per_night', 'desc')->get();
            } elseif (in_array($sortParam, ['rating', 'rating_desc', 'top_rated'])) {
                $hotels = $query->orderBy('rating', 'desc')->get();
            } else {
                $hotels = $query->orderBy('id', 'asc')->get();
            }

            $routeDistance = 0;
            $radius = 0;
        }

        $hotels->each(function ($hotel) {
            $hotel->ensurePrimaryImageExists();
            $this->attachActiveDiscountInfo($hotel);
        });

        return response()->json([
            'count'           => $hotels->count(),
            'hotels'          => $hotels,
            'route_km'        => round($routeDistance, 2),
            'search_radius'   => round($radius, 2),
            'sort_order'      => $isReverse ? 'reverse' : 'forward',
            'is_reversed'     => $isReverse,
        ]);
    }

    public function show($id)
    {
        $hotel = Hotel::bookable()
            ->where('id', $id)
            ->with(['images', 'primaryImage', 'amenities', 'reviews.user:id,name'])
            ->firstOrFail();

        $hotel->ensurePrimaryImageExists();
        $this->attachActiveDiscountInfo($hotel);

        $today = BusinessDate::today();
        $todayBooked = Booking::holdingSlot($hotel->id, $today)->count();
        $hotel->available_rooms = max(0, $hotel->total_rooms - $todayBooked);

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
        $activeUserBanner = Banner::where('is_active', true)
            ->whereIn('target_audience', ['user', 'all'])
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>=', now());
            })
            ->where('discount_percentage', '>', 0)
            ->latest()
            ->first();

        $discountPct = $activeUserBanner ? (float) $activeUserBanner->discount_percentage : 0;
        $basePrice = (float) $hotel->price_per_night;

        $discountAmount = round($basePrice * ($discountPct / 100), 2);
        $discountedBasePrice = max(0, $basePrice - $discountAmount);
        $gstAmount = round($discountedBasePrice * 0.18, 2);
        $totalPayable = round($discountedBasePrice + $gstAmount, 2);

        $hotel->active_discount_percentage = $discountPct;
        $hotel->active_promo_code           = $activeUserBanner ? ($activeUserBanner->discount_code ?? $activeUserBanner->promo_code ?? null) : null;
        $hotel->banner_title                = $activeUserBanner->title ?? null;
        $hotel->banner_image                = $activeUserBanner->image_url ?? null;
        $hotel->original_price              = $basePrice;
        $hotel->base_price                  = $basePrice;
        $hotel->price_per_night             = $basePrice;
        $hotel->price                       = $basePrice;
        $hotel->display_price               = $discountedBasePrice;
        $hotel->discount_amount             = $discountAmount;
        $hotel->discounted_price            = $discountedBasePrice;
        $hotel->gst_amount                  = $gstAmount;
        $hotel->total_payable               = $totalPayable;
        $hotel->amount_payable              = $totalPayable;
        $hotel->grand_total                 = $totalPayable;
    }

    private function haversine($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * QR Code Scan Endpoint for Instant Spot Booking.
     * Matches ONLY official YAAN- ID, never plain numeric database IDs.
     */
    public function scanQr(Request $request, $code = null)
    {
        $rawInput = $code 
            ?? $request->input('code') 
            ?? $request->input('qr_code') 
            ?? $request->input('qr_data') 
            ?? $request->input('hotel_code') 
            ?? $request->input('payload');

        if (empty($rawInput)) {
            return response()->json([
                'status'     => 'invalid_request',
                'registered' => false,
                'can_book'   => false,
                'error'      => 'Please provide a valid Hotel QR code or YAAN ID.',
                'message'    => 'Please provide a valid Hotel QR code or YAAN ID.',
            ], 422);
        }

        if (str_contains($rawInput, '/')) {
            $parts = explode('/', rtrim($rawInput, '/'));
            $rawInput = end($parts);
        }

        if (str_starts_with($rawInput, '{') && str_ends_with($rawInput, '}')) {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                $rawInput = $decoded['yaan_id'] ?? $decoded['hotel_code'] ?? $decoded['code'] ?? $rawInput;
            }
        }

        $cleanCode = trim((string)$rawInput);

        // Security Fix: Match ONLY yaan_id (never plain numeric database IDs)
        $hotel = Hotel::where('yaan_id', $cleanCode)
            ->with(['images', 'primaryImage', 'amenities', 'reviews.user:id,name', 'owner.ownerProfile'])
            ->first();

        if (!$hotel) {
            return response()->json([
                'status'        => 'not_found',
                'registered'    => false,
                'can_book'      => false,
                'searched_code' => $cleanCode,
                'error'         => 'Hotel not found.',
                'message'       => 'This hotel is not registered with Yaan company. Spot booking is not available for this location.',
            ], 404);
        }

        $hotel->ensurePrimaryImageExists();

        // 2. Check if hotel status is active / approved
        if (!in_array($hotel->status, ['active', 'approved'], true)) {
            $hotel->unsetRelation('owner');
            return response()->json([
                'status'     => 'inactive_hotel',
                'registered' => true,
                'can_book'   => false,
                'hotel_id'   => $hotel->id,
                'hotel_name' => $hotel->name,
                'yaan_id'    => $hotel->yaan_id,
                'hotel_code' => $hotel->yaan_id,
                'error'      => 'Hotel inactive.',
                'message'    => 'This hotel is registered with Yaan, but is currently undergoing verification or is temporarily inactive.',
            ], 403);
        }

        // 3. Check Owner KYC Approval status
        $owner = $hotel->owner;
        $ownerProfile = $owner ? $owner->ownerProfile : null;
        $isOwnerApproved = $owner && $owner->is_verified && $ownerProfile && $ownerProfile->status === 'approved';

        // Security: Unset owner relation so owner KYC documents/numbers are NEVER leaked to the customer
        $hotel->unsetRelation('owner');

        if (!$isOwnerApproved) {
            return response()->json([
                'status'     => 'kyc_pending',
                'registered' => true,
                'can_book'   => false,
                'hotel_id'   => $hotel->id,
                'hotel_name' => $hotel->name,
                'yaan_id'    => $hotel->yaan_id,
                'hotel_code' => $hotel->yaan_id,
                'error'      => 'KYC pending.',
                'message'    => 'Hotel partner KYC verification is in progress. Please contact hotel reception.',
            ], 403);
        }

        // 4. Check available rooms / parking slots for TODAY in India time
        $today = BusinessDate::today();
        $todayBooked = Booking::holdingSlot($hotel->id, $today)->count();
        $availableRooms = max(0, $hotel->total_rooms - $todayBooked);

        if ($availableRooms < 1) {
            return response()->json([
                'status'          => 'fully_booked',
                'registered'      => true,
                'can_book'        => false,
                'hotel_id'        => $hotel->id,
                'hotel_name'      => $hotel->name,
                'yaan_id'         => $hotel->yaan_id,
                'hotel_code'      => $hotel->yaan_id,
                'available_rooms' => 0,
                'error'           => 'Fully booked.',
                'message'         => 'Hotel is registered with Yaan, but all parking slots and rooms are fully booked for today.',
            ], 400);
        }

        $this->attachActiveDiscountInfo($hotel);
        $hotel->available_rooms = $availableRooms;

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

        return response()->json([
            'status'          => 'verified',
            'registered'      => true,
            'can_book'        => true,
            'message'         => 'Hotel verified! You can proceed with instant spot booking.',
            'hotel'           => $hotel,
            'today_date'      => $today,
            'check_in'        => $today,
            'check_out'       => BusinessDate::nextDay($today),
            'available_rooms' => $availableRooms,
        ]);
    }
}