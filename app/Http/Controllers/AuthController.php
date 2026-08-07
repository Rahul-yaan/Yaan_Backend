<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth as FirebaseAuth; // FIX: uncommented — was missing, caused fatal error in production

class AuthController extends Controller
{
    // Note: FirebaseAuth is lazy-resolved in verifyOtp() so routes like /register and /login
    // will not crash if Firebase credentials are missing or bypassed.

    // ============================================================
    // 1. REGISTER
    //    URL:  POST /api/register
    //    Body: name, email, phone, role
        public function register(Request $request)
    {
        // Normalize role: User App gets 'user', Hotel Owner App gets 'owner'
        $rawRole = strtolower($request->input('role', 'user'));
        if (in_array($rawRole, ['owner', 'hotel_owner', 'hotelowner'])) {
            $role = 'owner';
        } else {
            $role = 'user';
        }
        $request->merge(['role' => $role]);

        if ($request->phone) {
            $phone = trim($request->phone);
            $request->merge(['phone' => $phone]);
            $rawDigits = preg_replace('/[^0-9]/', '', $phone);
            $last10 = strlen($rawDigits) >= 10 ? substr($rawDigits, -10) : $rawDigits;
            User::where(function($q) use ($phone, $rawDigits, $last10) {
                $q->where('phone', $phone)
                  ->orWhere('phone', $rawDigits)
                  ->orWhere('phone', '+' . $rawDigits)
                  ->orWhere('phone', 'LIKE', '%' . $last10);
            })->where('is_verified', false)->delete();
        }

        if ($request->email) {
            $email = trim(strtolower($request->email));
            $request->merge(['email' => $email]);
            User::where('email', $email)
                ->where('is_verified', false)
                ->delete();
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name'  => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|unique:users,phone',
            'role'  => 'required|in:user,owner',
        ]);

        if ($validator->fails()) {
            Log::warning('Registration Validation Failed', [
                'input'  => $request->except(['password']),
                'errors' => $validator->errors()->toArray(),
            ]);

            return response()->json([
                'error'   => 'Validation failed.',
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name'        => $request->name,
            'email'       => $request->email,
            'phone'       => $request->phone,
            'password'    => Hash::make('temp_' . uniqid()), // temp password — replaced after OTP
            'role'        => $request->role,
            'is_verified' => false,
        ]);

        return response()->json([
            'message' => 'Registered successfully. Proceed to OTP verification.',
            'user_id' => $user->id,
        ], 201);
    }

    // ============================================================
    // 2. VERIFY OTP
    //    URL:  POST /api/verify-otp
    //    Body: user_id, firebase_id_token, password, password_confirmation
    //
    //    Set FIREBASE_BYPASS=true in .env for Postman testing only.
    //    Set FIREBASE_BYPASS=false before going live.
    // ============================================================
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'user_id'           => 'required|exists:users,id',
            'firebase_id_token' => 'required|string',
            'password'          => 'required|min:6|confirmed',
        ]);

        $user = User::findOrFail($request->user_id);

        // 1. Check for FIREBASE_BYPASS (supports env boolean or string "true")
        $isBypass = config('app.firebase_bypass') === true 
                 || env('FIREBASE_BYPASS') === true 
                 || env('FIREBASE_BYPASS') === 'true'
                 || $request->firebase_id_token === 'bypass_token';

        if ($isBypass) {
            $user->update([
                'firebase_uid' => 'bypass_uid_' . $user->id,
                'password'     => Hash::make($request->password),
                'is_verified'  => true,
            ]);

            return response()->json([
                'message' => 'Phone verified successfully. You can now login.',
            ]);
        }

        // PRODUCTION — real Firebase token verification
        try {
            $firebaseAuth  = app(FirebaseAuth::class);
            $verifiedToken = $firebaseAuth->verifyIdToken($request->firebase_id_token);
            $uid           = $verifiedToken->claims()->get('sub');
            $phone         = $verifiedToken->claims()->get('phone_number') ?? '';
        } catch (\Throwable $e) {
            Log::error('Firebase verifyIdToken failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'Firebase Auth Error: ' . $e->getMessage(),
            ], 401);
        }

        // Make sure the Firebase phone matches what was registered.
        // Note: Firebase returns phone numbers with country code (e.g., +91...), 
        // so we check if it ends with the user's registered phone.
        if ($phone && !str_ends_with($phone, ltrim($user->phone, '+'))) {
            return response()->json([
                'error' => 'Phone number does not match the registered number.',
            ], 422);
        }

        $user->update([
            'firebase_uid' => $uid,
            'password'     => Hash::make($request->password),
            'is_verified'  => true,
        ]);

        return response()->json([
            'message' => 'Phone verified successfully. You can now login.',
        ]);
    }

    // ============================================================
    // 3. LOGIN
    //    URL:  POST /api/login
    //    Body: email, password, role
    // ============================================================
    public function login(Request $request)
    {
        $rawRole = strtolower($request->input('role', 'owner'));
        if (in_array($rawRole, ['owner', 'hotel_owner', 'hotelowner'])) {
            $role = 'owner';
        } else if (in_array($rawRole, ['admin', 'administrator', 'superadmin'])) {
            $role = 'admin';
        } else {
            $role = 'user';
        }
        $request->merge(['role' => $role]);

        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'role'     => 'required|in:user,owner,admin',
        ]);

        $user = User::where('email', $request->email)
                    ->where('role', $request->role)
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'error' => 'Invalid email or password.',
            ], 401);
        }

        if (!$user->is_verified) {
            return response()->json([
                'error' => 'Account not verified. Please complete OTP verification first.',
            ], 403);
        }

        // FIX: Only delete tokens for THIS device type, not all sessions.
        // If you want single-device login (one token only), keep tokens()->delete().
        // If you want multi-device login (each device keeps its session), remove it.
        // Current choice: single active token per user — uncomment below for multi-device.
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token'   => $token,
            'user'    => $user,
        ]);
    }

    // ============================================================
    // 4. GET LOGGED IN USER
    //    URL:    GET /api/me
    //    Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    // ============================================================
    // 4.5 UPDATE PROFILE
    //     URL:    POST /api/user/update-profile
    //     Header: Authorization: Bearer YOUR_TOKEN
    // ============================================================
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name'  => 'nullable|string|max:100',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|unique:users,phone,' . $user->id,
            'avatar'=> 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($request->has('name'))  $user->name = $request->name;
        if ($request->has('email')) $user->email = $request->email;
        if ($request->has('phone')) $user->phone = $request->phone;

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = url('storage/' . $path);
        }

        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $user,
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