<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = [
        'title',
        'description',
        'image_path',
        'target_audience',
        'discount_code',
        'discount_percentage',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'discount_percentage' => 'float',
        'expires_at'          => 'datetime',
    ];

    protected $appends = [
        'image_url',
    ];

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
