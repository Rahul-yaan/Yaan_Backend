<?php

namespace App\Support;

/**
 * One place for splitting booking money between the hotel owner and the platform.
 *
 * Rule:
 *   base            = room price before GST (after any discount)
 *   owner profit    = 66% of base
 *   owner GST       = 18% of owner profit
 *   platform profit = base - owner profit
 *   platform GST    = total GST - owner GST (so no paisa is lost to rounding)
 *
 * Example: base 42.37, GST 7.63 (customer pays 50.00)
 *   owner 27.96 + 5.03 = 32.99, platform 14.41 + 2.60 = 17.01
 */
class Payout
{
    public const OWNER_SHARE = 0.66;
    public const GST_RATE    = 0.18;

    public static function split(float $base, ?float $gst = null): array
    {
        $base = round(max(0, $base), 2);
        $gst  = round($gst ?? $base * self::GST_RATE, 2);

        $ownerProfit    = round($base * self::OWNER_SHARE, 2);
        $ownerGst       = round($ownerProfit * self::GST_RATE, 2);
        $platformProfit = round($base - $ownerProfit, 2);
        $platformGst    = round(max(0, $gst - $ownerGst), 2);

        return [
            'base'            => $base,
            'gst'             => $gst,
            'customer_paid'   => round($base + $gst, 2),
            'owner_profit'    => $ownerProfit,
            'owner_gst'       => $ownerGst,
            'owner_total'     => round($ownerProfit + $ownerGst, 2),
            'platform_profit' => $platformProfit,
            'platform_gst'    => $platformGst,
            'platform_total'  => round($platformProfit + $platformGst, 2),
        ];
    }

    /**
     * Room price before GST for a booking. Works for old rows where
     * total_amount was saved with GST included.
     */
    public static function bookingBase($booking): float
    {
        $payable = (float) ($booking->total_payable ?? 0);
        $gst     = (float) ($booking->gst_amount ?? 0);

        if ($payable > 0 && $gst > 0) {
            return round($payable - $gst, 2);
        }
        if ((float) ($booking->total_amount ?? 0) > 0) {
            return (float) $booking->total_amount;
        }

        return max(0, (float) ($booking->price_per_night ?? 0) - (float) ($booking->promotion_applied ?? 0));
    }

    public static function forBooking($booking): array
    {
        $gst = (float) ($booking->gst_amount ?? 0);

        return self::split(self::bookingBase($booking), $gst > 0 ? $gst : null);
    }

    /**
     * Split each booking, then add the results up.
     */
    public static function sum(iterable $bookings): array
    {
        $totals = [
            'base' => 0, 'gst' => 0, 'customer_paid' => 0,
            'owner_profit' => 0, 'owner_gst' => 0, 'owner_total' => 0,
            'platform_profit' => 0, 'platform_gst' => 0, 'platform_total' => 0,
        ];

        foreach ($bookings as $booking) {
            foreach (self::forBooking($booking) as $key => $value) {
                $totals[$key] += $value;
            }
        }

        return array_map(fn ($v) => round($v, 2), $totals);
    }

    /** Columns needed to calculate a split, for lean queries. */
    public const COLUMNS = ['id', 'price_per_night', 'total_amount', 'total_payable', 'gst_amount', 'promotion_applied'];
}
