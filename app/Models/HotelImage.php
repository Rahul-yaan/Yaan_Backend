<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function getUrlAttribute()
    {
        if (empty($this->image_path)) {
            return null;
        }
        if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            return $this->image_path;
        }
        return url('storage/' . ltrim($this->image_path, '/'));
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
}