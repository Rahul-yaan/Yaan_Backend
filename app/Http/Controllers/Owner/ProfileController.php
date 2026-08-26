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
        $isProfileRejected = $profile && $profile->status === 'rejected';
        $isHotelRejected = $hotel && $hotel->status === 'rejected';
        $isProfileApproved = $profile && $profile->status === 'approved';
        $isHotelApproved = $hotel && in_array($hotel->status, ['approved', 'active']);

        if ($isVerified && $isProfileApproved && $isHotelApproved) {
            $kycStatus = 'approved';
            $rejectionReason = null;
            $kycMessage = 'Your Owner KYC and hotel profile are fully verified and active.';
        } elseif ($isProfileRejected || $isHotelRejected) {
            $kycStatus = 'rejected';
            $rejectionReason = ($profile && !empty($profile->rejection_reason))
                ? $profile->rejection_reason
                : (($hotel && !empty($hotel->rejection_reason)) ? $hotel->rejection_reason : 'Admin rejected your application.');
            $kycMessage = "Admin rejected your application for this reason: {$rejectionReason}";
        } else {
            $kycStatus = 'pending_approval';
            $rejectionReason = null;
            $kycMessage = 'Please wait for approval by the admin.';
        }

        return response()->json([
            'profile'          => $profile,
            'user'             => $user,
            'hotel'            => $hotel,
            'kyc_status'       => $kycStatus,
            'rejection_reason' => $rejectionReason,
            'kyc_message'      => $kycMessage,
            'message'          => $kycMessage,
            'hotel_status'     => $hotel ? $hotel->status : 'pending',
        ]);
    }

    // ============================================================
    // POST /api/owner/profile
    // ============================================================
    public function update(Request $request)
    {
        $request->validate([
            'hotel_name'      => 'nullable|string|max:200',
            'owner_name'      => 'nullable|string|max:200',
            'name'            => 'nullable|string|max:200',
            'phone'           => 'nullable|string|max:30',
            'address'         => 'nullable|string',
            'state'           => 'nullable|string',
            'city'            => 'nullable|string',
            'pincode'         => 'nullable|string|max:10',
            'aadhaar_number'  => 'nullable|string|regex:/^[0-9]{12}$/',
            'pan_number'      => 'nullable|string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/i',
            'pan_card_number' => 'nullable|string|regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/i',
            'fssai_number'    => 'nullable|string',
            'gst_number'      => 'nullable|string',
            'bank_name'       => 'nullable|string|min:2|max:100|regex:/^(?![0-9]+$)[a-zA-Z0-9\s\&\.\-]{2,100}$/',
            'account_number'  => 'nullable|string|regex:/^[0-9]{9,18}$/',
            'ifsc_code'       => 'nullable|string|regex:/^[A-Z]{4}0[A-Z0-9]{6}$/i',
            'business_proof'  => 'nullable',
            'aadhaar_front'   => 'nullable',
            'aadhaar_back'    => 'nullable',
            'pan_card'        => 'nullable',
            'fssai_license'   => 'nullable',
            'gst_image'       => 'nullable',
        ], [
            'aadhaar_number.regex'  => 'Aadhaar number must contain exactly 12 digits.',
            'pan_number.regex'      => 'Enter a valid PAN number, for example ABCDE1234F.',
            'pan_card_number.regex' => 'Enter a valid PAN number, for example ABCDE1234F.',
            'bank_name.min'         => 'Enter a valid bank name.',
            'bank_name.max'         => 'Enter a valid bank name.',
            'bank_name.regex'       => 'Enter a valid bank name.',
            'account_number.regex'  => 'Enter a valid bank account number.',
            'ifsc_code.regex'       => 'Enter a valid 11-character IFSC code.',
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
            'city', 'pincode', 'aadhaar_number', 'pan_number', 'fssai_number', 'gst_number',
            'bank_name', 'account_number', 'ifsc_code',
        ]);

        // Support alternative field aliases for aadhaar_number (e.g. aadhaar_no, aadhar)
        $aadhaarInput = $request->input('aadhaar_number') ?? $request->input('aadhaar_no') ?? $request->input('aadhar_number') ?? $request->input('aadhar');
        if (!empty($aadhaarInput)) {
            if (!preg_match('/^[0-9]{12}$/', trim($aadhaarInput))) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => [
                        'aadhaar_number' => ['Aadhaar number must contain exactly 12 digits.']
                    ]
                ], 422);
            }
            $data['aadhaar_number'] = preg_replace('/[^0-9]/', '', trim($aadhaarInput));
        }

        // PAN Card Number handling (auto uppercase, regex check, support aliases)
        $panInput = $request->input('pan_number') ?? $request->input('pan_card_number') ?? $request->input('pan_no') ?? $request->input('pan');
        if (!empty($panInput)) {
            $panUpper = strtoupper(trim($panInput));
            if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $panUpper)) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => [
                        'pan_number' => ['Enter a valid PAN number, for example ABCDE1234F.']
                    ]
                ], 422);
            }
            $data['pan_number'] = $panUpper;
        }

        // Bank Name handling (trim, length & allowed characters check)
        $bankNameInput = $request->input('bank_name');
        if (!empty($bankNameInput)) {
            $trimmedBankName = trim($bankNameInput);
            if (!preg_match('/^(?![0-9]+$)[a-zA-Z0-9\s\&\.\-]{2,100}$/', $trimmedBankName)) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => [
                        'bank_name' => ['Enter a valid bank name.']
                    ]
                ], 422);
            }
            $data['bank_name'] = $trimmedBankName;
        }

        // Bank Account Number handling (numeric digits only, 9-18 range, support aliases)
        $accountInput = $request->input('account_number') ?? $request->input('bank_account_number') ?? $request->input('bank_account_no');
        if (!empty($accountInput)) {
            $trimmedAccount = trim($accountInput);
            if (!preg_match('/^[0-9]{9,18}$/', $trimmedAccount)) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => [
                        'account_number' => ['Enter a valid bank account number.']
                    ]
                ], 422);
            }
            $data['account_number'] = $trimmedAccount;
        }

        // IFSC Code handling (auto uppercase, regex check, support aliases)
        $ifscInput = $request->input('ifsc_code') ?? $request->input('ifsc') ?? $request->input('ifsc_number');
        if (!empty($ifscInput)) {
            $ifscUpper = strtoupper(trim($ifscInput));
            if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifscUpper)) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors'  => [
                        'ifsc_code' => ['Enter a valid 11-character IFSC code.']
                    ]
                ], 422);
            }
            $data['ifsc_code'] = $ifscUpper;
        }

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
                $path = $file->store('kyc_docs', 'public');
                $data[$field] = $path;
            } elseif ($request->filled($field) && is_string($request->input($field))) {
                $val = trim($request->input($field));
                if (str_starts_with($val, 'data:image/') || str_starts_with($val, 'data:application/')) {
                    $savedPath = $this->saveBase64Document($val, $field, $user->id);
                    $data[$field] = $savedPath ?? $val;
                } else {
                    $data[$field] = $val;
                }
            }
        }

        // Update profile text and file fields
        unset($data['is_profile_complete']);
        $data['status'] = 'pending';
        $data['rejection_reason'] = null;
        $profile->update($data);

        \Illuminate\Support\Facades\DB::statement("UPDATE owner_profiles SET is_profile_complete = true, status = 'pending', rejection_reason = NULL, updated_at = NOW() WHERE id = ?", [$profile->id]);
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
                'rejection_reason'=> null,
            ]);
        } else {
            $hotelUpdate = [
                'name'             => !empty($data['hotel_name']) ? $data['hotel_name'] : $targetHotel->name,
                'address'          => !empty($data['address']) ? $data['address'] : $targetHotel->address,
                'city'             => !empty($data['city']) ? $data['city'] : $targetHotel->city,
                'status'           => 'pending',
                'rejection_reason' => null,
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
            'message'    => 'Please wait for approval by the admin.',
            'profile'    => $profile->fresh(),
            'kyc_status' => 'pending_approval',
        ]);
    }

    private function saveBase64Document($base64String, $fieldName, $userId)
    {
        try {
            if (preg_match('/^data:(image\/(\w+)|application\/pdf);base64,/', $base64String, $matches)) {
                $ext = isset($matches[2]) && !empty($matches[2]) ? $matches[2] : 'jpg';
                if ($ext === 'jpeg') $ext = 'jpg';
                $rawBase64 = substr($base64String, strpos($base64String, ',') + 1);
                $decoded = base64_decode($rawBase64);
                if ($decoded === false) return null;

                $fileName = "{$fieldName}_{$userId}_" . time() . '.' . $ext;
                $relPath = "kyc_docs/{$fileName}";
                Storage::disk('public')->put($relPath, $decoded);
                return $relPath;
            }
        } catch (\Throwable $e) {}

        return null;
    }
}