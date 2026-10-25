<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get active notifications, broadcasts, and offers for Mobile Apps (User App / Owner App)
     *
     * GET /api/notifications?target=user
     */
    public function index(Request $request)
    {
        $target = strtolower($request->query('target') ?? $request->query('audience') ?? 'user');

        $query = Banner::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>=', now());
            });

        if ($target === 'user') {
            $query->whereIn('target_audience', ['user', 'all']);
        } elseif ($target === 'owner') {
            $query->whereIn('target_audience', ['owner', 'all']);
        }

        $banners = $query->latest()->get();

        $notifications = $banners->map(function ($b) {
            $hasDiscount = !empty($b->discount_code) || (!empty($b->discount_percentage) && $b->discount_percentage > 0);
            return [
                'id'                  => (string) $b->id,
                'title'               => $b->title,
                'message'             => $b->description ?? '',
                'description'         => $b->description ?? '',
                'type'                => $hasDiscount ? 'offer' : 'news',
                'discount_code'       => $b->discount_code,
                'promo_code'          => $b->discount_code,
                'discount_percentage' => $b->discount_percentage ? (float) $b->discount_percentage : null,
                'discount_percent'    => $b->discount_percentage ? (int) round($b->discount_percentage) : null,
                'valid_till'          => $b->expires_at ? 'Valid till ' . $b->expires_at->format('d M Y') : null,
                'expires_at'          => $b->expires_at ? $b->expires_at->toIso8601String() : null,
                'banner_url'          => $b->image_url,
                'image_url'           => $b->image_url,
                'created_at'          => $b->created_at ? $b->created_at->toIso8601String() : now()->toIso8601String(),
                'is_read'             => false,
            ];
        })->values();

        $supportPhone = \App\Models\Setting::get('support_phone', '+91 7777991598');

        return response()->json([
            'success'       => true,
            'notifications' => $notifications,
            'data'          => $notifications,
            'support_phone' => $supportPhone,
        ]);
    }
}
