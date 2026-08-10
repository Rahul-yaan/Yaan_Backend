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