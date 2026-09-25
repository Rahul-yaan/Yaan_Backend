<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class HotelImage extends Model
{
    protected $fillable = [
        'hotel_id',
        'image_path',
        'image_data',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    protected $hidden = [
        'image_data',
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
            return !empty($this->image_data) ? $this->image_data : $fallbackUrl;
        }

        if (str_starts_with($this->image_path, 'data:')) {
            return $this->image_path;
        }

        // Direct external URLs (e.g. Unsplash, S3, Cloudinary)
        if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            if (!str_contains($this->image_path, '/storage/')) {
                return $this->image_path;
            }
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

        // If file exists on disk, return standard public URL
        try {
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($cleanPath)) {
                return url('storage/' . $cleanPath);
            }
        } catch (\Throwable $e) {}

        // If file is missing on disk but persistent image_data exists in DB, restore file to disk
        if (!empty($this->image_data) && str_starts_with($this->image_data, 'data:')) {
            try {
                $rawB64 = substr($this->image_data, strpos($this->image_data, ',') + 1);
                $decoded = base64_decode($rawB64);
                if ($decoded !== false) {
                    \Illuminate\Support\Facades\Storage::disk('public')->put($cleanPath, $decoded);
                    return url('storage/' . $cleanPath);
                }
            } catch (\Throwable $e) {}
        }

        // Do not return kyc documents as hotel image URLs
        if (str_contains($cleanPath, 'kyc_docs/')) {
            return $fallbackUrl;
        }

        // Return storage URL which will be handled by /storage/{path} fallback route
        return url('storage/' . $cleanPath);
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
}