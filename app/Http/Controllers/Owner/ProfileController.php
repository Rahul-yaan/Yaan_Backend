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

        $notification = [
            'show'             => true,
            'type'             => $kycStatus === 'rejected' ? 'danger' : ($kycStatus === 'approved' ? 'success' : 'warning'),
            'title'            => $kycStatus === 'rejected' ? 'Application Rejected by Admin' : ($kycStatus === 'approved' ? 'KYC Approved' : 'Approval Pending'),
            'message'          => $kycMessage,
            'kyc_message'      => $kycMessage,
            'rejection_reason' => $rejectionReason,
        ];

        return response()->json([
            'profile'          => $profile,
            'user'             => $user,
            'hotel'            => $hotel,
            'kyc_status'       => $kycStatus,
            'rejection_reason' => $rejectionReason,
            'kyc_message'      => $kycMessage,
            'message'          => $kycMessage,
            'admin_message'    => $rejectionReason ?? $kycMessage,
            'notification'     => $notification,
            'notification_bar' => $notification,
            'hotel_status'     => $hotel ? $hotel->status : 'pending',
        ]);
    }

    // ============================================================
    // POST /api/owner/profile
    // ============================================================
    public function update(Request $request)
    {
        $user = $request->user();
        $profile = OwnerProfile::where('user_id', $user->id)->first();

        // 1. Resolve and normalize all input aliases
        $hotelName = trim((string)($request->input('hotel_name') ?? $request->input('name') ?? $profile?->hotel_name ?? ''));
        $ownerName = trim((string)($request->input('owner_name') ?? $user->name ?? $profile?->owner_name ?? ''));
        $phoneInput= trim((string)($request->input('phone') ?? $request->input('mobile') ?? $user->phone ?? ''));
        $address   = trim((string)($request->input('address') ?? $profile?->address ?? ''));
        $city      = trim((string)($request->input('city') ?? $profile?->city ?? ''));
        $state     = trim((string)($request->input('state') ?? $profile?->state ?? ''));
        $pincode   = trim((string)($request->input('pincode') ?? $request->input('pin_code') ?? $request->input('postal_code') ?? $request->input('zip') ?? $profile?->pincode ?? ''));

        $aadhaarInput = trim((string)($request->input('aadhaar_number') ?? $request->input('aadhaar_no') ?? $request->input('aadhar_number') ?? $request->input('aadhar') ?? $profile?->aadhaar_number ?? ''));
        $panInput     = trim((string)($request->input('pan_number') ?? $request->input('pan_card_number') ?? $request->input('pan_no') ?? $request->input('pan') ?? $profile?->pan_number ?? ''));

        $bankName     = trim((string)($request->input('bank_name') ?? $profile?->bank_name ?? ''));
        $accountInput = trim((string)($request->input('account_number') ?? $request->input('bank_account_number') ?? $request->input('bank_account_no') ?? $profile?->account_number ?? ''));
        $ifscInput    = trim((string)($request->input('ifsc_code') ?? $request->input('ifsc') ?? $request->input('ifsc_number') ?? $profile?->ifsc_code ?? ''));

        $fssaiInput   = trim((string)($request->input('fssai_number') ?? $request->input('fssai_no') ?? $profile?->fssai_number ?? ''));
        $gstInput     = trim((string)($request->input('gst_number') ?? $request->input('gst_no') ?? $request->input('gstin') ?? $profile?->gst_number ?? ''));

        $errors = [];

        // --- A. Hotel Name Validation ---
        if (empty($hotelName) || mb_strlen($hotelName) < 3) {
            $errors['hotel_name'] = ['Please enter a valid hotel name (at least 3 characters).'];
        } elseif (!preg_match('/[a-zA-Z]/', $hotelName)) {
            $errors['hotel_name'] = ['Hotel name must contain letters.'];
        }

        // --- B. Owner Name Validation ---
        if (empty($ownerName) || mb_strlen($ownerName) < 2) {
            $errors['owner_name'] = ['Please enter the owner full name (at least 2 characters).'];
        } elseif (!preg_match('/[a-zA-Z]/', $ownerName)) {
            $errors['owner_name'] = ['Owner name must contain letters.'];
        }

        // --- C. Phone Validation (10-digit Indian Mobile) ---
        $rawPhone = preg_replace('/[^0-9]/', '', $phoneInput);
        $phone10 = strlen($rawPhone) >= 10 ? substr($rawPhone, -10) : $rawPhone;
        $userRaw = preg_replace('/[^0-9]/', '', (string)$user->phone);
        $user10  = strlen($userRaw) >= 10 ? substr($userRaw, -10) : $userRaw;

        // Security Fix (1.10): Phone is read-only after verification
        if ($request->filled('phone') && !empty($user10) && $phone10 !== $user10) {
            return response()->json([
                'error'   => 'Phone number cannot be changed directly.',
                'message' => 'Phone number cannot be changed without OTP verification.',
            ], 422);
        }

        if (empty($phone10) || !preg_match('/^[6-9][0-9]{9}$/', $phone10)) {
            $errors['phone'] = ['Please enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9.'];
        }

        // --- D. Address Validation ---
        if (empty($address) || mb_strlen($address) < 5) {
            $errors['address'] = ['Please provide the full hotel address (minimum 5 characters).'];
        }

        // --- E. City Validation ---
        if (empty($city) || mb_strlen($city) < 2) {
            $errors['city'] = ['Please enter a valid city name.'];
        }

        // --- F. State Validation ---
        if (empty($state) || mb_strlen($state) < 2) {
            $errors['state'] = ['Please select or enter a valid state.'];
        }

        // --- G. Pincode Validation (6 digits, non-zero start) ---
        $cleanPin = preg_replace('/[^0-9]/', '', $pincode);
        if (empty($cleanPin) || !preg_match('/^[1-9][0-9]{5}$/', $cleanPin)) {
            $errors['pincode'] = ['Enter a valid 6-digit Indian PIN code (cannot start with 0).'];
        }

        // --- H. Aadhaar Number Validation (UIDAI: 12 digits, cannot start with 0 or 1, not repeated digits) ---
        $cleanAadhaar = preg_replace('/[^0-9]/', '', $aadhaarInput);
        if (empty($cleanAadhaar)) {
            $errors['aadhaar_number'] = ['Aadhaar card number is required.'];
        } elseif (strlen($cleanAadhaar) !== 12) {
            $errors['aadhaar_number'] = ['Aadhaar number must contain exactly 12 digits.'];
        } elseif ($cleanAadhaar[0] === '0' || $cleanAadhaar[0] === '1') {
            $errors['aadhaar_number'] = ['Aadhaar number cannot start with 0 or 1.'];
        } elseif (preg_match('/^(\d)\1{11}$/', $cleanAadhaar)) {
            $errors['aadhaar_number'] = ['Aadhaar number cannot contain all identical digits.'];
        }

        // --- I. PAN Card Number Validation (10 chars, 5 letters + 4 digits + 1 letter) ---
        $cleanPan = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $panInput));
        if (empty($cleanPan)) {
            $errors['pan_number'] = ['PAN card number is required.'];
        } elseif (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $cleanPan)) {
            $errors['pan_number'] = ['Enter a valid 10-character PAN number (e.g. ABCDE1234F).'];
        }

        // --- J. Bank Name Validation ---
        if (empty($bankName) || mb_strlen($bankName) < 2) {
            $errors['bank_name'] = ['Please enter a valid bank name (e.g. State Bank of India, HDFC Bank).'];
        } elseif (!preg_match('/^(?![0-9]+$)[a-zA-Z0-9\s\&\.\-]{2,100}$/', $bankName)) {
            $errors['bank_name'] = ['Bank name contains invalid characters.'];
        }

        // --- K. Bank Account Number Validation (9-18 digits) ---
        $cleanAccount = preg_replace('/[^0-9]/', '', $accountInput);
        if (empty($cleanAccount)) {
            $errors['account_number'] = ['Bank account number is required.'];
        } elseif (!preg_match('/^[0-9]{9,18}$/', $cleanAccount)) {
            $errors['account_number'] = ['Enter a valid bank account number (must be between 9 and 18 digits).'];
        }

        // --- L. Bank IFSC Code Validation (11 chars, 5th char is 0) ---
        $cleanIfsc = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $ifscInput));
        if (empty($cleanIfsc)) {
            $errors['ifsc_code'] = ['Bank IFSC code is required.'];
        } elseif (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $cleanIfsc)) {
            $errors['ifsc_code'] = ['Enter a valid 11-character IFSC code (e.g. SBIN0001234). The 5th character must be 0.'];
        }

        // --- M. FSSAI Number Validation (Optional / Conditional: 14 digits) ---
        $cleanFssai = preg_replace('/[^0-9]/', '', $fssaiInput);
        if (!empty($fssaiInput)) {
            if (strlen($cleanFssai) !== 14) {
                $errors['fssai_number'] = ['FSSAI registration number must contain exactly 14 digits.'];
            }
        }

        // --- N. GSTIN Validation (Optional / Conditional: 15 chars) ---
        $cleanGst = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $gstInput));
        if (!empty($gstInput)) {
            if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $cleanGst)) {
                $errors['gst_number'] = ['Enter a valid 15-character GSTIN number (e.g. 24ABCDE1234F1Z5).'];
            }
        }

        // --- O. KYC Document Validations ---
        $this->validateDocumentFile($request, $profile, 'aadhaar_front', 'Aadhaar card front image', true, $errors);
        $this->validateDocumentFile($request, $profile, 'aadhaar_back', 'Aadhaar card back image', true, $errors);
        $this->validateDocumentFile($request, $profile, 'pan_card', 'PAN card document', true, $errors);
        $this->validateDocumentFile($request, $profile, 'business_proof', 'Business / Property proof document', true, $errors);

        // Conditional documents: If FSSAI number is provided, FSSAI document is required
        if (!empty($cleanFssai) && !isset($errors['fssai_number'])) {
            $this->validateDocumentFile($request, $profile, 'fssai_license', 'FSSAI license copy', true, $errors);
        } else {
            $this->validateDocumentFile($request, $profile, 'fssai_license', 'FSSAI license copy', false, $errors);
        }

        // Conditional documents: If GST number is provided, GST certificate is required
        if (!empty($cleanGst) && !isset($errors['gst_number'])) {
            $this->validateDocumentFile($request, $profile, 'gst_image', 'GST certificate copy', true, $errors);
        } else {
            $this->validateDocumentFile($request, $profile, 'gst_image', 'GST certificate copy', false, $errors);
        }

        // --- P. Hotel Price & Room Count Validation (if passed) ---
        $price = $request->input('price_per_night') ?? $request->input('price');
        if ($price !== null && $price !== '') {
            if (!is_numeric($price) || (float)$price < 42.37) {
                $errors['price_per_night'] = ['Hotel price cannot be less than ₹42.37.'];
            }
        }
        $rooms = $request->input('total_rooms') ?? $request->input('rooms');
        if ($rooms !== null && $rooms !== '') {
            if (!is_numeric($rooms) || (int)$rooms < 1) {
                $errors['total_rooms'] = ['Total rooms must be at least 1.'];
            }
        }

        // IF ANY ERROR OCCURS: STOP! Reject submission immediately.
        if (!empty($errors)) {
            \Illuminate\Support\Facades\Log::warning('Hotel Owner Registration Form Validation Failed', [
                'user_id' => $user->id,
                'errors'  => $errors,
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Validation failed.',
                'message' => 'Please correct all highlighted errors before submitting your hotel registration.',
                'errors'  => $errors,
            ], 422);
        }

        // 2. Update user name (phone is strictly read-only after verification)
        $user->name = $ownerName;
        $user->save();

        // 3. Assemble validated clean profile data
        $data = [
            'hotel_name'     => $hotelName,
            'owner_name'     => $ownerName,
            'address'        => $address,
            'city'           => $city,
            'state'          => $state,
            'pincode'        => $cleanPin,
            'aadhaar_number' => $cleanAadhaar,
            'pan_number'     => $cleanPan,
            'bank_name'      => $bankName,
            'account_number' => $cleanAccount,
            'ifsc_code'      => $cleanIfsc,
            'fssai_number'   => !empty($cleanFssai) ? $cleanFssai : null,
            'gst_number'     => !empty($cleanGst) ? $cleanGst : null,
        ];

        // 4. Handle KYC file uploads securely to private disk
        $fileFields = [
            'business_proof', 'aadhaar_front', 'aadhaar_back',
            'pan_card', 'fssai_license', 'gst_image',
        ];

        $uploadErrors = [];
        foreach ($fileFields as $field) {
            $input = null;
            if ($request->hasFile($field)) {
                $input = $request->file($field);
            } elseif ($request->filled($field) && is_string($request->input($field))) {
                $val = trim($request->input($field));
                if (str_starts_with($val, 'data:')) {
                    $input = $val;
                } elseif (str_starts_with($val, 'http://') || str_starts_with($val, 'https://') || str_starts_with($val, 'kyc_docs/')) {
                    $data[$field] = $val;
                }
            }

            if ($input === null) {
                continue;
            }

            try {
                $path = \App\Support\SafeUpload::storeKycDocument($input);
                if (!$path) {
                    throw new \InvalidArgumentException('empty');
                }
                $data[$field] = $path;

                // Database copy: Render deletes disk files on every deploy
                \App\Models\KycDocument::storeCopy(
                    $user->id,
                    $field,
                    Storage::disk('local')->get($path),
                    \App\Support\SafeUpload::detectMimeType(Storage::disk('local')->path($path))
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("KYC upload failed for user {$user->id} field {$field}: " . $e->getMessage());
                $uploadErrors[$field] = ['This document could not be saved. Please upload a clear JPG, PNG, WEBP or PDF file.'];
            }
        }

        if (!empty($uploadErrors)) {
            return response()->json([
                'success' => false,
                'error'   => 'Validation failed.',
                'message' => 'Some documents could not be saved. Please upload them again as JPG, PNG, WEBP or PDF.',
                'errors'  => $uploadErrors,
            ], 422);
        }

        // 5. Update profile and mark as complete & pending admin verification
        if (!$profile) {
            $profile = OwnerProfile::create(['user_id' => $user->id]);
        }
        $data['status'] = 'pending';
        $data['rejection_reason'] = null;
        $profile->update($data);

        \Illuminate\Support\Facades\DB::statement("UPDATE owner_profiles SET is_profile_complete = true, status = 'pending', rejection_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$profile->id]);
        \Illuminate\Support\Facades\DB::statement("UPDATE users SET is_verified = false, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$user->id]);

        // 6. Sync hotel record
        $lat   = $request->input('latitude') ?? $request->input('lat') ?? $request->input('origin_lat') ?? 22.3072;
        $lng   = $request->input('longitude') ?? $request->input('lng') ?? $request->input('lon') ?? $request->input('origin_lng') ?? 73.1812;
        $finalPrice = is_numeric($price) && (float)$price >= 42.37 ? (float)$price : 1500;
        $finalRooms = is_numeric($rooms) && (int)$rooms >= 1 ? (int)$rooms : 10;

        $targetHotel = \App\Models\Hotel::where('owner_id', $user->id)->first();
        if (!$targetHotel) {
            $targetHotel = \App\Models\Hotel::create([
                'owner_id'        => $user->id,
                'name'            => $hotelName,
                'address'         => $address,
                'city'            => $city,
                'latitude'        => (float) $lat,
                'longitude'       => (float) $lng,
                'price_per_night' => (float) $finalPrice,
                'total_rooms'     => (int) $finalRooms,
                'available_rooms' => (int) $finalRooms,
                'rating'          => 4.5,
                'review_count'    => 0,
                'status'          => 'pending',
                'rejection_reason'=> null,
            ]);
        } else {
            $hotelUpdate = [
                'name'             => $hotelName,
                'address'          => $address,
                'city'             => $city,
                'status'           => 'pending',
                'rejection_reason' => null,
            ];
            if ($request->filled('latitude') || $request->filled('lat') || $request->filled('origin_lat')) {
                $hotelUpdate['latitude'] = (float) $lat;
            }
            if ($request->filled('longitude') || $request->filled('lng') || $request->filled('lon') || $request->filled('origin_lng')) {
                $hotelUpdate['longitude'] = (float) $lng;
            }
            if ($price !== null && $price !== '') {
                $hotelUpdate['price_per_night'] = (float) $finalPrice;
            }
            if ($rooms !== null && $rooms !== '') {
                $hotelUpdate['total_rooms'] = (int) $finalRooms;
                $hotelUpdate['available_rooms'] = (int) $finalRooms;
            }
            $targetHotel->update($hotelUpdate);
        }

        if ($targetHotel) {
            $targetHotel->ensurePrimaryImageExists();
        }

        $pendingMsg = 'Please wait for approval by the admin.';
        $updateNotification = [
            'show'             => true,
            'type'             => 'warning',
            'title'            => 'Approval Pending',
            'message'          => $pendingMsg,
            'kyc_message'      => $pendingMsg,
            'rejection_reason' => null,
        ];

        return response()->json([
            'success'          => true,
            'message'          => $pendingMsg,
            'kyc_message'      => $pendingMsg,
            'admin_message'    => $pendingMsg,
            'profile'          => $profile->fresh(),
            'kyc_status'       => 'pending_approval',
            'notification'     => $updateNotification,
            'notification_bar' => $updateNotification,
        ]);
    }

    /**
     * Validate an uploaded document file or Base64 document payload.
     */
    private function validateDocumentFile(Request $request, ?OwnerProfile $profile, $fieldName, $label, $isRequired, array &$errors)
    {
        $hasUpload   = $request->hasFile($fieldName);
        $hasBase64   = $request->filled($fieldName) && is_string($request->input($fieldName));
        $hasExisting = $profile ? !empty($profile->{$fieldName}) : false;

        // 1. If uploaded as an HTTP multipart file
        if ($hasUpload) {
            $file = $request->file($fieldName);
            if (!$file->isValid()) {
                $errors[$fieldName] = ["The {$label} upload was invalid or corrupted."];
                return;
            }

            $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp', 'application/pdf'];
            $mime = strtolower((string)$file->getClientMimeType());
            $ext  = strtolower((string)$file->getClientOriginalExtension());
            $allowedExts = ['jpeg', 'png', 'jpg', 'webp', 'pdf'];

            if (!in_array($mime, $allowedMimes) && !in_array($ext, $allowedExts)) {
                $errors[$fieldName] = ["{$label} must be a valid image (JPEG, PNG, WEBP) or PDF document."];
                return;
            }

            if ($file->getSize() > 10 * 1024 * 1024) {
                $errors[$fieldName] = ["{$label} file size cannot exceed 10MB."];
                return;
            }
            return;
        }

        // 2. If uploaded as Base64 data URI or existing URL string
        if ($hasBase64) {
            $val = trim((string)$request->input($fieldName));
            if (str_starts_with($val, 'data:image/') || str_starts_with($val, 'data:application/pdf')) {
                $commaPos = strpos($val, ',');
                if ($commaPos === false) {
                    $errors[$fieldName] = ["The {$label} base64 payload is formatted incorrectly."];
                    return;
                }
                $rawBase64 = substr($val, $commaPos + 1);
                $decoded = base64_decode($rawBase64, true);
                if ($decoded === false) {
                    $errors[$fieldName] = ["The {$label} base64 file data is corrupted."];
                    return;
                }
                if (strlen($decoded) > 10 * 1024 * 1024) {
                    $errors[$fieldName] = ["{$label} file size cannot exceed 10MB."];
                    return;
                }
            } elseif (str_starts_with($val, 'http://') || str_starts_with($val, 'https://') || str_starts_with($val, 'kyc_docs/')) {
                return;
            } else {
                $errors[$fieldName] = ["{$label} must be an uploaded file or valid Base64 document."];
                return;
            }
            return;
        }

        // 3. If missing from request
        if ($isRequired && !$hasExisting) {
            $errors[$fieldName] = ["{$label} is required."];
        }
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