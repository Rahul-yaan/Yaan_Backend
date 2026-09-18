<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class HotelImage extends Model
{
    protected $fillable = [
        'hotel_id',
        'image_path',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    protected $appends = ['url'];

    public function setIsPrimaryAttribute($value)
    {
        $this->attributes['is_primary'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function getUrlAttribute()
    {
        $fallbackUrl = 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1000&q=80';

        if (empty($this->image_path)) {
            return $fallbackUrl;
        }
        if (str_starts_with($this->image_path, 'data:')) {
            return $this->image_path;
        }

        $cleanPath = str_replace('\\', '/', $this->image_path);
        if (preg_match('#^https?://[^/]+/(.*)$#i', $cleanPath, $matches)) {
            $cleanPath = $matches[1];
        }
        $cleanPath = ltrim($cleanPath, '/');
        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr($cleanPath, 8);
        }
        $cleanPath = ltrim($cleanPath, '/');

        if (empty($cleanPath)) {
            return $fallbackUrl;
        }

        try {
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($cleanPath)) {
                return url('storage/' . $cleanPath);
            }
        } catch (\Throwable $e) {}

        return $fallbackUrl;
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
}