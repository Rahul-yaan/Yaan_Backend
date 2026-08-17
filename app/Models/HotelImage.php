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
        $isTrue = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $this->attributes['is_primary'] = $isTrue ? DB::raw('true') : DB::raw('false');
    }

    public function getUrlAttribute()
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

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
}