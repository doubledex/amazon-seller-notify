<?php

namespace App\Services\Amazon\Inbound;

use App\Models\InboundShipment;
use App\Models\InboundShipmentCarton;
use App\Services\Amazon\OfficialSpApiService;
use App\Services\RegionConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SpApi\Api\fulfillment\inbound\v2024_03_20\FbaInboundApi;
use SpApi\ApiException;
use SpApi\Model\fulfillment\inbound\v2024_03_20\InboundPlanSummary;

class InboundShipmentSyncService
{
    private const MAX_ATTEMPTS = 6;

    public function __construct(
        private readonly RegionConfigService $regionConfigService,
        private readonly OfficialSpApiService $officialSpApiService,
        private readonly InboundDiscrepancyDetectionService $detectionService,
    ) {
    }

    public function sync(int $days = 120, ?string $region = null, ?string $marketplaceId = null, bool $runDetection = true): array
    {
        $days = max(1, min($days, 365));
        $region = $region ? strtoupper(trim($region)) : null;

        if ($region !== null && !in_array($region, ['EU', 'NA', 'FE'], true)) {
            return [
                'ok' => false,
                'message' => "Invalid region '{$region}'. Allowed: EU, NA, FE.",
            ];
        }

        $regions = $region !== null ? [$region] : $this->regionConfigService->spApiRegions();
        if (empty($regions)) {
            return [
                'ok' => false,
                'message' => 'No SP-API regions configured.',
            ];
        }

        $summary = [
            'ok' => true,
            'messages' => [],
            'marketplaces_scanned' => 0,
            'shipments_upserted' => 0,
            'shipments_scanned' => 0,
            'shipment_items_scanned' => 0,
            'carton_rows_upserted' => 0,
            'discrepancies_upserted' => 0,
        ];

        foreach ($regions as $regionCode) {
            $result = $this->syncRegion($regionCode, $days, $marketplaceId);
            $summary['messages'][] = $result['message'];
            $summary['marketplaces_scanned'] += (int) ($result['marketplaces_scanned'] ?? 0);
            $summary['shipments_upserted'] += (int) ($result['shipments_upserted'] ?? 0);
            $summary['shipments_scanned'] += (int) ($result['shipments_scanned'] ?? 0);
            $summary['shipment_items_scanned'] += (int) ($result['shipment_items_scanned'] ?? 0);
            $summary['carton_rows_upserted'] += (int) ($result['carton_rows_upserted'] ?? 0);
            if (!($result['ok'] ?? false)) {
                $summary['ok'] = false;
            }
        }

        if ($runDetection) {
            $detect = $this->detectionService->detect();
            $summary['discrepancies_upserted'] = (int) ($detect['discrepancies_upserted'] ?? 0);
            $summary['messages'][] = sprintf(
                'Discrepancy detection scanned %d shipments and upserted %d rows.',
                (int) ($detect['shipments_scanned'] ?? 0),
                (int) ($detect['discrepancies_upserted'] ?? 0)
            );
        }

        $summary['message'] = implode(' | ', array_filter($summary['messages'], fn ($message) => trim((string) $message) !== ''));

        return $summary;
    }

