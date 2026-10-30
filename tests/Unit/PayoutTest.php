<?php

namespace Tests\Unit;

use App\Support\Payout;
use PHPUnit\Framework\TestCase;

class PayoutTest extends TestCase
{
    public function test_split_for_42_37_room(): void
    {
        $s = Payout::split(42.37, 7.63);

        $this->assertEquals(50.00, $s['customer_paid']);
        $this->assertEquals(27.96, $s['owner_profit']);
        $this->assertEquals(5.03, $s['owner_gst']);
        $this->assertEquals(32.99, $s['owner_total']);
        $this->assertEquals(14.41, $s['platform_profit']);
        $this->assertEquals(2.60, $s['platform_gst']);
        $this->assertEquals(17.01, $s['platform_total']);

        // Nothing is lost or invented: both sides add up to what the customer paid
        $this->assertEquals(50.00, round($s['owner_total'] + $s['platform_total'], 2));
    }

    public function test_split_for_500_room(): void
    {
        $s = Payout::split(500, 90);

        $this->assertEquals(590.00, $s['customer_paid']);
        $this->assertEquals(330.00, $s['owner_profit']);
        $this->assertEquals(59.40, $s['owner_gst']);
        $this->assertEquals(389.40, $s['owner_total']);
        $this->assertEquals(170.00, $s['platform_profit']);
        $this->assertEquals(30.60, $s['platform_gst']);
        $this->assertEquals(200.60, $s['platform_total']);
    }

    public function test_old_booking_with_gst_inside_total_amount_uses_correct_base(): void
    {
        // Old rows saved total_amount = total_payable (GST included)
        $old = (object) [
            'price_per_night'   => 42.37,
            'total_amount'      => 50.00,
            'total_payable'     => 50.00,
            'gst_amount'        => 7.63,
            'promotion_applied' => 0,
        ];

        $s = Payout::forBooking($old);

        $this->assertEquals(42.37, $s['base']);
        $this->assertEquals(27.96, $s['owner_profit']);
        $this->assertEquals(32.99, $s['owner_total']);
    }

    public function test_sum_adds_each_booking(): void
    {
        $b = (object) ['price_per_night' => 42.37, 'total_amount' => 42.37, 'total_payable' => 50.00, 'gst_amount' => 7.63, 'promotion_applied' => 0];

        $s = Payout::sum([$b, $b]);

        $this->assertEquals(100.00, $s['customer_paid']);
        $this->assertEquals(65.98, $s['owner_total']);
        $this->assertEquals(34.02, $s['platform_total']);
    }
}
