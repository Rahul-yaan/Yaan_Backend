<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;

class AuthController extends Controller
{
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

        // Delete old unverified record with same phone
        User::where('phone', $request->phone)
            ->where('is_verified', false)
            ->delete();

        $user = User::create([
            'name'        => $request->name,
            'email'       => $request->email,
            'phone'       => $request->phone,
            'password'    => Hash::make('temp_' . uniqid()),
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
    //    FIREBASE_BYPASS=true in .env means you can put
    //    any text in firebase_id_token for Postman testing.
    //    Set FIREBASE_BYPASS=false before going live.
    // ============================================================
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'user_id'              => 'required|exists:users,id',
            'firebase_id_token'    => 'required|string',
            'password'             => 'required|min:6|confirmed',
        ]);

        $user = User::findOrFail($request->user_id);

        // BYPASS MODE — for Postman testing only
        if (env('FIREBASE_BYPASS', false) === true) {
            $user->update([
                'firebase_uid' => 'bypass_uid_' . $user->id,
                'password'     => Hash::make($request->password),
                'is_verified'  => true,
            ]);

            return response()->json([
                'message' => 'Phone verified. You can now login.',
            ]);
        }

        // PRODUCTION — real Firebase token check
        try {
            $verifiedToken = $this->firebaseAuth->verifyIdToken($request->firebase_id_token);
            $phone         = $verifiedToken->claims()->get('phone_number');
            $uid           = $verifiedToken->claims()->get('sub');
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Invalid or expired OTP. Please try again.'
            ], 401);
        }

        if ($user->phone !== $phone) {
            return response()->json([
                'error' => 'Phone number does not match.'
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
                'error' => 'Invalid email or password.'
            ], 401);
        }

        if (!$user->is_verified) {
            return response()->json([
                'error' => 'Account not verified. Please complete OTP verification first.'
            ], 403);
        }

        // Delete old tokens
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