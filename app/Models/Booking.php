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

        // 1. Check audit log override for the exact timestamp when refund was triggered
        $override = TransactionStatusOverride::where('booking_id', $this->id)
            ->where(function($q) {
                $q->whereIn('new_payment_status', ['refunded', 'refund_initiated'])
                  ->orWhere('reason', 'like', '%Refund%');
            })
            ->latest('id')
            ->first();

        if ($override && $override->created_at) {
            return $override->created_at->timezone('Asia/Kolkata')->format('d M Y, h:i:s A') . ' IST';
        }

        // 2. Check if gateway_response has an explicit refund entity timestamp (not payment timestamp)
        if (!empty($this->gateway_response)) {
            $gw = json_decode($this->gateway_response, true);
            if (is_array($gw)) {
                if (isset($gw['entity']) && $gw['entity'] === 'refund' && isset($gw['created_at'])) {
                    return \Carbon\Carbon::createFromTimestamp($gw['created_at'])->timezone('Asia/Kolkata')->format('d M Y, h:i:s A') . ' IST';
                }
                if (isset($gw['refund']['entity']['created_at'])) {
                    return \Carbon\Carbon::createFromTimestamp($gw['refund']['entity']['created_at'])->timezone('Asia/Kolkata')->format('d M Y, h:i:s A') . ' IST';
                }
            }
        }

        // 3. Fallback to model updated_at timestamp formatted in IST
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