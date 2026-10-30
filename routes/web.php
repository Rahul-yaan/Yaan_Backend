<?php

use Illuminate\Support\Facades\Route;

// Status and keep-alive URLs. They skip the "web" middleware (session, cookies, CSRF), so a ping
// never reads or writes the sessions table and never wakes the Neon database.
Route::withoutMiddleware('web')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'status' => 'online',
            'message' => 'Yaan Backend API is running successfully on Render.',
            'timestamp' => now()->toIso8601String()
        ]);
    });

    Route::get('/ping', function () {
        return response()->json([
            'status' => 'ok',
            'message' => 'Yaan Backend is awake',
            'timestamp' => now()->toIso8601String()
        ]);
    });

    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'yaan-backend',
            'timestamp' => now()->toIso8601String()
        ]);
    });
});

Route::get('/reset-password', function () {
    return view('reset_password');
});

// Secure Media Serving Route
Route::get('/media/{path}', [\App\Http\Controllers\MediaController::class, 'serve'])->where('path', '.*');

// Legacy Android/Vendor API endpoints fallback
Route::match(['get', 'post'], '/api.php', function () {
    $amenities = \App\Models\Amenity::whereNotIn(\Illuminate\Support\Facades\DB::raw('LOWER(name)'), ['men', 'women'])
        ->orderBy('id', 'asc')
        ->get();

    $jsonData = $amenities->map(function ($a) {
        return [
            'amenitie_id'     => (string) $a->id,
            'amenitie_name'   => $a->name,
            'imageurl'        => '',
            'amenitie_status' => '1',
        ];
    })->values();

    return response()->json([
        'status'    => 'success',
        'data'      => $amenities,
        'amenities' => $amenities,
        'JSON_DATA' => $jsonData,
    ]);
});
Route::match(['get', 'post'], '/admin/api.php', function () {
    $amenities = \App\Models\Amenity::whereNotIn(\Illuminate\Support\Facades\DB::raw('LOWER(name)'), ['men', 'women'])
        ->orderBy('id', 'asc')
        ->get();

    $jsonData = $amenities->map(function ($a) {
        return [
            'amenitie_id'     => (string) $a->id,
            'amenitie_name'   => $a->name,
            'imageurl'        => '',
            'amenitie_status' => '1',
        ];
    })->values();

    return response()->json([
        'status'    => 'success',
        'data'      => $amenities,
        'amenities' => $amenities,
        'JSON_DATA' => $jsonData,
    ]);
});

// Terms & Conditions and Privacy Policy Public Web Pages
// Customer App
Route::get('/terms-and-conditions', [\App\Http\Controllers\LegalController::class, 'termsView']);
Route::get('/terms',                [\App\Http\Controllers\LegalController::class, 'termsView']);
Route::get('/privacy-policy',       [\App\Http\Controllers\LegalController::class, 'privacyView']);
Route::get('/privacy',              [\App\Http\Controllers\LegalController::class, 'privacyView']);

// Vendor / Hotel Owner App
Route::get('/vendor/terms-and-conditions', [\App\Http\Controllers\LegalController::class, 'vendorTermsView']);
Route::get('/vendor/terms',                [\App\Http\Controllers\LegalController::class, 'vendorTermsView']);
Route::get('/vendor/privacy-policy',       [\App\Http\Controllers\LegalController::class, 'vendorPrivacyView']);
Route::get('/vendor/privacy',              [\App\Http\Controllers\LegalController::class, 'vendorPrivacyView']);
Route::get('/owner/terms-and-conditions',  [\App\Http\Controllers\LegalController::class, 'vendorTermsView']);
Route::get('/owner/privacy-policy',        [\App\Http\Controllers\LegalController::class, 'vendorPrivacyView']);

// About Us & Contact Us Web Pages
Route::get('/about-us',   [\App\Http\Controllers\LegalController::class, 'aboutView']);
Route::get('/about',      [\App\Http\Controllers\LegalController::class, 'aboutView']);
Route::get('/contact-us', [\App\Http\Controllers\LegalController::class, 'contactView']);
Route::get('/contact',    [\App\Http\Controllers\LegalController::class, 'contactView']);
Route::get('/support',    [\App\Http\Controllers\LegalController::class, 'contactView']);
// Account deletion page (URL required by Google Play Console)
Route::get('/account-deletion', function () {
    return view('account_deletion', [
        'supportPhone' => \App\Models\Setting::get('support_phone', '+91 7777991598'),
        'supportEmail' => 'support@yaanapp.com',
    ]);
});
Route::get('/delete-account', fn () => redirect('/account-deletion'));

Route::get('/cancellation-policy', [\App\Http\Controllers\LegalController::class, 'cancellationView']);
Route::get('/cancellation',        [\App\Http\Controllers\LegalController::class, 'cancellationView']);
Route::get('/refund-policy',       [\App\Http\Controllers\LegalController::class, 'cancellationView']);
Route::get('/refund',              [\App\Http\Controllers\LegalController::class, 'cancellationView']);

// Public storage files: hotel photos, avatars and banners only (KYC documents are never served here;
// the admin panel loads them from GET /api/admin/owners/{id}/documents/{field}).
Route::get('/storage/{path}', [\App\Http\Controllers\MediaController::class, 'serve'])->where('path', '.*');

// Explicitly serve Admin Portal with Cache-Control headers to ensure latest JS is always fetched
Route::get('/admin/{any?}', function () {
    $indexPath = public_path('admin/index.html');
    if (!file_exists($indexPath)) {
        abort(404, 'Admin Portal index file not found.');
    }
    return response()->file($indexPath, [
        'Content-Type'  => 'text/html',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma'        => 'no-cache',
        'Expires'       => '0',
    ]);
})->where('any', '.*');