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

        foreach ($hotels as $hotel) {
            if (empty($hotel->yaan_id)) {
                $hotel->yaan_id = Hotel::generateUniqueYaanId();
                $hotel->qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' . urlencode($hotel->yaan_id);
                $hotel->save();
            }
        }

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
                if ($id === 19 || $id === 20) {
                    continue;
                }
                $amenity = Amenity::find($id);
                if ($amenity && !in_array(strtolower($amenity->name), ['men', 'women'])) {
                    $resolvedIds[] = $amenity->id;
                } elseif (isset($idToNameMap[$id])) {
                    $name = $idToNameMap[$id];
                    if (!in_array(strtolower($name), ['men', 'women'])) {
                        $amenity = Amenity::firstOrCreate(['name' => $name]);
                        $resolvedIds[] = $amenity->id;
                    }
                }
            } elseif (is_string($item) && !empty(trim($item))) {
                $name = trim($item);
                if (in_array(strtolower($name), ['men', 'women'])) {
                    continue;
                }
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

    // Helper to validate and process wheel pricing matrix
    private function validateAndProcessWheelPrices(Request $request)
    {
        $rawWheelPrices = $request->input('wheel_prices') ?? $request->input('wheels_prices') ?? $request->input('wheel_matrix');

        if (is_string($rawWheelPrices)) {
            $decoded = json_decode($rawWheelPrices, true);
            if (is_array($decoded)) {
                $rawWheelPrices = $decoded;
            }
        }

        $processedMatrix = [];
        $errors = [];

        $knownCategories = [
            '4_wheel'       => '4 Wheel',
            '6_wheel'       => '6 Wheel',
            '8_wheel'       => '8 Wheel',
            '10_wheel'      => '10 Wheel',
            '12_wheel'      => '12 Wheel',
            '14_wheel'      => '14 Wheel',
            '16_wheel'      => '16 Wheel',
            '18_wheel'      => '18 Wheel',
            '20_wheel'      => '20 Wheel',
            '22_wheel'      => '22 Wheel',
            '24_wheel'      => '24 Wheel',
            '22_plus_wheel' => '22+ Wheel',
        ];

        if (is_array($rawWheelPrices) && !empty($rawWheelPrices)) {
            foreach ($rawWheelPrices as $key => $data) {
                if (isset($knownCategories[$key])) {
                    $categoryName = $knownCategories[$key];
                } else {
                    $cleanKey = str_replace(['_', '-'], ' ', strtolower((string)$key));
                    $cleanKey = str_replace('plus', '+', $cleanKey);
                    $categoryName = ucwords($cleanKey);
                }

                $origRaw = is_array($data) ? ($data['original_price'] ?? $data['price'] ?? $data['base_price'] ?? null) : null;
                $discRaw = is_array($data) ? ($data['discount_price'] ?? $data['discounted_price'] ?? $data['offer_price'] ?? null) : null;

                if ($origRaw !== null && $origRaw !== '') {
                    if (!is_numeric($origRaw) || (float)$origRaw < 42.37) {
                        $errors["wheel_prices.{$key}.original_price"] = ["Hotel price cannot be less than ₹42.37 for {$categoryName}."];
                    }
                }

                if ($discRaw !== null && $discRaw !== '') {
                    if (!is_numeric($discRaw) || (float)$discRaw < 0) {
                        $errors["wheel_prices.{$key}.discount_price"] = ["Enter a valid numeric price for {$categoryName}."];
                    } elseif ($origRaw !== null && $origRaw !== '' && is_numeric($origRaw)) {
                        $origVal = (float) $origRaw;
                        $discVal = (float) $discRaw;
                        if ($discVal > $origVal) {
                            $errors["wheel_prices.{$key}.discount_price"] = ["Discount price cannot be greater than the original price for {$categoryName}."];
                        }
                    }
                }

                if (is_array($data)) {
                    $processedMatrix[$key] = [
                        'original_price' => ($origRaw !== null && is_numeric($origRaw)) ? (float)$origRaw : null,
                        'discount_price' => ($discRaw !== null && is_numeric($discRaw)) ? (float)$discRaw : null,
                    ];
                }
            }
        }

        // Single Base / Discount Price validation at root level
        $rootBasePrice = $request->input('price_per_night') ?? $request->input('price') ?? $request->input('wheels_price') ?? $request->input('base_price');
        $rootDiscountPrice = $request->input('discount_price') ?? $request->input('discounted_price') ?? $request->input('offer_price');

        if ($rootBasePrice !== null && $rootBasePrice !== '') {
            if (!is_numeric($rootBasePrice) || (float)$rootBasePrice < 42.37) {
                $errors['price_per_night'] = ["Hotel price cannot be less than ₹42.37."];
            }
        }

        if ($rootDiscountPrice !== null && $rootDiscountPrice !== '') {
            if (!is_numeric($rootDiscountPrice) || (float)$rootDiscountPrice < 0) {
                $errors['discount_price'] = ["Enter a valid numeric price."];
            } elseif ($rootBasePrice !== null && $rootBasePrice !== '' && is_numeric($rootBasePrice)) {
                if ((float)$rootDiscountPrice > (float)$rootBasePrice) {
                    $errors['discount_price'] = ["Discount price cannot be greater than the base price."];
                }
            }
        }

        return [
            'errors'       => $errors,
            'wheel_matrix' => $processedMatrix,
        ];
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

        $wheelPricingCheck = $this->validateAndProcessWheelPrices($request);
        if (!empty($wheelPricingCheck['errors'])) {
            return response()->json([
                'error'   => 'Validation failed.',
                'message' => 'The given data was invalid.',
                'errors'  => $wheelPricingCheck['errors'],
            ], 422);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name'           => 'required|string|max:200',
            'description'    => 'nullable|string',
            'city'           => 'required|string',
            'address'        => 'required|string',
            'latitude'       => 'required|numeric',
            'longitude'      => 'required|numeric',
            'price_per_night'=> 'required|numeric|min:42.37',
            'discount_price' => 'nullable|numeric|min:0',
            'total_rooms'    => 'required|integer|min:1',
            'amenities'      => 'nullable|array',
            'amenities.*'    => 'exists:amenities,id',
        ], [
            'price_per_night.min' => 'Hotel price cannot be less than ₹42.37.',
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
        $wheelMatrixData = !empty($wheelPricingCheck['wheel_matrix']) ? $wheelPricingCheck['wheel_matrix'] : null;

        if ($existingHotel) {
            $existingHotel->update([
                'name'           => $request->name,
                'description'    => $request->description,
                'city'           => $request->city,
                'address'        => $request->address,
                'latitude'       => $request->latitude,
                'longitude'      => $request->longitude,
                'price_per_night'=> $request->price_per_night,
                'discount_price' => $request->input('discount_price'),
                'wheel_prices'   => $wheelMatrixData ?? $existingHotel->wheel_prices,
                'total_rooms'    => $request->total_rooms,
                'available_rooms'=> $request->total_rooms,
                'status'         => 'pending',
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
            'discount_price' => $request->input('discount_price'),
            'wheel_prices'   => $wheelMatrixData,
            'total_rooms'    => $request->total_rooms,
            'available_rooms'=> $request->total_rooms,
            'status'         => 'pending',
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

        $wheelPricingCheck = $this->validateAndProcessWheelPrices($request);
        if (!empty($wheelPricingCheck['errors'])) {
            return response()->json([
                'error'   => 'Validation failed.',
                'message' => 'The given data was invalid.',
                'errors'  => $wheelPricingCheck['errors'],
            ], 422);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name'           => 'sometimes|string|max:200',
            'description'    => 'nullable|string',
            'city'           => 'sometimes|string',
            'address'        => 'sometimes|string',
            'latitude'       => 'sometimes|numeric',
            'longitude'      => 'sometimes|numeric',
            'price_per_night'=> 'sometimes|numeric|min:42.37',
            'discount_price' => 'nullable|numeric|min:0',
            'total_rooms'    => 'sometimes|integer|min:1',
            'status'         => 'sometimes|in:active,inactive',
            'amenities'      => 'nullable|array',
            'amenities.*'    => 'exists:amenities,id',
        ], [
            'price_per_night.min' => 'Hotel price cannot be less than ₹42.37.',
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
            'discount_price', 'total_rooms', 'status',
        ]);

        if (!empty($wheelPricingCheck['wheel_matrix'])) {
            $updateData['wheel_prices'] = $wheelPricingCheck['wheel_matrix'];
        }

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

        $hasPrimary = $hotel->images()->whereRaw('is_primary IS TRUE')->exists();
        $uploaded = [];

        // Support base64 or URL strings sent in images array payload
        $rawImages = $request->input('images') ?? $request->input('image_urls') ?? $request->input('photos');
        if (!empty($rawImages) && is_array($rawImages)) {
            foreach ($rawImages as $imgStr) {
                if (is_string($imgStr) && !empty(trim($imgStr))) {
                    $imgStr = trim($imgStr);
                    $isPrimary = !$hasPrimary;
                    if ($isPrimary) {
                        \Illuminate\Support\Facades\DB::statement("UPDATE hotel_images SET is_primary = false WHERE hotel_id = ?", [$hotel->id]);
                        $hasPrimary = true;
                    }

                    $b64 = str_starts_with($imgStr, 'data:') ? $imgStr : null;
                    $cleanPath = $imgStr;
                    if ($b64) {
                        $ext = 'jpg';
                        if (preg_match('/^data:image\/(\w+);base64,/', $imgStr, $m)) {
                            $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                        }
                        $fileName = 'hotel_' . $hotel->id . '_' . uniqid() . '.' . $ext;
                        $cleanPath = 'hotels/' . $fileName;
                        try {
                            $raw = substr($imgStr, strpos($imgStr, ',') + 1);
                            Storage::disk('public')->put($cleanPath, base64_decode($raw));
                        } catch (\Throwable $e) {}
                    }

                    $image = HotelImage::create([
                        'hotel_id'   => $hotel->id,
                        'image_path' => $cleanPath,
                        'image_data' => $b64,
                        'is_primary' => $isPrimary,
                    ]);
                    $uploaded[] = $image;
                }
            }
        }

        if (empty($files)) {
            return $uploaded;
        }

        foreach ($files as $index => $file) {
            if (!$file->isValid()) continue;

            $path = $file->store('hotels', 'public');
            $isPrimary = !$hasPrimary || ($index === 0);

            if ($isPrimary) {
                \Illuminate\Support\Facades\DB::statement("UPDATE hotel_images SET is_primary = false WHERE hotel_id = ?", [$hotel->id]);
            }

            $b64Data = null;
            try {
                $realPath = $file->getRealPath();
                if ($realPath && file_exists($realPath)) {
                    $mime = $file->getClientMimeType() ?: 'image/jpeg';
                    $b64Data = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($realPath));
                }
            } catch (\Throwable $e) {}

            $image = HotelImage::create([
                'hotel_id'   => $hotel->id,
                'image_path' => $path,
                'image_data' => $b64Data,
                'is_primary' => $isPrimary,
            ]);

            $uploaded[] = $image;
            if ($isPrimary) {
                $hasPrimary = true;
            }
        }

        $hotel->ensurePrimaryImageExists();

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

    // ============================================================
    // 6. GET HOTEL YAAN ID & QR CODE FOR OWNER APP
    //    URL:    GET /api/owner/hotels/{id}/qr-code
    //    URL:    GET /api/owner/qr-code
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function getQrCode(Request $request, $id = null)
    {
        $query = Hotel::where('owner_id', $request->user()->id);

        if (!empty($id)) {
            $query->where('id', $id);
        }

        $hotel = $query->with(['primaryImage', 'amenities'])->first();

        if (!$hotel) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No hotel found for this account. Please register your hotel first.',
            ], 404);
        }

        if (empty($hotel->yaan_id)) {
            $hotel->yaan_id = Hotel::generateUniqueYaanId();
            $hotel->qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' . urlencode($hotel->yaan_id);
            $hotel->save();
        }

        $qrImageUrl = $hotel->qr_code_url ?: ('https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' . urlencode($hotel->yaan_id));

        return response()->json([
            'status'          => 'success',
            'hotel_id'        => $hotel->id,
            'hotel_name'      => $hotel->name,
            'yaan_id'         => $hotel->yaan_id,
            'hotel_code'      => $hotel->yaan_id,
            'city'            => $hotel->city,
            'address'         => $hotel->address,
            'status_state'    => $hotel->status,
            'qr_code_url'     => $qrImageUrl,
            'qr_payload'      => $hotel->qr_code_payload,
            'poster_title'    => 'Scan & Book on Yaan App',
            'poster_subtitle' => 'Instant Truck Parking & Rest Room Booking',
            'hotel'           => $hotel,
            'instructions'    => [
                '1. Download and print this QR poster standee.',
                '2. Place this poster at your Hotel Reception or Entry Gate.',
                '3. Drivers can scan this QR code using their Yaan User App for instant spot booking.'
            ],
        ]);
    }
}