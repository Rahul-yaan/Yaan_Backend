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
        $profile = OwnerProfile::where('user_id', $request->user()->id)
            ->first();

        return response()->json([
            'profile' => $profile,
            'user'    => $request->user(),
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

        $profile = OwnerProfile::firstOrCreate(
            ['user_id' => $request->user()->id]
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
                // Delete old file
                if ($profile->$field) {
                    Storage::disk('public')->delete($profile->$field);
                }
                $data[$field] = $request->file($field)
                    ->store('owner_documents', 'public');
            }
        }

        // Check if profile is complete
        $data['is_profile_complete'] = !empty($data['hotel_name'] ?? $profile->hotel_name)
            && !empty($data['owner_name'] ?? $profile->owner_name)
            && !empty($data['address'] ?? $profile->address);

        $profile->update($data);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'profile' => $profile,
        ]);
    }
}