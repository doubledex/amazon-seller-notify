<?php

use App\Models\InboundDiscrepancy;
use App\Models\InboundShipment;
use App\Models\InboundShipmentCarton;
use App\Models\UsFcInventory;
use App\Services\Amazon\Inbound\InboundDiscrepancyDetectionService;
use Illuminate\Support\Carbon;

it('computes and upserts discrepancies by shipment sku and fnsku', function () {
    config()->set('inbound_discrepancy.default_unit_value', 25);
    config()->set('inbound_discrepancy.claim_window_days', 30);
    config()->set('inbound_discrepancy.severity.value_thresholds.medium', 75);
    config()->set('inbound_discrepancy.severity.value_thresholds.high', 200);
    config()->set('inbound_discrepancy.severity.value_thresholds.critical', 500);
    config()->set('inbound_discrepancy.severity.warning_deadline_days', 7);
    config()->set('inbound_discrepancy.severity.urgent_deadline_days', 3);

    Carbon::setTestNow('2026-03-08 12:00:00');

    InboundShipment::query()->create([
        'shipment_id' => 'SHIP-1',
        'region_code' => 'NA',
        'marketplace_id' => 'ATVPDKIKX0DER',
        'shipment_closed_at' => now()->subDays(26),
    ]);

    InboundShipmentCarton::query()->create([
        'shipment_id' => 'SHIP-1',
        'carton_id' => 'C-1',
        'sku' => 'SKU-1',
        'fnsku' => 'FNSKU-1',
        'units_per_carton' => 6,
        'carton_count' => 2,
        'expected_units' => 12,
    ]);

    UsFcInventory::query()->create([
        'marketplace_id' => 'ATVPDKIKX0DER',
        'fulfillment_center_id' => 'DFW1',
        'seller_sku' => 'SKU-1',
        'fnsku' => 'FNSKU-1',
        'quantity_available' => 8,
        'last_seen_at' => now(),
    ]);

    $service = app(InboundDiscrepancyDetectionService::class);

    $firstRun = $service->detect('SHIP-1');

    expect($firstRun['shipments_scanned'])->toBe(1)
        ->and($firstRun['discrepancies_upserted'])->toBe(1)
        ->and(InboundDiscrepancy::query()->count())->toBe(1);

    $record = InboundDiscrepancy::query()->firstOrFail();

    expect($record->expected_units)->toBe(12)
        ->and($record->received_units)->toBe(8)
        ->and($record->delta)->toBe(-4)
        ->and((float) $record->carton_equivalent_delta)->toBe(-0.6667)
        ->and($record->split_carton)->toBeTrue()
        ->and((float) $record->value_impact)->toBe(100.0)
        ->and($record->severity)->toBe('critical')
        ->and($record->status)->toBe('open');

    UsFcInventory::query()->where('seller_sku', 'SKU-1')->update([
        'quantity_available' => 12,
        'last_seen_at' => now()->addMinute(),
    ]);

    $secondRun = $service->detect('SHIP-1');

    expect($secondRun['discrepancies_upserted'])->toBe(1)
        ->and(InboundDiscrepancy::query()->count())->toBe(1);

    $record->refresh();

    expect($record->delta)->toBe(0)
        ->and((float) $record->carton_equivalent_delta)->toBe(0.0)
        ->and($record->split_carton)->toBeFalse()
        ->and($record->status)->toBe('resolved');

    Carbon::setTestNow();
});

it('matches received units when sku and fnsku contain dots', function () {
    config()->set('inbound_discrepancy.default_unit_value', 10);

    InboundShipment::query()->create([
        'shipment_id' => 'SHIP-DOT-1',
        'region_code' => 'NA',
        'marketplace_id' => 'ATVPDKIKX0DER',
    ]);

    InboundShipmentCarton::query()->create([
        'shipment_id' => 'SHIP-DOT-1',
        'carton_id' => 'C-DOT-1',
        'sku' => 'ABC.123',
        'fnsku' => 'X0.9Z',
        'units_per_carton' => 4,
        'carton_count' => 3,
        'expected_units' => 12,
    ]);

    UsFcInventory::query()->create([
        'marketplace_id' => 'ATVPDKIKX0DER',
        'fulfillment_center_id' => 'DFW1',
        'seller_sku' => 'ABC.123',
        'fnsku' => 'X0.9Z',
        'quantity_available' => 12,
        'last_seen_at' => now(),
    ]);

    $result = app(InboundDiscrepancyDetectionService::class)->detect('SHIP-DOT-1');

    expect($result['shipments_scanned'])->toBe(1)
        ->and($result['discrepancies_upserted'])->toBe(1);

    $record = InboundDiscrepancy::query()
        ->where('shipment_id', 'SHIP-DOT-1')
        ->where('sku', 'ABC.123')
        ->where('fnsku', 'X0.9Z')
        ->firstOrFail();

    expect($record->received_units)->toBe(12)
        ->and($record->delta)->toBe(0)
        ->and($record->status)->toBe('resolved');
});

it('falls back to carton math when expected units remain at default zero', function () {
    config()->set('inbound_discrepancy.default_unit_value', 10);

    InboundShipment::query()->create([
        'shipment_id' => 'SHIP-FALLBACK-1',
        'region_code' => 'NA',
        'marketplace_id' => 'ATVPDKIKX0DER',
    ]);

    InboundShipmentCarton::query()->create([
        'shipment_id' => 'SHIP-FALLBACK-1',
        'carton_id' => 'C-FALLBACK-1',
        'sku' => 'SKU-FALLBACK-1',
        'fnsku' => 'FNSKU-FALLBACK-1',
        'units_per_carton' => 5,
        'carton_count' => 2,
        'expected_units' => 0,
    ]);

    UsFcInventory::query()->create([
        'marketplace_id' => 'ATVPDKIKX0DER',
        'fulfillment_center_id' => 'DFW1',
        'seller_sku' => 'SKU-FALLBACK-1',
        'fnsku' => 'FNSKU-FALLBACK-1',
        'quantity_available' => 10,
        'last_seen_at' => now(),
    ]);

    $result = app(InboundDiscrepancyDetectionService::class)->detect('SHIP-FALLBACK-1');

    expect($result['shipments_scanned'])->toBe(1)
        ->and($result['discrepancies_upserted'])->toBe(1);

    $record = InboundDiscrepancy::query()
        ->where('shipment_id', 'SHIP-FALLBACK-1')
        ->where('sku', 'SKU-FALLBACK-1')
        ->where('fnsku', 'FNSKU-FALLBACK-1')
        ->firstOrFail();

    expect($record->expected_units)->toBe(10)
        ->and($record->received_units)->toBe(10)
        ->and($record->delta)->toBe(0)
        ->and($record->status)->toBe('resolved');
});
