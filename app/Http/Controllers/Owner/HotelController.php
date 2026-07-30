<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelImage;
use App\Models\Amenity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HotelController extends Controller
{
    // ============================================================
    // 1. GET ALL MY HOTELS
    //    URL:    GET /api/owner/hotels
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function index(Request $request)
    {
        $hotels = Hotel::where('owner_id', $request->user()->id)
            ->with(['primaryImage', 'amenities'])
            ->get();

        return response()->json(['hotels' => $hotels]);
    }

    // Helper to resolve amenities from IDs, names, objects, or strings
    private function resolveAmenities($amenitiesInput)
    {
        if (is_string($amenitiesInput)) {
            $decoded = json_decode($amenitiesInput, true);
            if (is_array($decoded)) {
                $amenitiesInput = $decoded;
            } else {
                $amenitiesInput = array_map('trim', explode(',', $amenitiesInput));
            }
        }

        if (!is_array($amenitiesInput)) {
            return [];
        }

        $resolvedIds = [];
        foreach ($amenitiesInput as $item) {
            if (is_array($item)) {
                if (isset($item['id']) && is_numeric($item['id']) && (int)$item['id'] > 0) {
                    $item = (int)$item['id'];
                } elseif (isset($item['name']) && !empty(trim($item['name']))) {
                    $item = trim($item['name']);
                } elseif (isset($item['title']) && !empty(trim($item['title']))) {
                    $item = trim($item['title']);
                }
            }

            if (is_numeric($item) && (int)$item > 0) {
                $id = (int)$item;
                if (Amenity::where('id', $id)->exists()) {
                    $resolvedIds[] = $id;
                }
            } elseif (is_string($item) && !empty(trim($item))) {
                $name = trim($item);
                $amenity = Amenity::firstOrCreate(['name' => $name]);
                $resolvedIds[] = $amenity->id;
            }
        }

        return array_values(array_unique($resolvedIds));
    }

    private function extractAndResolveAmenities(Request $request)
    {
        $input = $request->input('amenities')
              ?? $request->input('amenity_ids')
              ?? $request->input('amenities_ids')
              ?? $request->input('selected_amenities')
              ?? $request->input('selectedAmenities')
              ?? $request->input('amenity_names')
              ?? $request->input('amenities_list');

        if ($input === null) {
            return null;
        }

        return $this->resolveAmenities($input);
    }

    // ============================================================
    // 2. ADD HOTEL
    //    URL:    POST /api/owner/hotels
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function store(Request $request)
    {
        $resolvedAmenities = $this->extractAndResolveAmenities($request);
        if ($resolvedAmenities !== null) {
            $request->merge(['amenities' => $resolvedAmenities]);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name'           => 'required|string|max:200',
            'description'    => 'nullable|string',
            'city'           => 'required|string',
            'address'        => 'required|string',
            'latitude'       => 'required|numeric',
            'longitude'      => 'required|numeric',
            'price_per_night'=> 'required|numeric|min:1',
            'total_rooms'    => 'required|integer|min:1',
            'amenities'      => 'nullable|array',
            'amenities.*'    => 'exists:amenities,id',
        ]);

        if ($validator->fails()) {
            \Illuminate\Support\Facades\Log::warning('Add Hotel Validation Failed', [
                'input'  => $request->all(),
                'errors' => $validator->errors()->toArray(),
            ]);

            return response()->json([
                'error'   => 'Validation failed.',
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $hotel = Hotel::create([
            'owner_id'       => $request->user()->id,
            'name'           => $request->name,
            'description'    => $request->description,
            'city'           => $request->city,
            'address'        => $request->address,
            'latitude'       => $request->latitude,
            'longitude'      => $request->longitude,
            'price_per_night'=> $request->price_per_night,
            'total_rooms'    => $request->total_rooms,
            'available_rooms'=> $request->total_rooms,
            'status'         => 'active',
        ]);

        // Attach amenities if provided
        if (!empty($resolvedAmenities)) {
            $hotel->amenities()->attach($resolvedAmenities);
        }

        return response()->json([
            'message' => 'Hotel added successfully.',
            'hotel'   => $hotel->load('amenities'),
        ], 201);
    }

    // ============================================================
    // 3. UPDATE HOTEL
    //    URL:    PUT /api/owner/hotels/{id}
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function update(Request $request, $id)
    {
        $hotel = Hotel::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->firstOrFail();

        $resolvedAmenities = $this->extractAndResolveAmenities($request);
        if ($resolvedAmenities !== null) {
            $request->merge(['amenities' => $resolvedAmenities]);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name'           => 'sometimes|string|max:200',
            'description'    => 'nullable|string',
            'city'           => 'sometimes|string',
            'address'        => 'sometimes|string',
            'latitude'       => 'sometimes|numeric',
            'longitude'      => 'sometimes|numeric',
            'price_per_night'=> 'sometimes|numeric|min:1',
            'total_rooms'    => 'sometimes|integer|min:1',
            'status'         => 'sometimes|in:active,inactive',
            'amenities'      => 'nullable|array',
            'amenities.*'    => 'exists:amenities,id',
        ]);

        if ($validator->fails()) {
            \Illuminate\Support\Facades\Log::warning('Update Hotel Validation Failed', [
                'input'  => $request->all(),
                'errors' => $validator->errors()->toArray(),
            ]);

            return response()->json([
                'error'   => 'Validation failed.',
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $updateData = $request->only([
            'name', 'description', 'city', 'address',
            'latitude', 'longitude', 'price_per_night',
            'total_rooms', 'status',
        ]);

        if (isset($updateData['total_rooms'])) {
            $today = \Carbon\Carbon::today()->toDateString();
            $todayBookingsCount = $hotel->bookings()
                ->whereIn('status', ['pending', 'confirmed'])
                ->whereDate('booking_date', $today)
                ->count();
            $updateData['available_rooms'] = max(0, (int)$updateData['total_rooms'] - $todayBookingsCount);
        }

        $hotel->update($updateData);

        if ($resolvedAmenities !== null) {
            $hotel->amenities()->sync($resolvedAmenities);
        }

        return response()->json([
            'message' => 'Hotel updated successfully.',
            'hotel'   => $hotel->load('amenities'),
        ]);
    }

    // ============================================================
    // 4. DELETE HOTEL
    //    URL:    DELETE /api/owner/hotels/{id}
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function destroy(Request $request, $id)
    {
        $hotel = Hotel::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->firstOrFail();

        // Delete images from storage
        foreach ($hotel->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        $hotel->delete();

        return response()->json(['message' => 'Hotel deleted successfully.']);
    }

    // ============================================================
    // 5. UPLOAD HOTEL IMAGES
    //    URL:    POST /api/owner/hotels/{id}/images
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function uploadImages(Request $request, $id)
    {
        $hotel = Hotel::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->firstOrFail();

        $request->validate([
            'images'   => 'required|array|max:5',
            'images.*' => 'image|mimes:jpg,jpeg,png|max:2048',
        ]);

        $uploaded = [];

        // Make the newly uploaded first image the primary one
        $hotel->images()->update(['is_primary' => false]);

        foreach ($request->file('images') as $index => $file) {
            $path = $file->store('hotels', 'public');

            $isPrimary = $index === 0;

            $image = HotelImage::create([
                'hotel_id'   => $hotel->id,
                'image_path' => $path,
                'is_primary' => $isPrimary,
            ]);

            $uploaded[] = $image;
        }

        return response()->json([
            'message' => 'Images uploaded successfully.',
            'images'  => $uploaded,
        ]);
    }
}