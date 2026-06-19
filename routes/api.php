<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

// ============================================================
// PUBLIC ROUTES — no token needed
// FIX: Added throttle middleware to prevent OTP brute-force
//      and registration spam (5 attempts per minute per IP)
// ============================================================

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register',   [AuthController::class, 'register']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/login',      [AuthController::class, 'login']);
});

// ============================================================
// PROTECTED ROUTES — token required in Authorization header
// ============================================================

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me',      [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // User app only
    Route::middleware('role:user')->group(function () {
        Route::get('/user/dashboard', function () {
            return response()->json([
                'message' => 'Welcome to User Dashboard!',
            ]);
        });
    });

    // Owner app only
    Route::middleware('role:owner')->group(function () {
        Route::get('/owner/dashboard', function () {
            return response()->json([
                'message' => 'Welcome to Owner Dashboard!',
            ]);
        });
    });

});