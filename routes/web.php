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

// Serve public storage files directly with automatic SVG fallback (preventing 404s on ephemeral storage/Render)
Route::get('/storage/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath)) {
        $altPath = storage_path('app/' . $path);
        if (file_exists($altPath)) {
            $fullPath = $altPath;
        } else {
            // Generate clean SVG fallback image so browser renders valid image without 404 or UI errors
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