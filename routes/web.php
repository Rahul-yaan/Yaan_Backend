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