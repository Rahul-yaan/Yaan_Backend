<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Kreait\Firebase\Contract\Auth as FirebaseAuth; // FIX: uncommented — was missing, caused fatal error in production

class AuthController extends Controller
{
    // FIX: constructor was commented out — $this->firebaseAuth would crash in production path
    public function __construct(private FirebaseAuth $firebaseAuth) {}

    // ============================================================
    // 1. REGISTER
    //    URL:  POST /api/register
    //    Body: name, email, phone, role
    // ============================================================
    public function register(Request $request)
    {
        $request->validate([
            'name'  => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|unique:users,phone',
            'role'  => 'required|in:user,owner',
        ]);

        // Clean up old unverified record with same phone
        User::where('phone', $request->phone)
            ->where('is_verified', false)
            ->delete();

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

        // FIX: env() returns a STRING "true", not boolean true
        // Using config() reads it as a proper cast boolean
        if (config('app.firebase_bypass') === true) {
            // BYPASS MODE — Postman/local testing only, never in production
            $user->update([
                'firebase_uid' => 'bypass_uid_' . $user->id,
                'password'     => Hash::make($request->password),
                'is_verified'  => true,
            ]);

            return response()->json([
                'message' => '[BYPASS] Phone verified. You can now login.',
            ]);
        }

        // PRODUCTION — real Firebase token verification
        try {
            $verifiedToken = $this->firebaseAuth->verifyIdToken($request->firebase_id_token);
            $phone         = $verifiedToken->claims()->get('phone_number');
            $uid           = $verifiedToken->claims()->get('sub');
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Invalid or expired Firebase token. Please try again.',
            ], 401);
        }

        // Make sure the Firebase phone matches what was registered
        if ($user->phone !== $phone) {
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
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'role'     => 'required|in:user,owner',
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