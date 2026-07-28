<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'user_id',
        'hotel_id',
        'check_in',
        'check_out',
        'total_nights',
        'guests',
        'price_per_night',
        'total_amount',
        'status',
        'razorpay_order_id',
        'razorpay_payment_id',
        'transaction_id',
        'payment_status',
        'booking_date',
        'truck_type',
        'truck_no',
        'logistics_name',
        'logistics_number',
        'payment_method',
        'promotion_applied',
        'gst_amount',
        'total_payable',
    ];

    protected $casts = [
        'check_in'          => 'date',
        'check_out'         => 'date',
        'booking_date'      => 'date',
        'price_per_night'   => 'decimal:2',
        'total_amount'      => 'decimal:2',
        'promotion_applied' => 'decimal:2',
        'gst_amount'        => 'decimal:2',
        'total_payable'     => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }
}