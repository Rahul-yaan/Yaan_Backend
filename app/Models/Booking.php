<?php

namespace App\Models;

use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Booking extends Model
{
    public const REFUND_PENDING = 'refund_pending';
    public const PENDING_HOLD_MINUTES = 15;

    protected $fillable = [
        'user_id',
        'hotel_id',
        'banner_id',
        'discount_code',
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
        'booking_type',
    ];

    protected $hidden = [
        'gateway_response',
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

    /**
     * Scope to find bookings holding a room/slot for a specific hotel and date.
     */
    public function scopeHoldingSlot($query, int $hotelId, string $date)
    {
        $holdCutoff = now()->subMinutes(self::PENDING_HOLD_MINUTES)->toDateTimeString();

        return $query->where('hotel_id', $hotelId)
            ->whereDate('booking_date', $date)
            ->where(function ($q) use ($holdCutoff) {
                $q->whereIn('status', ['confirmed', 'completed'])
                  ->orWhere(function ($pq) use ($holdCutoff) {
                      $pq->where('status', 'pending')
                         ->where('created_at', '>=', $holdCutoff);
                  });
            });
    }

    /**
     * Safely record payment from verifyPayment, webhooks, or admin auto-sync.
     * Guaranteed atomic with pessimistic row locking.
     */
    public function recordPayment(string $paymentId, array $extra = []): bool
    {
        return DB::transaction(function () use ($paymentId, $extra) {
            $hotel = Hotel::where('id', $this->hotel_id)->lockForUpdate()->first();
            /** @var Booking $booking */
            $booking = self::where('id', $this->id)->lockForUpdate()->first();

            if (!$booking || !$hotel) {
                return false;
            }

            // Already in a terminal paid/refund state: idempotent return
            if (in_array($booking->payment_status, ['paid', self::REFUND_PENDING, 'refunded', 'refund_initiated'], true)) {
                if (empty($booking->razorpay_payment_id)) {
                    $booking->razorpay_payment_id = $paymentId;
                }
                if (empty($booking->transaction_id) || str_starts_with($booking->transaction_id, 'TMP-')) {
                    $booking->transaction_id = $paymentId;
                }
                $booking->save();
                return in_array($booking->status, ['confirmed', 'completed'], true);
            }

            $booking->razorpay_payment_id = $paymentId;
            $booking->transaction_id      = $paymentId;

            if (!empty($extra['gateway_response'])) {
                $booking->gateway_response = is_string($extra['gateway_response'])
                    ? $extra['gateway_response']
                    : json_encode($extra['gateway_response']);
            }

            // If already cancelled, set refund_pending and keep cancelled
            if ($booking->status === 'cancelled') {
                $booking->payment_status = self::REFUND_PENDING;
                $booking->save();
                return false;
            }

            // If already confirmed or completed, just mark paid
            if (in_array($booking->status, ['confirmed', 'completed'], true)) {
                $booking->payment_status = 'paid';
                $booking->save();
                return true;
            }

            // Booking is pending: check if slots are still available
            $targetDate = $booking->booking_date ? $booking->booking_date->toDateString() : BusinessDate::today();
            $holdingCount = self::holdingSlot($hotel->id, $targetDate)
                ->where('id', '!=', $booking->id)
                ->count();

            if ($holdingCount < $hotel->total_rooms) {
                // Room successfully secured
                $booking->payment_status = 'paid';
                $booking->status         = 'confirmed';
                $booking->save();

                $hotel->refreshAvailableRooms($targetDate);
                return true;
            }

            // Room lost (slot hold expired and taken by someone else)
            $booking->status              = 'cancelled';
            $booking->payment_status      = self::REFUND_PENDING;
            $booking->cancellation_reason = 'Payment succeeded after slot hold expired. Room capacity reached; refund pending admin approval.';
            $booking->save();

            $hotel->refreshAvailableRooms($targetDate);
            return false;
        });
    }

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
        if ($this->payment_status === self::REFUND_PENDING) {
            return null;
        }

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

    public function banner()
    {
        return $this->belongsTo(Banner::class);
    }

    public function statusOverrides()
    {
        return $this->hasMany(TransactionStatusOverride::class, 'booking_id');
    }
}