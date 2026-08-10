<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionStatusOverride extends Model
{
    protected $fillable = [
        'booking_id',
        'admin_id',
        'old_status',
        'new_status',
        'old_payment_status',
        'new_payment_status',
        'reason',
        'ip_address',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
