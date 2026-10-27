<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PricingCalculationTest extends TestCase
{
    /**
     * Test standard owner payout and platform fee calculation without active admin discount.
     */
    public function test_standard_pricing_breakdown(): void
    {
        $roomRateWithoutGst = 42.37;
        $gstRate = 0.18;

        // Total payable by user: 42.37 + 18% GST (7.63) = 50.00
        $userGst = round($roomRateWithoutGst * $gstRate, 2);
        $totalPayable = round($roomRateWithoutGst + $userGst, 2);

        $this->assertEquals(7.63, $userGst);
        $this->assertEquals(50.00, $totalPayable);

        // Owner portion: 66% of base rate
        $ownerBasePayout = round($roomRateWithoutGst * 0.66, 2);
        $ownerGst = round($ownerBasePayout * $gstRate, 2);
        $ownerTotalPayout = round($ownerBasePayout + $ownerGst, 2);

        $this->assertEquals(27.96, $ownerBasePayout);
        $this->assertEquals(5.03, $ownerGst);
        $this->assertEquals(32.99, $ownerTotalPayout);

        // Platform fee portion: 34% of base rate
        $platformBaseFee = round($roomRateWithoutGst * 0.34, 2);
        $platformGst = round($platformBaseFee * $gstRate, 2);

        $this->assertEquals(14.41, $platformBaseFee);
        $this->assertEquals(2.59, $platformGst);
    }
}
