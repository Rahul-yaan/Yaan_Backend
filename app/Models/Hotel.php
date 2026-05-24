<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hotel extends Model
{
    
    protected $fillable = [
        'name','city','address','latitude','longitude',
        'price_per_hour','amenities'
    ];

    protected $casts = [
        'amenities' => 'array'
    ];

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
