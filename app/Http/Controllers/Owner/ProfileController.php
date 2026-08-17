<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\OwnerProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    // ============================================================
    // GET /api/owner/profile
    // ============================================================
    public function show(Request $request)
    {
        $user = $request->user();
        $profile = OwnerProfile::where('user_id', $user->id)->first();
        $hotel = \App\Models\Hotel::where('owner_id', $user->id)->first();

        $isVerified = (bool) $user->is_verified;
        $isComplete = $profile ? (bool) $profile->is_profile_complete : false;

        $kycStatus = 'action_required';
        $kycMessage = 'Admin has requested fresh KYC submission. Please fill in your hotel details and upload document images.';

        if ($isVerified && ($hotel && in_array($hotel->status, ['approved', 'active']))) {
            $kycStatus = 'approved';
            $kycMessage = 'Your Owner KYC and hotel profile are fully verified and active.';
        } else {
            $kycStatus = 'pending_approval';
            $kycMessage = 'Registration submitted successfully! Your hotel and profile are pending Admin verification. Please wait for Admin approval before your hotel goes live for users.';
        }

        return response()->json([
            'profile'      => $profile,
            'user'         => $user,
            'hotel'        => $hotel,
            'kyc_status'   => $kycStatus,
            'kyc_message'  => $kycMessage,
            'hotel_status' => $hotel ? $hotel->status : 'pending',
        ]);
    }

    // ============================================================
    // POST /api/owner/profile
    // ============================================================
    public function update(Request $request)
    {
        $request->validate([
            'hotel_name'     => 'nullable|string|max:200',
            'owner_name'     => 'nullable|string|max:200',
            'name'           => 'nullable|string|max:200',
            'phone'          => 'nullable|string|max:30',
            'address'        => 'nullable|string',
            'state'          => 'nullable|string',
            'city'           => 'nullable|string',
            'pincode'        => 'nullable|string|max:10',
            'fssai_number'   => 'nullable|string',
            'gst_number'     => 'nullable|string',
            'bank_name'      => 'nullable|string',
            'account_number' => 'nullable|string',
            'ifsc_code'      => 'nullable|string',
            'business_proof' => 'nullable',
            'aadhaar_front'  => 'nullable',
            'aadhaar_back'   => 'nullable',
            'pan_card'       => 'nullable',
            'fssai_license'  => 'nullable',
            'gst_image'      => 'nullable',
        ]);

        $user = $request->user();

        // Update User name & phone if provided
        $newName = $request->input('owner_name') ?? $request->input('name');
        if (!empty($newName)) {
            $user->name = trim($newName);
        }
        if ($request->filled('phone')) {
            $user->phone = trim($request->input('phone'));
        }
        $user->save();

        $profile = OwnerProfile::firstOrCreate(
            ['user_id' => $user->id]
        );

        $data = $request->only([
            'hotel_name', 'owner_name', 'address', 'state',
            'city', 'pincode', 'fssai_number', 'gst_number',
            'bank_name', 'account_number', 'ifsc_code',
        ]);

        if (empty($data['owner_name']) && !empty($user->name)) {
            $data['owner_name'] = $user->name;
        }

        // Handle file uploads (supporting both multipart UploadedFile and Base64/URL strings)
        $fileFields = [
            'business_proof', 'aadhaar_front', 'aadhaar_back',
            'pan_card', 'fssai_license', 'gst_image',
        ];

        foreach ($fileFields as $field) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                $mime = $file->getClientMimeType() ?: 'image/jpeg';
                $contents = file_get_contents($file->getRealPath());
                $base64 = 'data:' . $mime . ';base64,' . base64_encode($contents);
                $data[$field] = $base64;
            } elseif ($request->filled($field) && is_string($request->input($field))) {
                $data[$field] = trim($request->input($field));
            }
        }

        // Update profile text and file fields
        unset($data['is_profile_complete']);
        $profile->update($data);

        \Illuminate\Support\Facades\DB::statement("UPDATE owner_profiles SET is_profile_complete = true, updated_at = NOW() WHERE id = ?", [$profile->id]);
        \Illuminate\Support\Facades\DB::statement("UPDATE users SET is_verified = false, updated_at = NOW() WHERE id = ?", [$user->id]);

        // Sync hotel name, address, city, latitude, longitude, pricing with core hotels table
        $lat   = $request->input('latitude') ?? $request->input('lat') ?? $request->input('origin_lat') ?? 22.3072;
        $lng   = $request->input('longitude') ?? $request->input('lng') ?? $request->input('lon') ?? $request->input('origin_lng') ?? 73.1812;
        $price = $request->input('price_per_night') ?? $request->input('price') ?? 1500;
        $rooms = $request->input('total_rooms') ?? $request->input('rooms') ?? 10;

        $targetHotel = \App\Models\Hotel::where('owner_id', $user->id)->first();
        if (!$targetHotel) {
            $targetHotel = \App\Models\Hotel::create([
                'owner_id'        => $user->id,
                'name'            => !empty($data['hotel_name']) ? $data['hotel_name'] : $user->name,
                'address'         => $data['address'] ?? 'N/A',
                'city'            => $data['city'] ?? 'N/A',
                'latitude'        => (float) $lat,
                'longitude'       => (float) $lng,
                'price_per_night' => (float) $price,
                'total_rooms'     => (int) $rooms,
                'available_rooms' => (int) $rooms,
                'rating'          => 4.5,
                'review_count'    => 0,
                'status'          => 'pending',
            ]);
        } else {
            $hotelUpdate = [
                'name'    => !empty($data['hotel_name']) ? $data['hotel_name'] : $targetHotel->name,
                'address' => !empty($data['address']) ? $data['address'] : $targetHotel->address,
                'city'    => !empty($data['city']) ? $data['city'] : $targetHotel->city,
                'status'  => 'pending',
            ];
            if ($request->filled('latitude') || $request->filled('lat') || $request->filled('origin_lat')) {
                $hotelUpdate['latitude'] = (float) $lat;
            }
            if ($request->filled('longitude') || $request->filled('lng') || $request->filled('lon') || $request->filled('origin_lng')) {
                $hotelUpdate['longitude'] = (float) $lng;
            }
            if ($request->filled('price_per_night') || $request->filled('price')) {
                $hotelUpdate['price_per_night'] = (float) $price;
            }
            if ($request->filled('total_rooms') || $request->filled('rooms')) {
                $hotelUpdate['total_rooms'] = (int) $rooms;
                $hotelUpdate['available_rooms'] = (int) $rooms;
            }
            $targetHotel->update($hotelUpdate);
        }

        // Automatically attach registration/profile photo as hotel image
        $uploadedPhoto = $data['business_proof'] ?? $data['aadhaar_front'] ?? $data['gst_image'] ?? null;
        if ($uploadedPhoto && $targetHotel) {
            \App\Models\HotelImage::create([
                'hotel_id'   => $targetHotel->id,
                'image_path' => $uploadedPhoto,
                'is_primary' => true,
            ]);
        }

        if ($targetHotel) {
            $targetHotel->ensurePrimaryImageExists();
        }

        return response()->json([
            'message'    => 'KYC & Profile details updated successfully. Status is now Pending Admin Verification.',
            'profile'    => $profile->fresh(),
            'kyc_status' => 'pending_approval',
        ]);
    }
}