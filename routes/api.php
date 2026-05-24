<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\HotelController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ReviewController;



// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');
// Route::post('/register', [AuthController::class, 'register']);
// Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
// Route::post('/login', [AuthController::class, 'login']);

// Route::middleware(['auth:sanctum', 'role:owner'])
//     ->get('/owner/dashboard', function () {
//         return "Owner Dashboard";
//     });

// Route::middleware(['auth:sanctum', 'role:user'])
//     ->get('/user/dashboard', function () {
//         return "User Dashboard";
//     });
Route::post('/firebase-login', [AuthController::class, 'firebaseLogin']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/owner/dashboard', function () {
        return "Owner Dashboard";
    })->middleware('role:owner');

    Route::get('/user/dashboard', function () {
        return "User Dashboard";
    })->middleware('role:user');
});


Route::get('/hotels', [HotelController::class, 'index']);
Route::get('/hotels/{id}', [HotelController::class, 'show']);

Route::post('/bookings', [BookingController::class, 'store']);


Route::get('/hotels/{id}/reviews', [ReviewController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/bookings', [BookingController::class, 'index']);

    Route::post('/reviews', [ReviewController::class, 'store']);

    Route::post('/logout', [AuthController::class, 'logout']);
});