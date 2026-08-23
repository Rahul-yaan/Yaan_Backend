<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'online',
        'message' => 'Yaan Backend API is running successfully on Render.',
        'timestamp' => now()->toIso8601String()
    ]);
});

Route::get('/reset-password', function () {
    return view('reset_password');
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
Route::get('/cancellation-policy', [\App\Http\Controllers\LegalController::class, 'cancellationView']);
Route::get('/cancellation',        [\App\Http\Controllers\LegalController::class, 'cancellationView']);
Route::get('/refund-policy',       [\App\Http\Controllers\LegalController::class, 'cancellationView']);
Route::get('/refund',              [\App\Http\Controllers\LegalController::class, 'cancellationView']);

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

// Serve public storage files directly (bypassing missing symlink issues on Render/production)
Route::get('/storage/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath)) {
        $altPath = storage_path('app/' . $path);
        if (file_exists($altPath)) {
            $fullPath = $altPath;
        } else {
            abort(404, 'Requested document file does not exist on server.');
        }
    }
    
    $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
    return response()->file($fullPath, [
        'Content-Type' => $mimeType,
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', '.*');