<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\YaanFixtures;
use Tests\TestCase;

/**
 * A9: owner payout and platform fee must be split from the room price BEFORE GST.
 * Example: ₹42.37 room -> GST ₹7.63 -> customer pays ₹50.00
 *          owner ₹27.96 + ₹5.03 = ₹32.99, platform ₹14.41 + ₹2.60 = ₹17.01
 */
class PayoutSplitTest extends TestCase
{
    use RefreshDatabase, YaanFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createYaanFixtures();
    }

    public function test_booking_saves_room_price_before_gst(): void
    {
        $booking = $this->bookPayAtHotel();

        $this->assertEquals(42.37, (float) $booking->total_amount);
        $this->assertEquals(7.63, (float) $booking->gst_amount);
        $this->assertEquals(50.00, (float) $booking->total_payable);
    }

    public function test_owner_and_admin_dashboards_show_correct_split(): void
    {
        $this->bookPayAtHotel();

        Sanctum::actingAs($this->owner, ['*']);
        $owner = $this->getJson('/api/owner/dashboard')->assertOk();
        $this->assertEquals('27.96', $owner->json('payable_amount'));
        $this->assertEquals('14.41', $owner->json('platform_fee'));
        $this->assertEquals('50.00', $owner->json('total_amount'));
        $this->assertEquals(32.99, $owner->json('financial_breakdown.owner_total_payout'));

        Sanctum::actingAs($this->admin, ['*']);
        $ownerDetail = $this->getJson("/api/admin/owners/{$this->owner->id}")->assertOk();
        $this->assertEquals(27.96, $ownerDetail->json('analytics.owner_net_share'));
        $this->assertEquals(32.99, $ownerDetail->json('analytics.owner_payable_revenue'));
        $this->assertEquals(14.41, $ownerDetail->json('analytics.platform_fee_base'));
        $this->assertEquals(2.60, $ownerDetail->json('analytics.platform_fee_gst'));

        $hotelDetail = $this->getJson("/api/admin/hotels/{$this->hotel->id}")->assertOk();
        $this->assertEquals(32.99, $hotelDetail->json('analytics.owner_payable_revenue'));
        $this->assertEquals(14.41, $hotelDetail->json('analytics.platform_fee_base'));

        $dash = $this->getJson('/api/admin/dashboard')->assertOk();
        $this->assertEquals(32.99, $dash->json('metrics.hotel_owners_revenue'));
        // Dashboard card and target goal show the platform fee without GST
        $this->assertEquals(14.41, $dash->json('metrics.platform_revenue'));
        $this->assertEquals(14.41, $dash->json('platform_revenue'));
        $this->assertEquals(17.01, $dash->json('metrics.platform_fee_collected'));
        $this->assertEquals(50.00, $dash->json('metrics.total_revenue'));
        $this->assertEquals(14.41, $dash->json('goals.current_month_revenue'));

        $ai = $this->getJson('/api/admin/dashboard/ai-analysis')->assertOk();
        $this->assertEquals(14.41, $ai->json('summary.current_month_revenue'));
    }

    public function test_invoice_and_gst_report_use_room_price_before_gst(): void
    {
        $booking = $this->bookPayAtHotel();

        Sanctum::actingAs($this->admin, ['*']);
        $inv = $this->getJson("/api/admin/transactions/{$booking->id}/invoice")->assertOk();

        $this->assertEquals(27.96, $inv->json('invoice.pricing.owner_net_share'));
        $this->assertEquals(5.03, $inv->json('invoice.pricing.owner_gst'));
        $this->assertEquals(32.99, $inv->json('invoice.pricing.owner_total_payout'));
        $this->assertEquals(14.41, $inv->json('invoice.pricing.platform_fee_base'));
        $this->assertEquals(2.60, $inv->json('invoice.pricing.platform_fee_gst'));

        $this->assertEquals(42.37, $inv->json('invoice.gst_income_report.total_amount'));
        $this->assertEquals(7.63, $inv->json('invoice.gst_income_report.gst_18_percent'));
        $this->assertEquals(14.41, $inv->json('invoice.gst_income_report.our_commission_34'));
    }

    public function test_old_rows_with_gst_inside_total_amount_are_fixed_by_migration(): void
    {
        $booking = $this->bookPayAtHotel();
        // Old (buggy) row: total_amount saved with GST included
        Booking::whereKey($booking->id)->update(['total_amount' => 50.00]);

        $migration = require database_path('migrations/2026_10_29_000000_fix_booking_total_amount_to_pre_gst.php');
        $migration->up();

        $this->assertEquals(42.37, (float) $booking->fresh()->total_amount);
    }
}