    private function syncRegion(string $regionCode, int $days, ?string $singleMarketplaceId = null): array
    {
        $config = $this->regionConfigService->spApiConfig($regionCode);
        $api = $this->officialSpApiService->makeInboundV20240320Api($regionCode);
        if ($api === null) {
            return [
                'ok' => false,
                'message' => "[{$regionCode}] Unable to initialize official SP-API client.",
                'marketplaces_scanned' => 0,
                'shipments_upserted' => 0,
                'shipments_scanned' => 0,
                'shipment_items_scanned' => 0,
                'carton_rows_upserted' => 0,
            ];
        }

        $configuredMarketplaces = array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            (array) ($config['marketplace_ids'] ?? [])
        )));
        $marketplaceIds = $singleMarketplaceId !== null
            ? [trim($singleMarketplaceId)]
            : $configuredMarketplaces;

        if (empty($marketplaceIds)) {
            return [
                'ok' => false,
                'message' => "[{$regionCode}] No marketplace IDs configured.",
                'marketplaces_scanned' => 0,
                'shipments_upserted' => 0,
                'shipments_scanned' => 0,
                'shipment_items_scanned' => 0,
                'carton_rows_upserted' => 0,
            ];
        }

        $shipmentsUpserted = 0;
        $shipmentsScanned = 0;
        $shipmentItemsScanned = 0;
        $cartonRowsUpserted = 0;
        $errors = [];

        foreach ($marketplaceIds as $marketplaceId) {
            try {
                $result = $this->syncMarketplace($api, $regionCode, $marketplaceId, $days);
                $shipmentsUpserted += $result['shipments_upserted'];
                $shipmentsScanned += $result['shipments_scanned'];
                $shipmentItemsScanned += $result['shipment_items_scanned'];
                $cartonRowsUpserted += $result['carton_rows_upserted'];
            } catch (\Throwable $e) {
                $errors[] = "{$marketplaceId}: {$e->getMessage()}";
                Log::warning('Inbound shipment sync marketplace error', [
                    'region' => $regionCode,
                    'marketplace_id' => $marketplaceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message = sprintf(
            '[%s] marketplaces=%d shipments_scanned=%d shipments_upserted=%d items_scanned=%d carton_rows=%d',
            $regionCode,
            count($marketplaceIds),
            $shipmentsScanned,
            $shipmentsUpserted,
            $shipmentItemsScanned,
            $cartonRowsUpserted
        );
        if (!empty($errors)) {
            $message .= ' | errors: ' . implode('; ', $errors);
        }

        return [
            'ok' => empty($errors),
            'message' => $message,
            'marketplaces_scanned' => count($marketplaceIds),
            'shipments_upserted' => $shipmentsUpserted,
            'shipments_scanned' => $shipmentsScanned,
            'shipment_items_scanned' => $shipmentItemsScanned,
            'carton_rows_upserted' => $cartonRowsUpserted,
        ];
    }

    private function syncMarketplace(FbaInboundApi $api, string $regionCode, string $marketplaceId, int $days): array
    {
        $updatedAfter = Carbon::now('UTC')->subDays($days);
        $shipmentRefs = $this->collectShipmentReferences($api, $marketplaceId, $updatedAfter);

        if (empty($shipmentRefs)) {
            return [
                'shipments_upserted' => 0,
                'shipments_scanned' => 0,
                'shipment_items_scanned' => 0,
                'carton_rows_upserted' => 0,
            ];
        }

        $syncedAt = now();
        $shipmentRows = [];
        $cartonRowsByShipment = [];
        $shipmentItemsScanned = 0;

        foreach ($shipmentRefs as $ref) {
            $shipment = $this->callWithRetries(
                fn () => $api->getShipment($ref['inbound_plan_id'], $ref['shipment_id']),
                'fbaInbound.getShipment'
            );

            $shipmentId = $ref['shipment_id'];
            $status = strtoupper(trim((string) $shipment->getStatus()));
            $shipmentRows[] = [
                'shipment_id' => $shipmentId,
                'region_code' => $regionCode,
                'marketplace_id' => $marketplaceId,
                'carrier_name' => null,
                'pro_tracking_number' => null,
                'shipment_created_at' => null,
                'shipment_closed_at' => $this->shipmentClosedAtFromStatus($status, $syncedAt),
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];

            $items = $this->collectShipmentItems($api, $ref['inbound_plan_id'], $shipmentId);
            $shipmentItemsScanned += count($items);
            $cartonRowsByShipment[$shipmentId] = $this->buildCartonRows($shipmentId, $items, $syncedAt);
        }

        DB::transaction(function () use ($shipmentRows, $cartonRowsByShipment, &$cartonRowsUpserted) {
            InboundShipment::upsert(
                $shipmentRows,
                ['shipment_id'],
                [
                    'region_code',
                    'marketplace_id',
                    'carrier_name',
                    'pro_tracking_number',
                    'shipment_created_at',
                    'shipment_closed_at',
                    'updated_at',
                ]
            );

            $cartonRowsUpserted = 0;
            foreach ($cartonRowsByShipment as $shipmentId => $cartonRows) {
                InboundShipmentCarton::query()->where('shipment_id', $shipmentId)->delete();
                if (empty($cartonRows)) {
                    continue;
                }
                InboundShipmentCarton::insert($cartonRows);
                $cartonRowsUpserted += count($cartonRows);
            }
        });

        return [
            'shipments_upserted' => count($shipmentRows),
            'shipments_scanned' => count($shipmentRows),
            'shipment_items_scanned' => $shipmentItemsScanned,
            'carton_rows_upserted' => $cartonRowsUpserted,
        ];
    }

    /**
     * @return array<int, array{inbound_plan_id: string, shipment_id: string}>
     */
    private function collectShipmentReferences(FbaInboundApi $api, string $marketplaceId, Carbon $updatedAfter): array
    {
        $nextToken = null;
        $refs = [];

        do {
            $response = $this->callWithRetries(
                fn () => $api->listInboundPlans(
                    page_size: 30,
                    pagination_token: $nextToken,
                    status: null
                ),
                'fbaInbound.listInboundPlans'
            );

            foreach ((array) ($response->getInboundPlans() ?? []) as $planSummary) {
                if (!$planSummary instanceof InboundPlanSummary) {
                    continue;
                }

                if (!$this->planMatchesMarketplaceAndWindow($planSummary, $marketplaceId, $updatedAfter)) {
                    continue;
                }

                $planId = trim((string) $planSummary->getInboundPlanId());
                if ($planId === '') {
                    continue;
                }

                $inboundPlan = $this->callWithRetries(
                    fn () => $api->getInboundPlan($planId),
                    'fbaInbound.getInboundPlan'
                );

                foreach ((array) ($inboundPlan->getShipments() ?? []) as $shipmentSummary) {
                    $shipmentId = trim((string) ($shipmentSummary?->getShipmentId() ?? ''));
                    if ($shipmentId === '') {
                        continue;
                    }

                    $key = $planId . '|' . $shipmentId;
                    $refs[$key] = [
                        'inbound_plan_id' => $planId,
                        'shipment_id' => $shipmentId,
                    ];
                }
            }

            $nextToken = trim((string) ($response->getPagination()?->getNextToken() ?? ''));
            if ($nextToken === '') {
                $nextToken = null;
            }
        } while ($nextToken !== null);

        return array_values($refs);
    }

    private function planMatchesMarketplaceAndWindow(
        InboundPlanSummary $planSummary,
        string $marketplaceId,
        Carbon $updatedAfter
    ): bool {
        $marketplaces = array_map(
            static fn ($value) => trim((string) $value),
            (array) ($planSummary->getMarketplaceIds() ?? [])
        );

        if (!in_array($marketplaceId, $marketplaces, true)) {
            return false;
        }

        return Carbon::instance($planSummary->getLastUpdatedAt())->greaterThanOrEqualTo($updatedAfter);
    }

    private function collectShipmentItems(FbaInboundApi $api, string $inboundPlanId, string $shipmentId): array
    {
        $nextToken = null;
        $items = [];

        do {
            $response = $this->callWithRetries(
                fn () => $api->listShipmentItems(
                    inbound_plan_id: $inboundPlanId,
                    shipment_id: $shipmentId,
                    page_size: 50,
                    pagination_token: $nextToken
                ),
                'fbaInbound.listShipmentItems'
            );

            foreach ((array) ($response->getItems() ?? []) as $item) {
                $items[] = $item;
            }

            $nextToken = trim((string) ($response->getPagination()?->getNextToken() ?? ''));
            if ($nextToken === '') {
                $nextToken = null;
            }
        } while ($nextToken !== null);

        return $items;
    }

    private function buildCartonRows(string $shipmentId, array $items, Carbon $syncedAt): array
    {
        $lineMap = [];

        foreach ($items as $item) {
            $sku = trim((string) ($item?->getMsku() ?? ''));
            $fnsku = '';
            $quantityShipped = max(0, (int) ($item?->getQuantity() ?? 0));
            if ($quantityShipped <= 0) {
                continue;
            }

            $quantityInCase = 0;
            $key = $sku . '|' . $fnsku;

            if (!isset($lineMap[$key])) {
                $lineMap[$key] = [
                    'sku' => $sku,
                    'fnsku' => $fnsku,
                    'expected_units' => 0,
                    'units_per_carton' => 0,
                ];
            }

            $lineMap[$key]['expected_units'] += $quantityShipped;
            if ($quantityInCase > 0) {
                $lineMap[$key]['units_per_carton'] = max($lineMap[$key]['units_per_carton'], $quantityInCase);
            }
        }

        $rows = [];
        foreach ($lineMap as $line) {
            $expectedUnits = (int) $line['expected_units'];
            $unitsPerCarton = (int) $line['units_per_carton'];
            $cartonCount = $unitsPerCarton > 0 ? (int) ceil($expectedUnits / $unitsPerCarton) : 0;
            $cartonId = 'ITEM-' . substr(sha1($shipmentId . '|' . $line['sku'] . '|' . $line['fnsku']), 0, 40);

            $rows[] = [
                'shipment_id' => $shipmentId,
                'carton_id' => $cartonId,
                'sku' => $line['sku'],
                'fnsku' => $line['fnsku'],
                'expected_units' => $expectedUnits,
                'units_per_carton' => $unitsPerCarton,
                'carton_count' => $cartonCount,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        return $rows;
    }

    private function callWithRetries(callable $callback, string $operation)
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;
            try {
                return $callback();
            } catch (ApiException $e) {
                $lastException = $e;
                $status = (int) $e->getCode();
                if (!$this->shouldRetryStatus($status) || $attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                usleep($this->retryDelayMicros($attempt));
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                usleep($this->retryDelayMicros($attempt));
            }
        }

        throw new \RuntimeException("{$operation} failed after max retry attempts.", previous: $lastException);
    }

    private function shouldRetryStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function retryDelayMicros(int $attempt): int
    {
        $baseMs = 200;
        $delayMs = $baseMs * (2 ** max(0, $attempt - 1));
        $jitterMs = random_int(0, 100);

        return (int) (($delayMs + $jitterMs) * 1000);
    }

    private function shipmentClosedAtFromStatus(string $status, Carbon $timestamp): ?Carbon
    {
        $terminal = ['CLOSED', 'CANCELLED', 'DELETED'];

        if (in_array($status, $terminal, true)) {
            return $timestamp;
        }

        return null;
    }

}
