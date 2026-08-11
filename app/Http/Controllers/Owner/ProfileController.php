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

        $isVerified = (bool) $user->is_verified;
        $isComplete = $profile ? (bool) $profile->is_profile_complete : false;

        $kycStatus = 'action_required';
        $kycMessage = 'Admin has requested fresh KYC submission. Please fill in your hotel details and upload document images.';

        if ($isVerified) {
            $kycStatus = 'verified';
            $kycMessage = 'Your Owner KYC is fully verified and active.';
        } elseif ($isComplete) {
            $kycStatus = 'pending_approval';
            $kycMessage = 'Your KYC documents have been submitted successfully and are currently pending Admin verification.';
        }

        return response()->json([
            'profile'    => $profile,
            'user'       => $user,
            'kyc_status' => $kycStatus,
            'kyc_message' => $kycMessage,
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
            'address'        => 'nullable|string',
            'state'          => 'nullable|string',
            'city'           => 'nullable|string',
            'pincode'        => 'nullable|string|max:10',
            'fssai_number'   => 'nullable|string',
            'gst_number'     => 'nullable|string',
            'bank_name'      => 'nullable|string',
            'account_number' => 'nullable|string',
            'ifsc_code'      => 'nullable|string',
            'business_proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'aadhaar_front'  => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'aadhaar_back'   => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'pan_card'       => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'fssai_license'  => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'gst_image'      => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $user = $request->user();

        $profile = OwnerProfile::firstOrCreate(
            ['user_id' => $user->id]
        );

        $data = $request->only([
            'hotel_name', 'owner_name', 'address', 'state',
            'city', 'pincode', 'fssai_number', 'gst_number',
            'bank_name', 'account_number', 'ifsc_code',
        ]);

        // Handle file uploads
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
            }
        }

        // Update profile text and file fields (excluding boolean flags to avoid PostgreSQL 42804 type mismatch)
        unset($data['is_profile_complete']);
        $profile->update($data);

        \Illuminate\Support\Facades\DB::statement("UPDATE owner_profiles SET is_profile_complete = true, updated_at = NOW() WHERE id = ?", [$profile->id]);
        \Illuminate\Support\Facades\DB::statement("UPDATE users SET is_verified = false, updated_at = NOW() WHERE id = ?", [$user->id]);

        // Sync hotel name, address, and city with core hotels table and reset status to pending for Admin verification
        if (!empty($data['hotel_name'])) {
            $hotel = \App\Models\Hotel::where('owner_id', $user->id)->first();
            if ($hotel) {
                $hotel->update([
                    'name'    => $data['hotel_name'],
                    'address' => $data['address'] ?? $hotel->address,
                    'city'    => $data['city'] ?? $hotel->city,
                    'status'  => 'pending',
                ]);
            } else {
                \App\Models\Hotel::create([
                    'owner_id'        => $user->id,
                    'name'            => $data['hotel_name'],
                    'address'         => $data['address'] ?? 'N/A',
                    'city'            => $data['city'] ?? 'N/A',
                    'price_per_night' => 1500,
                    'total_rooms'     => 10,
                    'available_rooms' => 10,
                    'rating'          => 4.5,
                    'review_count'    => 0,
                    'status'          => 'pending',
                ]);
            }
        } else {
            \Illuminate\Support\Facades\DB::statement("UPDATE hotels SET status = 'pending', updated_at = NOW() WHERE owner_id = ?", [$user->id]);
        }

        return response()->json([
            'message'    => 'KYC & Profile details updated successfully. Status is now Pending Admin Verification.',
            'profile'    => $profile->fresh(),
            'kyc_status' => 'pending_approval',
        ]);
    }
}