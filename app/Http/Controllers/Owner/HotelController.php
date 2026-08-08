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

        $idToNameMap = [
            1  => "Free WiFi",
            2  => "Air Conditioning",
            3  => "Room Service",
            4  => "Swimming Pool",
            5  => "Free Parking",
            6  => "Wifi",
            7  => "Rest Rooms",
            8  => "Fuel Stations",
            9  => "Dining Facilities",
            10 => "Comfortable Rooms",
            11 => "ATM",
            12 => "Convenience Stores",
            13 => "First Aid",
            14 => "Fitness center",
            15 => "Food Outlets",
            16 => "Showers",
            17 => "Laundry Services",
            18 => "Seating Areas",
            19 => "Men",
            20 => "Women",
        ];

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
                $amenity = Amenity::find($id);
                if ($amenity) {
                    $resolvedIds[] = $amenity->id;
                } elseif (isset($idToNameMap[$id])) {
                    $name = $idToNameMap[$id];
                    $amenity = Amenity::firstOrCreate(['name' => $name]);
                    $resolvedIds[] = $amenity->id;
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

        $existingHotel = Hotel::where('owner_id', $request->user()->id)->first();

        if ($existingHotel) {
            $existingHotel->update([
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

            if (!empty($resolvedAmenities)) {
                $existingHotel->amenities()->sync($resolvedAmenities);
            }

            $this->processUploadedImages($request, $existingHotel);

            return response()->json([
                'message' => 'Hotel details updated successfully.',
                'hotel'   => $existingHotel->load(['images', 'primaryImage', 'amenities']),
            ], 200);
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

        // Process any uploaded images sent during hotel creation
        $this->processUploadedImages($request, $hotel);

        return response()->json([
            'message' => 'Hotel added successfully.',
            'hotel'   => $hotel->load(['images', 'primaryImage', 'amenities']),
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

        // Process any uploaded images sent during hotel update
        $this->processUploadedImages($request, $hotel);

        return response()->json([
            'message' => 'Hotel updated successfully.',
            'hotel'   => $hotel->load(['images', 'primaryImage', 'amenities']),
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

    private function processUploadedImages(Request $request, Hotel $hotel)
    {
        $files = [];
        $excludeKeys = [
            'pan_card', 'gst_image', 'fssai_license', 'business_proof',
            'aadhar_front', 'aadhar_back', 'aadhaar_front', 'aadhaar_back'
        ];

        $allUploadedFiles = $request->allFiles();
        foreach ($allUploadedFiles as $key => $fileInput) {
            if (in_array(strtolower($key), $excludeKeys)) {
                continue;
            }

            if (is_array($fileInput)) {
                foreach ($fileInput as $file) {
                    if ($file instanceof \Illuminate\Http\UploadedFile) {
                        $files[] = $file;
                    }
                }
            } elseif ($fileInput instanceof \Illuminate\Http\UploadedFile) {
                $files[] = $fileInput;
            }
        }

        if (empty($files)) {
            return [];
        }

        $hasPrimary = $hotel->images()->whereRaw('("is_primary" = true OR "is_primary" IS TRUE)')->exists();
        $uploaded = [];

        foreach ($files as $index => $file) {
            if (!$file->isValid()) continue;

            $path = $file->store('hotels', 'public');
            $isPrimary = !$hasPrimary || ($index === 0);

            if ($isPrimary) {
                HotelImage::where('hotel_id', $hotel->id)->update([
                    'is_primary' => \Illuminate\Support\Facades\DB::raw('false')
                ]);
            }

            $image = HotelImage::create([
                'hotel_id'   => $hotel->id,
                'image_path' => $path,
                'is_primary' => $isPrimary,
            ]);

            $uploaded[] = $image;
            if ($isPrimary) {
                $hasPrimary = true;
            }
        }

        return $uploaded;
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

        $uploaded = $this->processUploadedImages($request, $hotel);

        return response()->json([
            'message' => 'Images uploaded successfully.',
            'images'  => $hotel->images()->get(),
            'hotel'   => $hotel->load(['images', 'primaryImage', 'amenities']),
        ]);
    }
}