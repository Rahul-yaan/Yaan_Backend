<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ForgotPasswordController;

// ============================================================
// PUBLIC ROUTES — no token needed
// ============================================================

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register',        [AuthController::class, 'register']);
    Route::post('/verify-otp',      [AuthController::class, 'verifyOtp']);
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
    Route::post('/reset-password',  [ForgotPasswordController::class, 'resetPassword']);
});

// ============================================================
// PROTECTED ROUTES — token required in Authorization header
// ============================================================

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me',      [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('role:user')->group(function () {
        Route::get('/user/dashboard', function () {
            return response()->json(['message' => 'Welcome to User Dashboard!']);
        });
        
        // search must be before {ID}

        Route::get('/hotels/search', [App\Http\Controllers\User\HotelController::class, 'search']);
        Route::get('/hotels/{id}/reviews',     [App\Http\Controllers\User\ReviewController::class, 'index']);
        Route::get('/hotels/{id}',   [App\Http\Controllers\User\HotelController::class, 'show']);
        Route::post('/bookings',              [App\Http\Controllers\User\BookingController::class, 'store']);
        Route::get('/bookings/my',            [App\Http\Controllers\User\BookingController::class, 'myBookings']);
        Route::post('/bookings/{id}/cancel',  [App\Http\Controllers\User\BookingController::class, 'cancel']);

        Route::post('/reviews',                [App\Http\Controllers\User\ReviewController::class, 'store']);
        
    });

    Route::middleware('role:owner')->group(function () {
        Route::get('/owner/dashboard', function () {
            return response()->json(['message' => 'Welcome to Owner Dashboard!']);
        });
    });

    // Owner routes
    Route::middleware('role:owner')->prefix('owner')->group(function () {
    Route::get('/hotels',                    [App\Http\Controllers\Owner\HotelController::class, 'index']);
    Route::post('/hotels',                   [App\Http\Controllers\Owner\HotelController::class, 'store']);
    Route::put('/hotels/{id}',               [App\Http\Controllers\Owner\HotelController::class, 'update']);
    Route::delete('/hotels/{id}',            [App\Http\Controllers\Owner\HotelController::class, 'destroy']);
    Route::post('/hotels/{id}/images',       [App\Http\Controllers\Owner\HotelController::class, 'uploadImages']);
});

});