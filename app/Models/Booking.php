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
        'temp_transaction_id',
        'payment_status',
        'cancellation_reason',
        'gateway_response',
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

    protected $appends = [
        'display_transaction_id',
        'is_confirmed',
        'region_time_formatted',
        'refund_time_formatted',
    ];

    public function getDisplayTransactionIdAttribute()
    {
        if (!empty($this->transaction_id)) {
            return $this->transaction_id;
        }
        if (!empty($this->temp_transaction_id)) {
            return $this->temp_transaction_id;
        }
        if (!empty($this->razorpay_order_id)) {
            return 'TMP-' . $this->razorpay_order_id;
        }
        return 'TMP-' . str_pad($this->id, 8, '0', STR_PAD_LEFT);
    }

    public function getIsConfirmedAttribute()
    {
        return $this->payment_status === 'paid' || $this->status === 'confirmed';
    }

    public function getRegionTimeFormattedAttribute()
    {
        $dt = $this->created_at ? $this->created_at->timezone('Asia/Kolkata') : now()->timezone('Asia/Kolkata');
        return $dt->format('d M Y, h:i:s A') . ' IST';
    }

    public function getRefundTimeFormattedAttribute()
    {
        $isRefunded = in_array($this->payment_status, ['refunded', 'refund_initiated']) || str_contains(strtolower($this->cancellation_reason ?? ''), 'refund');
        if (!$isRefunded) {
            return null;
        }

        if (!empty($this->gateway_response)) {
            $gw = json_decode($this->gateway_response, true);
            if (is_array($gw)) {
                $epoch = $gw['created_at'] ?? ($gw['refund']['entity']['created_at'] ?? null);
                if ($epoch && is_numeric($epoch)) {
                    return \Carbon\Carbon::createFromTimestamp($epoch)->timezone('Asia/Kolkata')->format('d M Y, h:i:s A') . ' IST';
                }
            }
        }

        $dt = $this->updated_at ? $this->updated_at->timezone('Asia/Kolkata') : now()->timezone('Asia/Kolkata');
        return $dt->format('d M Y, h:i:s A') . ' IST';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function hotel()
    {
        return $this->belongsTo(Hotel::class);
    }

    public function statusOverrides()
    {
        return $this->hasMany(TransactionStatusOverride::class, 'booking_id');
    }
}