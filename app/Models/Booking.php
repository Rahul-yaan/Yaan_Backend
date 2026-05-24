<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
     protected $fillable = [
        'hotel_id','customer_name','vehicle_number',
        'booking_date','from_time','to_time',
        'total_price','payment_method'
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
}
