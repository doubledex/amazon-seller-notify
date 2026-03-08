<?php

namespace App\Services\Amazon\Inbound;

use App\Models\InboundShipment;
use App\Models\InboundShipmentCarton;
use App\Services\Amazon\OfficialSpApiService;
use App\Services\RegionConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SpApi\Api\fulfillment\inbound\v0\FbaInboundApi;
use SpApi\ApiException;
use SpApi\Model\fulfillment\inbound\v0\ShipmentStatus;

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
        $api = $this->officialSpApiService->makeInboundV0Api($regionCode);
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
        $nowUtc = Carbon::now('UTC');
        $updatedAfter = $nowUtc->copy()->subDays($days);
        $nextToken = null;
        $shipmentData = [];

        do {
            $queryType = $nextToken ? 'NEXT_TOKEN' : 'DATE_RANGE';

            $response = $this->callWithRetries(
                fn () => $api->getShipments(
                    query_type: $queryType,
                    marketplace_id: $marketplaceId,
                    shipment_status_list: $nextToken ? null : $this->shipmentStatusFilter(),
                    shipment_id_list: null,
                    last_updated_after: $nextToken ? null : $updatedAfter->toDateTime(),
                    last_updated_before: $nextToken ? null : $nowUtc->toDateTime(),
                    next_token: $nextToken,
                ),
                'fbaInbound.getShipments'
            );

            $payload = $response->getPayload();
            foreach ((array) ($payload?->getShipmentData() ?? []) as $shipment) {
                $shipmentId = trim((string) $shipment->getShipmentId());
                if ($shipmentId === '') {
                    continue;
                }
                $shipmentData[$shipmentId] = $shipment;
            }

            $nextToken = trim((string) ($payload?->getNextToken() ?? ''));
            if ($nextToken === '') {
                $nextToken = null;
            }
        } while ($nextToken !== null);

        if (empty($shipmentData)) {
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

        foreach ($shipmentData as $shipmentId => $shipment) {
            $status = strtoupper(trim((string) $shipment->getShipmentStatus()));
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

            $itemsResponse = $this->callWithRetries(
                fn () => $api->getShipmentItemsByShipmentId(
                    shipment_id: $shipmentId,
                    marketplace_id: $marketplaceId
                ),
                'fbaInbound.getShipmentItemsByShipmentId'
            );
            $items = (array) ($itemsResponse->getPayload()?->getItemData() ?? []);
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

    private function buildCartonRows(string $shipmentId, array $items, Carbon $syncedAt): array
    {
        $lineMap = [];

        foreach ($items as $item) {
            $sku = trim((string) $item->getSellerSku());
            $fnsku = trim((string) $item->getFulfillmentNetworkSku());
            $quantityShipped = max(0, (int) $item->getQuantityShipped());
            if ($quantityShipped <= 0) {
                continue;
            }

            $quantityInCase = max(0, (int) ($item->getQuantityInCase() ?? 0));
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

    private function shipmentStatusFilter(): array
    {
        return [
            ShipmentStatus::WORKING,
            ShipmentStatus::SHIPPED,
            ShipmentStatus::RECEIVING,
            ShipmentStatus::CANCELLED,
            ShipmentStatus::DELETED,
            ShipmentStatus::CLOSED,
            ShipmentStatus::ERROR,
            ShipmentStatus::IN_TRANSIT,
            ShipmentStatus::DELIVERED,
            ShipmentStatus::CHECKED_IN,
        ];
    }
}
