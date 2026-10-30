<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SafeUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;

class AuthController extends Controller
{
    /**
     * Determine if a user record is an abandoned unverified sign-up that can be re-registered safely.
     */
    private function isAbandonedSignup(User $user): bool
    {
        return empty($user->firebase_uid)
            && !$user->is_verified
            && !$user->hotels()->exists()
            && !$user->ownerProfile()->exists()
            && !$user->bookings()->exists();
    }

    // ============================================================
    // 1. REGISTER
    //    URL:  POST /api/register
    //    Body: name, email, phone, role, password (optional)
    // ============================================================
    public function register(Request $request)
    {
        $rawRole = strtolower($request->input('role', 'user'));
        if (in_array($rawRole, ['owner', 'hotel_owner', 'hotelowner'])) {
            $role = 'owner';
        } else {
            $role = 'user';
        }
        $request->merge(['role' => $role]);

        $name  = trim($request->input('name', ''));
        $email = trim(strtolower($request->input('email', '')));
        $phone = trim($request->input('phone', ''));

        if (empty($name) || empty($email) || empty($phone)) {
            return response()->json([
                'error'   => 'Validation failed.',
                'message' => 'Please provide your full name, email address, and phone number.',
            ], 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'error'   => 'Validation failed.',
                'message' => 'Please enter a valid email address.',
            ], 422);
        }

        $rawDigits = preg_replace('/[^0-9]/', '', $phone);
        $last10 = strlen($rawDigits) >= 10 ? substr($rawDigits, -10) : $rawDigits;

        if (strlen($last10) < 10) {
            return response()->json([
                'error'   => 'Validation failed.',
                'message' => 'Please enter a valid 10-digit mobile number.',
            ], 422);
        }

        // Look up existing user (strictly excluding super admin)
        $existingUser = User::where('role', '!=', 'admin')
            ->where(function($q) use ($email, $phone, $rawDigits, $last10) {
                $q->where('email', $email)
                  ->orWhere('phone', $phone)
                  ->orWhere('phone', $rawDigits)
                  ->orWhere('phone', '+' . $rawDigits)
                  ->orWhere('phone', 'LIKE', '%' . $last10);
            })->first();

        $userPassword = $request->filled('password') 
            ? Hash::make($request->password) 
            : Hash::make('temp_' . uniqid());

        if ($existingUser) {
            $isAbandoned = $this->isAbandonedSignup($existingUser);

            if ($isAbandoned) {
                // Abandoned sign-up: reset access tokens and overwrite with clean profile
                $existingUser->tokens()->delete();
                $existingUser->update([
                    'name'        => $name,
                    'email'       => $email,
                    'phone'       => $phone,
                    'password'    => $userPassword,
                    'role'        => $role,
                    'is_verified' => false,
                ]);
                $user = $existingUser;
            } else {
                // Account is NOT abandoned: protect role isolation and existing verified accounts
                if ($existingUser->role !== $role) {
                    $msg = $existingUser->role === 'owner'
                        ? "This mobile number is already registered as a Hotel Owner account. It cannot be used to register a Customer account."
                        : "This mobile number is already registered as a Customer account. It cannot be used to register a Hotel Owner account.";

                    return response()->json([
                        'error'   => 'Role restriction error.',
                        'message' => $msg,
                    ], 422);
                }

                // Prevent overwriting any active or verified account
                return response()->json([
                    'error'   => 'Account already exists. Please log in.',
                    'message' => 'Account already exists. Please log in.',
                ], 422);
            }
        } else {
            $user = User::create([
                'name'        => $name,
                'email'       => $email,
                'phone'       => $phone,
                'password'    => $userPassword,
                'role'        => $role,
                'is_verified' => false,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Registered successfully. Proceed to OTP verification.',
            'user_id' => $user->id,
            'id'      => $user->id,
            'user'    => $user,
        ], 201);
    }

