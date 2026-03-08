<?php

namespace App\Services\Amazon\Inbound;

use App\Models\InboundDiscrepancy;
use App\Models\InboundShipment;
use App\Models\InboundShipmentCarton;
use App\Models\UsFcInventory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class InboundDiscrepancyDetectionService
{
    public function detect(?string $shipmentId = null): array
    {
        $shipments = InboundShipment::query()
            ->when($shipmentId !== null, fn ($query) => $query->where('shipment_id', $shipmentId))
            ->get(['shipment_id', 'marketplace_id', 'shipment_closed_at'])
            ->keyBy('shipment_id');

        if ($shipments->isEmpty()) {
            return [
                'shipments_scanned' => 0,
                'discrepancies_upserted' => 0,
            ];
        }

        $expectedRows = $this->expectedCartonLines($shipments->keys()->all());
        $receivedByMarketplace = $this->receivedUnitsByMarketplace(
            $shipments->pluck('marketplace_id')->filter()->unique()->values()->all()
        );

        $upserted = 0;
        foreach ($expectedRows as $line) {
            $shipment = $shipments->get($line['shipment_id']);
            if ($shipment === null) {
                continue;
            }

            $receivedUnits = (int) data_get(
                $receivedByMarketplace,
                implode('.', [
                    $shipment->marketplace_id,
                    $this->keyPart($line['sku']),
                    $this->keyPart($line['fnsku']),
                ]),
                0
            );

            $delta = $receivedUnits - $line['expected_units'];
            $cartonEquivalentDelta = $line['units_per_carton'] > 0
                ? ($delta / $line['units_per_carton'])
                : 0.0;
            $splitCarton = $line['units_per_carton'] > 0
                ? (abs($delta) % $line['units_per_carton']) !== 0
                : false;

            $valueImpact = abs($delta) * (float) config('inbound_discrepancy.default_unit_value', 0.0);
            $challengeDeadlineAt = $this->challengeDeadlineForShipment($shipment->shipment_closed_at);
            $severity = $this->determineSeverity($valueImpact, $challengeDeadlineAt);

            InboundDiscrepancy::query()->updateOrCreate(
                [
                    'shipment_id' => $line['shipment_id'],
                    'sku' => $line['sku'],
                    'fnsku' => $line['fnsku'],
                ],
                [
                    'expected_units' => $line['expected_units'],
                    'received_units' => $receivedUnits,
                    'delta' => $delta,
                    'carton_delta' => (int) $cartonEquivalentDelta,
                    'carton_equivalent_delta' => $cartonEquivalentDelta,
                    'units_per_carton' => $line['units_per_carton'],
                    'carton_count' => $line['carton_count'],
                    'split_carton' => $splitCarton,
                    'value_impact' => $valueImpact,
                    'challenge_deadline_at' => $challengeDeadlineAt,
                    'severity' => $severity,
                    'status' => $delta === 0 ? 'resolved' : 'open',
                    'discrepancy_detected_at' => now(),
                ]
            );

            $upserted++;
        }

        return [
            'shipments_scanned' => $shipments->count(),
            'discrepancies_upserted' => $upserted,
        ];
    }

    private function expectedCartonLines(array $shipmentIds): Collection
    {
        return InboundShipmentCarton::query()
            ->selectRaw('shipment_id, COALESCE(sku, "") as sku, COALESCE(fnsku, "") as fnsku')
            ->selectRaw('SUM(COALESCE(expected_units, units_per_carton * carton_count, 0)) as expected_units')
            ->selectRaw('MAX(COALESCE(units_per_carton, 0)) as units_per_carton')
            ->selectRaw('SUM(COALESCE(carton_count, 0)) as carton_count')
            ->whereIn('shipment_id', $shipmentIds)
            ->groupBy('shipment_id', 'sku', 'fnsku')
            ->get()
            ->map(fn ($row) => [
                'shipment_id' => (string) $row->shipment_id,
                'sku' => (string) $row->sku,
                'fnsku' => (string) $row->fnsku,
                'expected_units' => (int) $row->expected_units,
                'units_per_carton' => (int) $row->units_per_carton,
                'carton_count' => (int) $row->carton_count,
            ]);
    }

    private function receivedUnitsByMarketplace(array $marketplaceIds): array
    {
        if (empty($marketplaceIds)) {
            return [];
        }

        $latestByMarketplace = UsFcInventory::query()
            ->selectRaw('marketplace_id, MAX(last_seen_at) as max_last_seen_at')
            ->whereIn('marketplace_id', $marketplaceIds)
            ->whereNotNull('last_seen_at')
            ->groupBy('marketplace_id');

        $rows = UsFcInventory::query()
            ->joinSub($latestByMarketplace, 'latest', function ($join) {
                $join->on('us_fc_inventories.marketplace_id', '=', 'latest.marketplace_id');
                $join->on('us_fc_inventories.last_seen_at', '=', 'latest.max_last_seen_at');
            })
            ->selectRaw('us_fc_inventories.marketplace_id')
            ->selectRaw('COALESCE(us_fc_inventories.seller_sku, "") as sku')
            ->selectRaw('COALESCE(us_fc_inventories.fnsku, "") as fnsku')
            ->selectRaw('SUM(COALESCE(us_fc_inventories.quantity_available, 0)) as received_units')
            ->groupBy('us_fc_inventories.marketplace_id', 'sku', 'fnsku')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->marketplace_id][$this->keyPart($row->sku)][$this->keyPart($row->fnsku)] = (int) $row->received_units;
        }

        return $result;
    }

    private function challengeDeadlineForShipment(mixed $shipmentClosedAt): ?Carbon
    {
        if ($shipmentClosedAt === null) {
            return null;
        }

        return Carbon::parse($shipmentClosedAt)
            ->addDays((int) config('inbound_discrepancy.claim_window_days', 30));
    }

    private function determineSeverity(float $valueImpact, ?Carbon $challengeDeadlineAt): string
    {
        $criticalValue = (float) config('inbound_discrepancy.severity.value_thresholds.critical', 500.0);
        $highValue = (float) config('inbound_discrepancy.severity.value_thresholds.high', 200.0);
        $mediumValue = (float) config('inbound_discrepancy.severity.value_thresholds.medium', 75.0);
        $urgentDays = (int) config('inbound_discrepancy.severity.urgent_deadline_days', 3);
        $warningDays = (int) config('inbound_discrepancy.severity.warning_deadline_days', 7);

        $daysRemaining = $challengeDeadlineAt?->diffInDays(now(), false);
        $deadlineUrgent = $daysRemaining !== null && $daysRemaining >= -$urgentDays;
        $deadlineSoon = $daysRemaining !== null && $daysRemaining >= -$warningDays;

        if ($valueImpact >= $criticalValue || ($deadlineUrgent && $valueImpact > 0)) {
            return 'critical';
        }

        if ($valueImpact >= $highValue || ($deadlineSoon && $valueImpact >= $mediumValue)) {
            return 'high';
        }

        if ($valueImpact >= $mediumValue) {
            return 'medium';
        }

        return 'low';
    }

    private function keyPart(?string $value): string
    {
        return trim((string) $value);
    }
}

