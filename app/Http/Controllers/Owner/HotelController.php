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

    // ============================================================
    // 2. ADD HOTEL
    //    URL:    POST /api/owner/hotels
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function store(Request $request)
    {
        $request->validate([
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
        if ($request->amenities) {
            $hotel->amenities()->attach($request->amenities);
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

        $request->validate([
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

        $hotel->update($request->only([
            'name', 'description', 'city', 'address',
            'latitude', 'longitude', 'price_per_night',
            'total_rooms', 'status',
        ]));

        if ($request->has('amenities')) {
            $hotel->amenities()->sync($request->amenities);
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

        foreach ($request->file('images') as $index => $file) {
            $path = $file->store('hotels', 'public');

            $isPrimary = $index === 0 && $hotel->images()->count() === 0;

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