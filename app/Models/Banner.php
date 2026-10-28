<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Banner extends Model
{
    protected $fillable = [
        'title',
        'description',
        'image_path',
        'target_audience',
        'discount_code',
        'discount_percentage',
        'max_uses',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'discount_percentage' => 'float',
        'max_uses'            => 'integer',
        'expires_at'          => 'datetime',
    ];

    protected $appends = [
        'image_url',
        'promo_code',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Check if the coupon has remaining usage allowances.
     */
    public function hasAvailableUses(): bool
    {
        if (empty($this->max_uses) || (int)$this->max_uses <= 0) {
            return true;
        }

        $usedCount = Booking::where('banner_id', $this->id)
            ->where('status', '!=', 'cancelled')
            ->count();

        return $usedCount < (int)$this->max_uses;
    }

    /**
     * Exact match for active coupon code with max_uses enforcement.
     */
    public static function findActiveCoupon(string $code, string $audience = 'user'): ?self
    {
        $clean = strtoupper(trim($code));
        if (empty($clean)) {
            return null;
        }

        $banner = self::where('is_active', true)
            ->whereIn('target_audience', [$audience, 'all'])
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>=', now());
            })
            ->where(function ($q) use ($clean) {
                $q->where(DB::raw('UPPER(discount_code)'), $clean);
            })
            ->latest()
            ->first();

        if ($banner && $banner->hasAvailableUses()) {
            return $banner;
        }

        return null;
    }

    /**
     * Active automatic promotional offer (not requiring a manual coupon code).
     */
    public static function activeAutoOffer(string $audience = 'user'): ?self
    {
        return self::where('is_active', true)
            ->whereIn('target_audience', [$audience, 'all'])
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>=', now());
            })
            ->where('discount_percentage', '>', 0)
            ->where(function ($q) {
                $q->whereNull('discount_code')
                  ->orWhere('discount_code', '');
            })
            ->latest()
            ->first();
    }

    public function getPromoCodeAttribute()
    {
        return $this->discount_code;
    }

    public function getImageUrlAttribute()
    {
        if (empty($this->image_path)) {
            return null;
        }

        if (str_starts_with($this->image_path, 'data:') || str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            $url = $this->image_path;
            if (str_starts_with($url, 'http://') && !str_contains($url, 'localhost') && !str_contains($url, '127.0.0.1') && !str_contains($url, '192.168.') && !str_contains($url, '10.0.2.2')) {
                return preg_replace('/^http:/i', 'https:', $url);
            }
            return $url;
        }

        $cleanPath = ltrim($this->image_path, '/');
        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr($cleanPath, 8);
        }

        $url = asset('storage/' . ltrim($cleanPath, '/'));
        if (str_starts_with($url, 'http://') && !str_contains($url, 'localhost') && !str_contains($url, '127.0.0.1') && !str_contains($url, '192.168.') && !str_contains($url, '10.0.2.2')) {
            return preg_replace('/^http:/i', 'https:', $url);
        }

        return $url;
    }
}
