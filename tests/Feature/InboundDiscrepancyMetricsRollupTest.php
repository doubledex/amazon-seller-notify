<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InboundDiscrepancyMetricsRollupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rolls_up_daily_inbound_discrepancy_kpis_and_exposes_page(): void
    {
        DB::table('inbound_shipments')->insert([
            'shipment_id' => 'S1',
            'region_code' => 'NA',
            'marketplace_id' => 'ATVPDKIKX0DER',
            'carrier_name' => 'UPS',
            'shipment_created_at' => '2026-03-01 00:00:00',
            'shipment_closed_at' => '2026-03-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inbound_discrepancies')->insert([
            [
                'shipment_id' => 'S1',
                'sku' => 'SKU-1',
                'fnsku' => 'FNSKU-1',
                'expected_units' => 100,
                'received_units' => 90,
                'units_per_carton' => 12,
                'carton_count' => 8,
                'delta' => -10,
                'carton_delta' => -1,
                'carton_equivalent_delta' => -0.8333,
                'split_carton' => true,
                'value_impact' => 120.50,
                'challenge_deadline_at' => '2026-03-11 00:00:00',
                'severity' => 'high',
                'status' => 'open',
                'discrepancy_detected_at' => '2026-03-01 08:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'shipment_id' => 'S1',
                'sku' => 'SKU-2',
                'fnsku' => 'FNSKU-2',
                'expected_units' => 50,
                'received_units' => 50,
                'units_per_carton' => 10,
                'carton_count' => 5,
                'delta' => 0,
                'carton_delta' => 0,
                'carton_equivalent_delta' => 0,
                'split_carton' => false,
                'value_impact' => 0,
                'challenge_deadline_at' => '2026-03-20 00:00:00',
                'severity' => 'low',
                'status' => 'resolved',
                'discrepancy_detected_at' => '2026-03-01 08:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $discrepancyId = (int) DB::table('inbound_discrepancies')->where('sku', 'SKU-1')->value('id');

        DB::table('inbound_claim_cases')->insert([
            'discrepancy_id' => $discrepancyId,
            'challenge_deadline_at' => '2026-03-11 00:00:00',
            'submitted_at' => '2026-03-05 00:00:00',
            'outcome' => 'won',
            'reimbursed_units' => 10,
            'reimbursed_amount' => 100.00,
            'created_at' => '2026-03-05 00:00:00',
            'updated_at' => '2026-03-07 00:00:00',
        ]);

        DB::table('us_fc_inventories')->insert([
            'marketplace_id' => 'ATVPDKIKX0DER',
            'fulfillment_center_id' => 'PHX6',
            'seller_sku' => 'SKU-1',
            'asin' => 'ASIN1',
            'fnsku' => 'FNSKU-1',
            'item_condition' => 'NewItem',
            'quantity_available' => 1,
            'raw_row' => json_encode([]),
            'report_id' => 1,
            'report_type' => 'test',
            'report_date' => '2026-03-01',
            'last_seen_at' => '2026-03-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('metrics:inbound-refresh --from=2026-03-01 --to=2026-03-01')
            ->assertSuccessful();

        $this->assertDatabaseHas('daily_inbound_discrepancy_metrics', [
            'metric_date' => '2026-03-01',
            'discrepancy_count' => 2,
            'claims_submitted_count' => 1,
            'claims_before_deadline_count' => 1,
            'claims_won_count' => 1,
        ]);

        $this->assertDatabaseHas('daily_inbound_split_carton_metrics', [
            'metric_date' => '2026-03-01',
            'fulfillment_center_id' => 'PHX6',
            'sku' => 'SKU-1',
            'carrier_name' => 'UPS',
            'split_carton_count' => 1,
        ]);

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('metrics.inbound_discrepancies'));
        $response->assertOk();
        $response->assertSee('Inbound Discrepancy KPIs');
    }
}
