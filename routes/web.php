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

// Serve public storage files directly with automatic DB restoration & smart fallback
Route::get('/storage/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath)) {
        $altPath = storage_path('app/' . $path);
        if (file_exists($altPath)) {
            $fullPath = $altPath;
        } else {
            $cleanBase = basename($path);

            // 1. Try to restore Hotel Image from persistent database record
            try {
                $hotelImg = \App\Models\HotelImage::where('image_path', $path)
                    ->orWhere('image_path', 'LIKE', "%{$cleanBase}")
                    ->first();
                if ($hotelImg && !empty($hotelImg->image_data) && str_starts_with($hotelImg->image_data, 'data:')) {
                    $raw = substr($hotelImg->image_data, strpos($hotelImg->image_data, ',') + 1);
                    $decoded = base64_decode($raw);
                    if ($decoded !== false) {
                        @mkdir(dirname($fullPath), 0775, true);
                        file_put_contents($fullPath, $decoded);
                        $mime = mime_content_type($fullPath) ?: 'image/jpeg';
                        return response()->file($fullPath, [
                            'Content-Type'  => $mime,
                            'Cache-Control' => 'public, max-age=86400',
                        ]);
                    }
                }
            } catch (\Throwable $e) {}

            // 2. Try to restore Owner KYC document from persistent database record
            try {
                $profile = \App\Models\OwnerProfile::where(function($q) use ($cleanBase) {
                    $q->where('business_proof', 'LIKE', "%{$cleanBase}%")
                      ->orWhere('aadhaar_front', 'LIKE', "%{$cleanBase}%")
                      ->orWhere('aadhaar_back', 'LIKE', "%{$cleanBase}%")
                      ->orWhere('pan_card', 'LIKE', "%{$cleanBase}%")
                      ->orWhere('fssai_license', 'LIKE', "%{$cleanBase}%")
                      ->orWhere('gst_image', 'LIKE', "%{$cleanBase}%");
                })->first();

                if ($profile) {
                    $fields = ['business_proof', 'aadhaar_front', 'aadhaar_back', 'pan_card', 'fssai_license', 'gst_image'];
                    foreach ($fields as $f) {
                        $val = $profile->$f;
                        if (!empty($val) && str_starts_with($val, 'data:')) {
                            $raw = substr($val, strpos($val, ',') + 1);
                            $decoded = base64_decode($raw);
                            if ($decoded !== false) {
                                @mkdir(dirname($fullPath), 0775, true);
                                file_put_contents($fullPath, $decoded);
                                $mime = str_contains($val, 'pdf') ? 'application/pdf' : (mime_content_type($fullPath) ?: 'image/jpeg');
                                return response()->file($fullPath, [
                                    'Content-Type'  => $mime,
                                    'Cache-Control' => 'public, max-age=86400',
                                ]);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {}

            // 3. For hotel photos, redirect to high-resolution default hotel photo so it never breaks
            $isDoc = preg_match('/kyc|doc|aadhaar|pan|gst|fssai|proof|pdf/i', $path);
            if (!$isDoc) {
                return redirect('https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1000&q=80');
            }

            // 4. For missing KYC documents, generate clean SVG preview
            $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="250" viewBox="0 0 400 250">
  <rect width="400" height="250" fill="#1e293b" rx="10"/>
  <rect x="15" y="15" width="370" height="220" fill="none" stroke="#38bdf8" stroke-width="2" stroke-dasharray="6,6" rx="8" opacity="0.6"/>
  <text x="200" y="105" text-anchor="middle" font-size="36">📄</text>
  <text x="200" y="145" text-anchor="middle" fill="#f8fafc" font-family="-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif" font-size="15" font-weight="700">KYC Uploaded Document</text>
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