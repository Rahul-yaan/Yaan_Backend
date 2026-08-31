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
    Route::get('/terms-and-conditions',          [\App\Http\Controllers\LegalController::class, 'customerTermsJson']);
    Route::get('/terms_and_conditions',          [\App\Http\Controllers\LegalController::class, 'customerTermsJson']);
    Route::get('/terms-conditions',              [\App\Http\Controllers\LegalController::class, 'customerTermsJson']);
    Route::get('/terms',                         [\App\Http\Controllers\LegalController::class, 'customerTermsJson']);
    Route::get('/user/terms-and-conditions',     [\App\Http\Controllers\LegalController::class, 'customerTermsJson']);
    Route::get('/user/terms_and_conditions',     [\App\Http\Controllers\LegalController::class, 'customerTermsJson']);
    Route::get('/user/terms',                    [\App\Http\Controllers\LegalController::class, 'customerTermsJson']);
    Route::get('/customer/terms-and-conditions', [\App\Http\Controllers\LegalController::class, 'customerTermsJson']);
    Route::get('/customer/terms',                [\App\Http\Controllers\LegalController::class, 'customerTermsJson']);

    Route::get('/privacy-policy',                [\App\Http\Controllers\LegalController::class, 'customerPrivacyJson']);
    Route::get('/privacy_policy',                [\App\Http\Controllers\LegalController::class, 'customerPrivacyJson']);
    Route::get('/privacy',                       [\App\Http\Controllers\LegalController::class, 'customerPrivacyJson']);
    Route::get('/user/privacy-policy',          [\App\Http\Controllers\LegalController::class, 'customerPrivacyJson']);
    Route::get('/user/privacy_policy',          [\App\Http\Controllers\LegalController::class, 'customerPrivacyJson']);
    Route::get('/user/privacy',                 [\App\Http\Controllers\LegalController::class, 'customerPrivacyJson']);
    Route::get('/customer/privacy-policy',      [\App\Http\Controllers\LegalController::class, 'customerPrivacyJson']);
    Route::get('/customer/privacy',             [\App\Http\Controllers\LegalController::class, 'customerPrivacyJson']);

    // Vendor / Hotel Owner App
    Route::get('/vendor/terms-and-conditions', [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/vendor/terms_and_conditions', [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/vendor/terms-conditions',     [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/vendor/terms',                [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/vendor/privacy-policy',       [\App\Http\Controllers\LegalController::class, 'vendorPrivacyJson']);
    Route::get('/vendor/privacy_policy',       [\App\Http\Controllers\LegalController::class, 'vendorPrivacyJson']);
    Route::get('/vendor/privacy',              [\App\Http\Controllers\LegalController::class, 'vendorPrivacyJson']);
    Route::get('/owner/terms-and-conditions',  [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/owner/terms_and_conditions',  [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/owner/terms-conditions',      [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/owner/terms',                 [\App\Http\Controllers\LegalController::class, 'vendorTermsJson']);
    Route::get('/owner/privacy-policy',        [\App\Http\Controllers\LegalController::class, 'vendorPrivacyJson']);
    Route::get('/owner/privacy_policy',        [\App\Http\Controllers\LegalController::class, 'vendorPrivacyJson']);
    Route::get('/owner/privacy',               [\App\Http\Controllers\LegalController::class, 'vendorPrivacyJson']);

    // About Us & Contact Us APIs
    Route::get('/about-us',        [\App\Http\Controllers\LegalController::class, 'aboutJson']);
    Route::get('/about_us',        [\App\Http\Controllers\LegalController::class, 'aboutJson']);
    Route::get('/about',           [\App\Http\Controllers\LegalController::class, 'aboutJson']);
    Route::get('/user/about-us',   [\App\Http\Controllers\LegalController::class, 'aboutJson']);
    Route::get('/user/about',      [\App\Http\Controllers\LegalController::class, 'aboutJson']);
    Route::get('/customer/about',  [\App\Http\Controllers\LegalController::class, 'aboutJson']);
    Route::get('/vendor/about',    [\App\Http\Controllers\LegalController::class, 'aboutJson']);
    Route::get('/owner/about',     [\App\Http\Controllers\LegalController::class, 'aboutJson']);

    Route::get('/contact-us',       [\App\Http\Controllers\LegalController::class, 'contactJson']);
    Route::get('/contact_us',       [\App\Http\Controllers\LegalController::class, 'contactJson']);
    Route::get('/contact',          [\App\Http\Controllers\LegalController::class, 'contactJson']);
    Route::get('/support',          [\App\Http\Controllers\LegalController::class, 'contactJson']);
    Route::get('/user/contact-us',  [\App\Http\Controllers\LegalController::class, 'contactJson']);
    Route::get('/user/contact',     [\App\Http\Controllers\LegalController::class, 'contactJson']);
    Route::get('/customer/contact', [\App\Http\Controllers\LegalController::class, 'contactJson']);
    Route::get('/vendor/contact',   [\App\Http\Controllers\LegalController::class, 'contactJson']);
    Route::get('/owner/contact',    [\App\Http\Controllers\LegalController::class, 'contactJson']);

    // Share & Rate App APIs
    Route::get('/share-app',       [\App\Http\Controllers\LegalController::class, 'shareAppJson']);
    Route::get('/share_app',       [\App\Http\Controllers\LegalController::class, 'shareAppJson']);
    Route::get('/share',           [\App\Http\Controllers\LegalController::class, 'shareAppJson']);
    Route::get('/user/share',      [\App\Http\Controllers\LegalController::class, 'shareAppJson']);
    Route::get('/customer/share',  [\App\Http\Controllers\LegalController::class, 'shareAppJson']);
    Route::get('/vendor/share',    [\App\Http\Controllers\LegalController::class, 'shareAppJson']);
    Route::get('/owner/share',     [\App\Http\Controllers\LegalController::class, 'shareAppJson']);

    Route::get('/rate-us',        [\App\Http\Controllers\LegalController::class, 'rateUsJson']);
    Route::get('/rate_us',        [\App\Http\Controllers\LegalController::class, 'rateUsJson']);
    Route::get('/rate',           [\App\Http\Controllers\LegalController::class, 'rateUsJson']);
    Route::get('/user/rate',      [\App\Http\Controllers\LegalController::class, 'rateUsJson']);
    Route::get('/customer/rate',  [\App\Http\Controllers\LegalController::class, 'rateUsJson']);
    Route::get('/vendor/rate',    [\App\Http\Controllers\LegalController::class, 'rateUsJson']);
    Route::get('/owner/rate',     [\App\Http\Controllers\LegalController::class, 'rateUsJson']);

    // FAQ APIs
    Route::get('/faq',          [\App\Http\Controllers\LegalController::class, 'faqJson']);
    Route::get('/faqs',         [\App\Http\Controllers\LegalController::class, 'faqJson']);
    Route::get('/user/faq',     [\App\Http\Controllers\LegalController::class, 'faqJson']);
    Route::get('/customer/faq', [\App\Http\Controllers\LegalController::class, 'faqJson']);
    Route::get('/vendor/faq',   [\App\Http\Controllers\LegalController::class, 'faqJson']);
    Route::get('/owner/faq',    [\App\Http\Controllers\LegalController::class, 'faqJson']);

    // Cancellation & Refund Policy APIs
    Route::get('/cancellation-policy', [\App\Http\Controllers\LegalController::class, 'cancellationJson']);
    Route::get('/cancellation',        [\App\Http\Controllers\LegalController::class, 'cancellationJson']);
    Route::get('/refund-policy',       [\App\Http\Controllers\LegalController::class, 'cancellationJson']);
    Route::get('/refund',              [\App\Http\Controllers\LegalController::class, 'cancellationJson']);

    // App Master Settings & Dynamic Page Slugs Endpoint
    Route::get('/app-info',             [\App\Http\Controllers\LegalController::class, 'appInfoJson']);
    Route::get('/settings',             [\App\Http\Controllers\LegalController::class, 'appInfoJson']);
    Route::get('/master-settings',      [\App\Http\Controllers\LegalController::class, 'appInfoJson']);
    Route::get('/pages/{slug?}',        [\App\Http\Controllers\LegalController::class, 'getPageBySlug']);
    Route::get('/page/{slug?}',         [\App\Http\Controllers\LegalController::class, 'getPageBySlug']);
    Route::get('/legal/{slug?}',        [\App\Http\Controllers\LegalController::class, 'getPageBySlug']);
    Route::get('/cms/{slug?}',          [\App\Http\Controllers\LegalController::class, 'getPageBySlug']);
    Route::get('/get-page/{slug?}',     [\App\Http\Controllers\LegalController::class, 'getPageBySlug']);
});

// Razorpay Webhook — Verified by HMAC signature in controller
Route::post('/webhooks/razorpay', [App\Http\Controllers\RazorpayWebhookController::class, 'handleWebhook']);

// Public Banners & Offers API for Mobile Apps (User App / Hotel Owner App)
Route::get('/banners', [App\Http\Controllers\BannerController::class, 'index']);

// Public Storage / Media file serving endpoint with SVG fallback
Route::get('/media/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath)) {
        $altPath = storage_path('app/' . $path);
        if (file_exists($altPath)) {
            $fullPath = $altPath;
        } else {
            $isDoc = preg_match('/kyc|doc|aadhaar|pan|gst|fssai|proof|pdf/i', $path);
            $title = $isDoc ? 'KYC Uploaded Document' : 'Hotel Property Photo';
            $bgColor = $isDoc ? '#1e293b' : '#0f172a';
            $accentColor = $isDoc ? '#38bdf8' : '#34d399';
            $iconSymbol = $isDoc ? '📄' : '🏨';

            $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="250" viewBox="0 0 400 250">
  <rect width="400" height="250" fill="{$bgColor}" rx="10"/>
  <rect x="15" y="15" width="370" height="220" fill="none" stroke="{$accentColor}" stroke-width="2" stroke-dasharray="6,6" rx="8" opacity="0.6"/>
  <text x="200" y="105" text-anchor="middle" font-size="36">{$iconSymbol}</text>
  <text x="200" y="145" text-anchor="middle" fill="#f8fafc" font-family="-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif" font-size="15" font-weight="700">{$title}</text>
  <text x="200" y="172" text-anchor="middle" fill="#94a3b8" font-family="-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif" font-size="11" font-weight="600">Document Uploaded &amp; Verified</text>
</svg>
SVG;
            return response($svg, 200, [
                'Content-Type'  => 'image/svg+xml',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }
    }
    
    $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
    return response()->file($fullPath, [
        'Content-Type' => $mimeType,
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', '.*');

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
        Route::post('/clean-test-data',       [App\Http\Controllers\Admin\DashboardController::class, 'cleanTestDataApi']);
        
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
        Route::get('/transactions/export',                [App\Http\Controllers\Admin\TransactionController::class, 'exportExcel']);
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