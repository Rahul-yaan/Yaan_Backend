<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    /**
     * Get active banners and offers for Mobile Apps (User App / Hotel Owner App)
     *
     * GET /api/banners?target=user
     * GET /api/banners?target=owner
     */
    public function index(Request $request)
    {
        $target = strtolower($request->query('target') ?? $request->query('audience') ?? '');

        $query = Banner::whereRaw('is_active IS TRUE')
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>=', now());
            });

        if ($target === 'user') {
            $query->whereIn('target_audience', ['user', 'all']);
        } elseif ($target === 'owner') {
            $query->whereIn('target_audience', ['owner', 'all']);
        }

        $banners = $query->latest()->get();

        return response()->json([
            'success' => true,
            'banners' => $banners,
            'data'    => $banners, // Supports both 'banners' and 'data' keys for client parsing
        ]);
    }
}
