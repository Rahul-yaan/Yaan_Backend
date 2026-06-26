<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hotel extends Model
{
    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'city',
        'address',
        'latitude',
        'longitude',
        'price_per_night',
        'total_rooms',
        'available_rooms',
        'rating',
        'review_count',
        'status',
    ];

    protected $casts = [
        'latitude'       => 'double',
        'longitude'      => 'double',
        'price_per_night'=> 'decimal:2',
        'rating'         => 'decimal:2',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function images()
    {
        return $this->hasMany(HotelImage::class);
    }

    public function primaryImage()
    {
        return $this->hasOne(HotelImage::class)->where('is_primary', true);
    }

    public function amenities()
    {
        return $this->belongsToMany(Amenity::class, 'hotel_amenities');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}