    // ============================================================
    // 2. VERIFY OTP
    //    URL:  POST /api/verify-otp
    //    Body: user_id (or phone/email), firebase_id_token (or id_token), password (optional)
    // ============================================================
    public function verifyOtp(Request $request)
    {
        $idToken = $request->input('firebase_id_token') 
                ?? $request->input('id_token') 
                ?? $request->input('idToken') 
                ?? $request->input('token');

        if (empty($idToken)) {
            return response()->json([
                'error'   => 'Firebase ID token is required.',
                'message' => 'Firebase ID token is required.',
            ], 422);
        }

        $user = null;
        if ($request->filled('user_id')) {
            $user = User::find($request->user_id);
        }
        if (!$user && $request->filled('phone')) {
            $phoneInput = trim($request->phone);
            $rawDigits = preg_replace('/[^0-9]/', '', $phoneInput);
            $last10 = strlen($rawDigits) >= 10 ? substr($rawDigits, -10) : $rawDigits;
            $user = User::where(function($q) use ($phoneInput, $rawDigits, $last10) {
                $q->where('phone', $phoneInput)
                  ->orWhere('phone', $rawDigits)
                  ->orWhere('phone', '+' . $rawDigits)
                  ->orWhere('phone', 'LIKE', '%' . $last10);
            })->latest()->first();
        }
        if (!$user && $request->filled('email')) {
            $user = User::where('email', strtolower(trim($request->email)))->first();
        }

        if (!$user) {
            return response()->json([
                'error'   => 'User account not found. Please register first.',
                'message' => 'User account not found. Please register first.',
            ], 404);
        }

        if ($request->filled('role')) {
            $rawRole = strtolower($request->input('role'));
            $requestedRole = in_array($rawRole, ['owner', 'hotel_owner', 'hotelowner']) ? 'owner' : 'user';
            if ($user->role !== 'admin' && $user->role !== $requestedRole) {
                $msg = $user->role === 'owner'
                    ? "This mobile number is registered as a Hotel Owner account. Please log in using the Hotel Owner App."
                    : "This mobile number is registered as a Customer account. Please log in using the Customer App.";

                return response()->json([
                    'error'   => 'Role restriction error.',
                    'message' => $msg,
                ], 422);
            }
        }

        if ($request->filled('password')) {
            $password = $request->password;
            if (strlen($password) < 6) {
                return response()->json([
                    'error'   => 'Password must be at least 6 characters long.',
                    'message' => 'Password must be at least 6 characters long.',
                ], 422);
            }
            $user->password = Hash::make($password);
        }

        // 1. Check for FIREBASE_BYPASS (strictly blocked in production/staging)
        $isBypass = app()->environment('local') && config('app.firebase_bypass') === true && $idToken === 'bypass_token';

        if ($isBypass) {
            $user->firebase_uid = 'bypass_uid_' . $user->id;
            $user->is_verified = true;
            $user->save();

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Phone verified successfully.',
                'token'   => $token,
                'user'    => $user->fresh(),
            ]);
        }

        // 2. Real Firebase ID Token Verification
        try {
            $firebaseAuth  = app(FirebaseAuth::class);
            $verifiedToken = $firebaseAuth->verifyIdToken($idToken);
            $uid           = $verifiedToken->claims()->get('sub');
            $fbPhone       = $verifiedToken->claims()->get('phone_number') ?? '';
        } catch (\Throwable $e) {
            Log::error('Firebase verifyIdToken failed: ' . $e->getMessage());
            return response()->json([
                'error'   => 'Invalid or expired OTP token. Please try again.',
                'message' => 'Invalid or expired OTP token. Please try again.',
            ], 401);
        }

        // 3. Strict Phone Verification against Token Claims
        if (empty($fbPhone)) {
            return response()->json([
                'error'   => 'Firebase token does not contain a verified phone number.',
                'message' => 'Firebase token does not contain a verified phone number.',
            ], 422);
        }

        $cleanUserPhone = preg_replace('/[^0-9]/', '', $user->phone);
        $cleanFbPhone   = preg_replace('/[^0-9]/', '', $fbPhone);
        $last10User     = strlen($cleanUserPhone) >= 10 ? substr($cleanUserPhone, -10) : $cleanUserPhone;
        $last10Fb       = strlen($cleanFbPhone) >= 10 ? substr($cleanFbPhone, -10) : $cleanFbPhone;

        if ($last10User !== $last10Fb) {
            return response()->json([
                'error'   => 'Phone number does not match the registered number.',
                'message' => 'Phone number does not match the registered number.',
            ], 422);
        }

        // 4. Ensure one Firebase UID belongs to only one user account
        $uidInUse = User::where('firebase_uid', $uid)->where('id', '!=', $user->id)->exists();
        if ($uidInUse) {
            return response()->json([
                'error'   => 'This phone number is already verified with another account.',
                'message' => 'This phone number is already verified with another account.',
            ], 422);
        }

        $user->firebase_uid = $uid;
        $user->is_verified  = true;
        $user->save();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Phone verified successfully.',
            'token'   => $token,
            'user'    => $user->fresh(),
        ]);
    }

    // ============================================================
    // 3. LOGIN
    //    URL:  POST /api/login
    //    Body: email (or phone/login_id), password, role
    // ============================================================
    public function login(Request $request)
    {
        $loginInput = trim($request->input('email') ?? $request->input('phone') ?? $request->input('login_id') ?? '');
        $password   = $request->input('password');

        if (empty($loginInput) || empty($password)) {
            return response()->json([
                'error'   => 'Email/Phone and password are required.',
                'message' => 'Email/Phone and password are required.',
            ], 422);
        }

        $hasRoleInput = $request->has('role');
        $rawRole = strtolower($request->input('role', 'user'));
        if (in_array($rawRole, ['owner', 'hotel_owner', 'hotelowner'])) {
            $role = 'owner';
        } else if (in_array($rawRole, ['admin', 'administrator', 'superadmin'])) {
            $role = 'admin';
        } else {
            $role = 'user';
        }

        // Find user by Email OR Phone (matching phone format or last 10 digits)
        $rawDigits = preg_replace('/[^0-9]/', '', $loginInput);
        $last10 = strlen($rawDigits) >= 10 ? substr($rawDigits, -10) : $rawDigits;

        $user = User::where('email', strtolower($loginInput))
            ->orWhere(function($q) use ($loginInput, $rawDigits, $last10) {
                if (!empty($rawDigits)) {
                    $q->where('phone', $loginInput)
                      ->orWhere('phone', $rawDigits)
                      ->orWhere('phone', '+' . $rawDigits)
                      ->orWhere('phone', 'LIKE', '%' . $last10);
                }
            })->first();

        // Security: verify password FIRST to prevent account existence enumeration
        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json([
                'error'   => 'Invalid credentials or role.',
                'message' => 'Invalid credentials or role.',
            ], 401);
        }

        if ($user->role === 'admin') {
            $role = 'admin';
        }

        // Prevent cross-app role mismatch login
        if ($user->role !== 'admin' && $hasRoleInput && !empty($request->input('role')) && $user->role !== $role) {
            $msg = $user->role === 'owner'
                ? "This mobile number is registered as a Hotel Owner account. Please log in using the Hotel Owner App."
                : "This mobile number is registered as a Customer account. Please log in using the Customer App.";

            return response()->json([
                'error'   => 'Role restriction error.',
                'message' => $msg,
            ], 401);
        }

        if ($user->role === 'admin') {
            if (!$user->is_verified) {
                $user->is_verified = true;
                $user->save();
            }
        } elseif ($user->role !== 'owner' && !$user->is_verified) {
            return response()->json([
                'error'   => 'Your account is pending verification. Please contact the admin.',
                'message' => 'Your account is pending verification. Please contact the admin.',
            ], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        $targetHotel = \App\Models\Hotel::where('owner_id', $user->id)->first();
        $ownerProfile = \App\Models\OwnerProfile::where('user_id', $user->id)->first();

        $kycStatus = 'pending_approval';
        $rejectionReason = null;
        $message = 'Please wait for approval by the admin.';

        if ($user->role === 'owner') {
            $isProfileApproved = $ownerProfile && $ownerProfile->status === 'approved';
            $isHotelApproved = $targetHotel && in_array($targetHotel->status, ['approved', 'active']);

            if ($user->is_verified && $isProfileApproved && $isHotelApproved) {
                $kycStatus = 'approved';
                $message = 'Login successful.';
            } elseif (($ownerProfile && $ownerProfile->status === 'rejected') || ($targetHotel && $targetHotel->status === 'rejected')) {
                $kycStatus = 'rejected';
                $rejectionReason = ($ownerProfile && !empty($ownerProfile->rejection_reason))
                    ? $ownerProfile->rejection_reason
                    : (($targetHotel && !empty($targetHotel->rejection_reason)) ? $targetHotel->rejection_reason : 'Admin rejected your application.');
                $message = "Admin rejected your application for this reason: {$rejectionReason}";
            } else {
                $kycStatus = 'pending_approval';
                $message = 'Please wait for approval by the admin.';
            }
        } elseif ($user->role === 'admin') {
            $kycStatus = 'approved';
            $message = 'Login successful.';
        } else {
            $kycStatus = 'approved';
            $message = 'Login successful.';
        }

        $loginNotification = [
            'show'             => true,
            'type'             => $kycStatus === 'rejected' ? 'danger' : ($kycStatus === 'approved' ? 'success' : 'warning'),
            'title'            => $kycStatus === 'rejected' ? 'Application Rejected by Admin' : ($kycStatus === 'approved' ? 'Account Verified' : 'Approval Pending'),
            'message'          => $message,
            'kyc_message'      => $message,
            'rejection_reason' => $rejectionReason,
        ];

        return response()->json([
            'message'          => $message,
            'kyc_message'      => $message,
            'admin_message'    => $rejectionReason ?? $message,
            'token'            => $token,
            'user'             => $user->load('ownerProfile'),
            'kyc_status'       => $kycStatus,
            'rejection_reason' => $rejectionReason,
            'hotel_status'     => $targetHotel ? $targetHotel->status : 'pending',
            'notification'     => $loginNotification,
            'notification_bar' => $loginNotification,
        ]);
    }

    // ============================================================
    // 4. GET LOGGED IN USER
    //    URL:    GET /api/me
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function me(Request $request)
    {
        $user = $request->user();
        if ($user && $user->role !== 'admin' && $user->role !== 'owner' && !$user->is_verified) {
            return response()->json([
                'error'   => 'Your account is pending verification. Please contact the admin.',
                'message' => 'Your account is pending verification. Please contact the admin.',
            ], 403);
        }

        $targetHotel = \App\Models\Hotel::where('owner_id', $user->id)->first();
        $ownerProfile = \App\Models\OwnerProfile::where('user_id', $user->id)->first();

        $kycStatus = 'pending_approval';
        $rejectionReason = null;
        $message = 'Please wait for approval by the admin.';

        if ($user->role === 'owner') {
            $isProfileApproved = $ownerProfile && $ownerProfile->status === 'approved';
            $isHotelApproved = $targetHotel && in_array($targetHotel->status, ['approved', 'active']);

            if ($user->is_verified && $isProfileApproved && $isHotelApproved) {
                $kycStatus = 'approved';
                $message = 'Account verified.';
            } elseif (($ownerProfile && $ownerProfile->status === 'rejected') || ($targetHotel && $targetHotel->status === 'rejected')) {
                $kycStatus = 'rejected';
                $rejectionReason = ($ownerProfile && !empty($ownerProfile->rejection_reason))
                    ? $ownerProfile->rejection_reason
                    : (($targetHotel && !empty($targetHotel->rejection_reason)) ? $targetHotel->rejection_reason : 'Admin rejected your application.');
                $message = "Admin rejected your application for this reason: {$rejectionReason}";
            } else {
                $kycStatus = 'pending_approval';
                $message = 'Please wait for approval by the admin.';
            }
        } elseif ($user->role === 'admin') {
            $kycStatus = 'approved';
            $message = 'Account verified.';
        } else {
            $kycStatus = 'approved';
            $message = 'Account verified.';
        }

        $meNotification = [
            'show'             => true,
            'type'             => $kycStatus === 'rejected' ? 'danger' : ($kycStatus === 'approved' ? 'success' : 'warning'),
            'title'            => $kycStatus === 'rejected' ? 'Application Rejected by Admin' : ($kycStatus === 'approved' ? 'Account Verified' : 'Approval Pending'),
            'message'          => $message,
            'kyc_message'      => $message,
            'rejection_reason' => $rejectionReason,
        ];

        return response()->json([
            'user'             => $user->load('ownerProfile'),
            'kyc_status'       => $kycStatus,
            'rejection_reason' => $rejectionReason,
            'hotel_status'     => $targetHotel ? $targetHotel->status : 'pending',
            'message'          => $message,
            'kyc_message'      => $message,
            'admin_message'    => $rejectionReason ?? $message,
            'notification'     => $meNotification,
            'notification_bar' => $meNotification,
        ]);
    }

    // ============================================================
    // 4.5 UPDATE PROFILE
    //     URL:    POST /api/user/update-profile, POST /api/user/profile, POST /api/profile
    //     Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // 1. Update Name
        $name = $request->input('name') ?? $request->input('full_name') ?? $request->input('user_name');
        if ($name !== null && trim($name) !== '') {
            $user->name = trim($name);
        }

        // 2. Update Email if changed and valid
        if ($request->filled('email') && strtolower(trim($request->email)) !== strtolower($user->email)) {
            $request->validate([
                'email' => 'email|unique:users,email,' . $user->id,
            ]);
            $user->email = strtolower(trim($request->email));
        }

        // 3. Security: Phone is strictly read-only after verification
        if ($request->filled('phone')) {
            $newRaw = preg_replace('/[^0-9]/', '', (string)$request->phone);
            $oldRaw = preg_replace('/[^0-9]/', '', (string)$user->phone);
            $last10New = strlen($newRaw) >= 10 ? substr($newRaw, -10) : $newRaw;
            $last10Old = strlen($oldRaw) >= 10 ? substr($oldRaw, -10) : $oldRaw;

            if ($last10New !== $last10Old) {
                return response()->json([
                    'error'   => 'Phone number cannot be changed directly.',
                    'message' => 'Phone number cannot be changed without OTP verification.',
                ], 422);
            }
        }

        // 4. Update Profile Avatar Image safely
        $imageFile = null;
        foreach (['avatar', 'image', 'profile_image', 'photo', 'file'] as $key) {
            if ($request->hasFile($key)) {
                $imageFile = $request->file($key);
                break;
            }
        }

        if ($imageFile) {
            try {
                $path = SafeUpload::storePublicImage($imageFile, 'avatars');
                if ($path) {
                    $user->avatar = url('storage/' . $path);
                }
            } catch (\Throwable $e) {}
        } else {
            $base64Input = $request->input('avatar') ?? $request->input('profile_image') ?? $request->input('image');
            if (is_string($base64Input) && str_starts_with($base64Input, 'data:image')) {
                try {
                    $path = SafeUpload::storePublicImage($base64Input, 'avatars');
                    if ($path) {
                        $user->avatar = url('storage/' . $path);
                    }
                } catch (\Throwable $e) {}
            }
        }

        $user->save();
        $updatedUser = $user->fresh();

        return response()->json([
            'success' => true,
            'status'  => true,
            'message' => 'Profile updated successfully.',
            'user'    => $updatedUser,
            'data'    => $updatedUser,
        ]);
    }

    // ============================================================
    // 4.6 DELETE ACCOUNT (required by Google Play)
    //     URL:    DELETE /api/account
    //     Header: Authorization: Bearer YOUR_TOKEN
    //
    // Personal data is removed, but booking and payment records are
    // kept (anonymised) because they are needed for tax and accounting.
    // ============================================================
    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            return response()->json([
                'error'   => 'Admin account cannot be deleted from the app.',
                'message' => 'Admin account cannot be deleted from the app.',
            ], 403);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
            if ($user->role === 'owner') {
                // Take the hotel offline
                \App\Models\Hotel::where('owner_id', $user->id)->update(['status' => 'inactive']);

                // Remove KYC files and data
                $profile = \App\Models\OwnerProfile::where('user_id', $user->id)->first();
                if ($profile) {
                    foreach (\App\Models\KycDocument::FIELDS as $field) {
                        $raw = $profile->getRawOriginal($field);
                        if (!empty($raw) && !str_starts_with($raw, 'data:') && !str_starts_with($raw, 'http')) {
                            foreach (['local', 'public'] as $disk) {
                                try { \Illuminate\Support\Facades\Storage::disk($disk)->delete($raw); } catch (\Throwable $e) {}
                            }
                        }
                    }
                    $profile->delete();
                }
                \App\Models\KycDocument::where('user_id', $user->id)->delete();
            }

            $user->tokens()->delete();

            $user->forceFill([
                'name'         => 'Deleted User',
                'email'        => 'deleted_' . $user->id . '@deleted.local',
                'phone'        => 'del_' . $user->id,
                'firebase_uid' => null,
                'is_verified'  => false,
                'avatar'       => null,
                'password'     => Hash::make(\Illuminate\Support\Str::random(40)),
            ])->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Your account has been deleted.',
        ]);
    }

    // ============================================================
    // 5. LOGOUT
    //    URL:    POST /api/logout
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}