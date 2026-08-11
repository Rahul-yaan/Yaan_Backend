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
            return preg_replace('/^http:/i', 'https:', $this->image_path);
        }
        $cleanPath = ltrim($this->image_path, '/');
        if (str_starts_with($cleanPath, 'storage/')) {
            $cleanPath = substr($cleanPath, 8);
        }
        $url = asset('storage/' . ltrim($cleanPath, '/'));
        return preg_replace('/^http:/i', 'https:', $url);
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
}