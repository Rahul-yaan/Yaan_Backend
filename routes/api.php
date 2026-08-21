<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ForgotPasswordController;

// ============================================================
// PUBLIC ROUTES — no token needed
// ============================================================

Route::middleware('throttle:60,1')->group(function () {
    Route::post('/register',        [AuthController::class, 'register']);
    Route::post('/verify-otp',      [AuthController::class, 'verifyOtp']);
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
    Route::post('/reset-password',  [ForgotPasswordController::class, 'resetPassword']);
    
    // Legal & Policy APIs for Mobile Apps (User App & Hotel App)
    // Customer App
    Route::get('/terms-and-conditions', [\App\Http\Controllers\LegalController::class, 'termsJson']);
    Route::get('/terms-conditions',     [\App\Http\Controllers\LegalController::class, 'termsJson']);
    Route::get('/terms',                [\App\Http\Controllers\LegalController::class, 'termsJson']);
    Route::get('/privacy-policy',       [\App\Http\Controllers\LegalController::class, 'privacyJson']);
    Route::get('/privacy',              [\App\Http\Controllers\LegalController::class, 'privacyJson']);
    Route::get('/pages/terms',          [\App\Http\Controllers\LegalController::class, 'termsJson']);
    Route::get('/pages/privacy',        [\App\Http\Controllers\LegalController::class, 'privacyJson']);
    Route::get('/legal/terms',          [\App\Http\Controllers\LegalController::class, 'termsJson']);
    Route::get('/legal/privacy',        [\App\Http\Controllers\LegalController::class, 'privacyJson']);

    // Vendor / Hotel Owner App
    Route::get('/vendor/terms-and-conditions', [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/vendor/terms-conditions',     [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/vendor/terms',                [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/vendor/privacy-policy',       [\App\Http\Controllers\LegalController::class, 'vendorPrivacyJson']);
    Route::get('/vendor/privacy',              [\App\Http\Controllers\LegalController::class, 'vendorPrivacyJson']);
    Route::get('/owner/terms-and-conditions',  [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/owner/terms-conditions',      [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/owner/terms',                 [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/owner/privacy-policy',        [\App\Http\Controllers\LegalController::class, 'vendorPrivacyJson']);
    Route::get('/owner/privacy',               [\App\Http\Controllers\LegalController::class, 'vendorPrivacyJson']);
});

// Razorpay Webhook — Verified by HMAC signature in controller
Route::post('/webhooks/razorpay', [App\Http\Controllers\RazorpayWebhookController::class, 'handleWebhook']);

// Public Banners & Offers API for Mobile Apps (User App / Hotel Owner App)
Route::get('/banners', [App\Http\Controllers\BannerController::class, 'index']);

// ============================================================
// PROTECTED ROUTES — token required in Authorization header
// ============================================================

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/me',                   [AuthController::class, 'me']);
    Route::get('/user/profile',         [AuthController::class, 'me']);
    Route::get('/profile',              [AuthController::class, 'me']);

    Route::post('/user/update-profile', [AuthController::class, 'updateProfile']);
    Route::post('/user/profile',        [AuthController::class, 'updateProfile']);
    Route::post('/profile',             [AuthController::class, 'updateProfile']);
    Route::put('/user/profile',         [AuthController::class, 'updateProfile']);
    Route::put('/profile',              [AuthController::class, 'updateProfile']);

    Route::post('/logout',              [AuthController::class, 'logout']);

    // User routes
    Route::middleware('role:user')->group(function () {
        Route::get('/user/dashboard', function () {
            return response()->json(['message' => 'Welcome to User Dashboard!']);
        });
        Route::get('/hotels/search',           [App\Http\Controllers\User\HotelController::class, 'search']);
        Route::get('/hotels/{id}/reviews',     [App\Http\Controllers\User\ReviewController::class, 'index']);
        Route::get('/hotels/{id}',             [App\Http\Controllers\User\HotelController::class, 'show']);
        Route::post('/bookings',               [App\Http\Controllers\User\BookingController::class, 'store']);
        Route::get('/bookings/my',             [App\Http\Controllers\User\BookingController::class, 'myBookings']);
        Route::post('/bookings/{id}/cancel',   [App\Http\Controllers\User\BookingController::class, 'cancel']);
        Route::post('/bookings/{id}/verify-payment', [App\Http\Controllers\User\BookingController::class, 'verifyPayment']);
        Route::post('/reviews',                [App\Http\Controllers\User\ReviewController::class, 'store']);
    });

    // Owner routes
    Route::middleware('role:owner')->prefix('owner')->group(function () {
        Route::get('/hotels',              [App\Http\Controllers\Owner\HotelController::class, 'index']);
        Route::post('/hotels',             [App\Http\Controllers\Owner\HotelController::class, 'store']);
        Route::put('/hotels/{id}',         [App\Http\Controllers\Owner\HotelController::class, 'update']);
        Route::delete('/hotels/{id}',      [App\Http\Controllers\Owner\HotelController::class, 'destroy']);
        Route::post('/hotels/{id}/images', [App\Http\Controllers\Owner\HotelController::class, 'uploadImages']);
        Route::get('/dashboard',           [App\Http\Controllers\Owner\DashboardController::class, 'index']);
        Route::get('/bookings',            [App\Http\Controllers\Owner\BookingController::class, 'index']);
        Route::get('/bookings/{id}',       [App\Http\Controllers\Owner\BookingController::class, 'show']);
        Route::put('/bookings/{id}/status',[App\Http\Controllers\Owner\BookingController::class, 'updateStatus']);
        Route::get('/profile',             [App\Http\Controllers\Owner\ProfileController::class, 'show']);
        Route::post('/profile',            [App\Http\Controllers\Owner\ProfileController::class, 'update']);
    });

    // Admin routes
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/dashboard',             [App\Http\Controllers\Admin\DashboardController::class, 'index']);
        Route::get('/dashboard/ai-analysis', [App\Http\Controllers\Admin\DashboardController::class, 'getAiAnalysis']);
        Route::post('/dashboard/target-goal', [App\Http\Controllers\Admin\DashboardController::class, 'updateTargetGoal']);
        
        // Hotels
        Route::get('/hotels/locations',              [App\Http\Controllers\Admin\HotelController::class, 'getLocations']);
        Route::get('/hotels',                        [App\Http\Controllers\Admin\HotelController::class, 'index']);
        Route::get('/hotels/{id}',                   [App\Http\Controllers\Admin\HotelController::class, 'show']);
        Route::put('/hotels/{id}/status',            [App\Http\Controllers\Admin\HotelController::class, 'updateStatus']);
        Route::post('/hotels/{id}/images',           [App\Http\Controllers\Admin\HotelController::class, 'uploadImage']);
        Route::delete('/hotels/{id}/images/{imageId}', [App\Http\Controllers\Admin\HotelController::class, 'deleteImage']);

        // Owners
        Route::get('/owners',              [App\Http\Controllers\Admin\OwnerController::class, 'index']);
        Route::get('/owners/{id}',         [App\Http\Controllers\Admin\OwnerController::class, 'show']);
        Route::put('/owners/{id}/verify',  [App\Http\Controllers\Admin\OwnerController::class, 'verifyOwner']);
        Route::post('/owners/{id}/reset-kyc', [App\Http\Controllers\Admin\OwnerController::class, 'resetKyc']);

        // Users / Customers
        Route::get('/users',               [App\Http\Controllers\Admin\UserController::class, 'index']);
        Route::get('/users/{id}',          [App\Http\Controllers\Admin\UserController::class, 'show']);
        Route::put('/users/{id}/status',   [App\Http\Controllers\Admin\UserController::class, 'toggleStatus']);

        // Bookings
        Route::get('/bookings',            [App\Http\Controllers\Admin\BookingController::class, 'index']);
        Route::get('/bookings/{id}',       [App\Http\Controllers\Admin\BookingController::class, 'show']);
        Route::put('/bookings/{id}/status',[App\Http\Controllers\Admin\BookingController::class, 'updateStatus']);

        // Transactions & Razorpay Management
        Route::get('/transactions',                       [App\Http\Controllers\Admin\TransactionController::class, 'index']);
        Route::get('/transactions/{id}',                   [App\Http\Controllers\Admin\TransactionController::class, 'show']);
        Route::get('/transactions/{id}/invoice',           [App\Http\Controllers\Admin\TransactionController::class, 'getInvoice']);
        Route::post('/transactions/{id}/verify-razorpay',  [App\Http\Controllers\Admin\TransactionController::class, 'verifyRazorpay']);
        Route::post('/transactions/{id}/refund',           [App\Http\Controllers\Admin\TransactionController::class, 'refundTransaction']);
        Route::put('/transactions/{id}/status',            [App\Http\Controllers\Admin\TransactionController::class, 'updateStatus']);

        // Reviews
        Route::get('/reviews',             [App\Http\Controllers\Admin\ReviewController::class, 'index']);
        Route::delete('/reviews/{id}',      [App\Http\Controllers\Admin\ReviewController::class, 'destroy']);

        // Banners & Offers
        Route::get('/banners',             [App\Http\Controllers\Admin\BannerController::class, 'index']);
        Route::post('/banners',            [App\Http\Controllers\Admin\BannerController::class, 'store']);
        Route::put('/banners/{id}',        [App\Http\Controllers\Admin\BannerController::class, 'update']);
        Route::put('/banners/{id}/status', [App\Http\Controllers\Admin\BannerController::class, 'toggleStatus']);
        Route::delete('/banners/{id}',     [App\Http\Controllers\Admin\BannerController::class, 'destroy']);
    });

});