<?php

namespace App\Services\Amazon\Inbound;

use App\Models\InboundShipment;
use App\Models\InboundShipmentCarton;
use App\Services\Amazon\OfficialSpApiService;
use App\Services\RegionConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SpApi\Api\fulfillment\inbound\v0\FbaInboundApi as FbaInboundV0Api;
use SpApi\Api\fulfillment\inbound\v2024_03_20\FbaInboundApi as FbaInboundV20240320Api;
use SpApi\ApiException;
use SpApi\Model\fulfillment\inbound\v2024_03_20\InboundPlanSummary;
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

    public function sync(
        int $days = 120,
        ?string $region = null,
        ?string $marketplaceId = null,
        bool $runDetection = true,
        bool $debug = false
    ): array
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
            $result = $this->syncRegion($regionCode, $days, $marketplaceId, $debug);
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

    private function syncRegion(string $regionCode, int $days, ?string $singleMarketplaceId = null, bool $debug = false): array
    {
        $config = $this->regionConfigService->spApiConfig($regionCode);
        $api2024 = $this->officialSpApiService->makeInboundV20240320Api($regionCode);
        $apiV0 = $this->officialSpApiService->makeInboundV0Api($regionCode);
        if ($api2024 === null && $apiV0 === null) {
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
                $result = $this->syncMarketplace($api2024, $apiV0, $regionCode, $marketplaceId, $days, $debug);
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

    private function syncMarketplace(
        ?FbaInboundV20240320Api $api2024,
        ?FbaInboundV0Api $apiV0,
        string $regionCode,
        string $marketplaceId,
        int $days,
        bool $debug = false
    ): array
    {
        if ($api2024 === null) {
            return $apiV0 === null
                ? [
                    'shipments_upserted' => 0,
                    'shipments_scanned' => 0,
                    'shipment_items_scanned' => 0,
                    'carton_rows_upserted' => 0,
                ]
                : $this->syncMarketplaceViaV0($apiV0, $regionCode, $marketplaceId, $days, $debug);
        }

        $updatedAfter = Carbon::now('UTC')->subDays($days);
        $shipmentRefs = $this->collectShipmentReferences($api2024, $regionCode, $marketplaceId, $updatedAfter, $debug);

        if (empty($shipmentRefs)) {
            if ($apiV0 !== null) {
                Log::info('Inbound sync using v0 fallback because v2024 discovered no shipment refs', [
                    'region' => $regionCode,
                    'marketplace_id' => $marketplaceId,
                    'days' => $days,
                ]);

                return $this->syncMarketplaceViaV0($apiV0, $regionCode, $marketplaceId, $days, $debug);
            }

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
                fn () => $api2024->getShipment($ref['inbound_plan_id'], $ref['shipment_id']),
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
                'api_source_version' => 'fulfillment/inbound/v2024-03-20',
                'api_shipment_payload' => $this->shipmentPayloadV2024($shipment),
                'api_items_payload' => null,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];

            $items = $this->collectShipmentItems($api2024, $ref['inbound_plan_id'], $shipmentId);
            $shipmentRows[array_key_last($shipmentRows)]['api_items_payload'] = $this->itemsPayloadV2024($items);
            $shipmentItemsScanned += count($items);
            $cartonRowsByShipment[$shipmentId] = $this->buildCartonRowsFromV2024($shipmentId, $items, $syncedAt);
        }

        $cartonRowsUpserted = $this->persistShipmentRows($shipmentRows, $cartonRowsByShipment);

        return [
            'shipments_upserted' => count($shipmentRows),
            'shipments_scanned' => count($shipmentRows),
            'shipment_items_scanned' => $shipmentItemsScanned,
            'carton_rows_upserted' => $cartonRowsUpserted,
        ];
    }

    private function syncMarketplaceViaV0(
        FbaInboundV0Api $api,
        string $regionCode,
        string $marketplaceId,
        int $days,
        bool $debug = false
    ): array {
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
                'fbaInbound.v0.getShipments'
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

        if ($debug) {
            Log::info('Inbound v0 fallback diagnostics', [
                'region' => $regionCode,
                'marketplace_id' => $marketplaceId,
                'updated_after' => $updatedAfter->toIso8601String(),
                'shipments_discovered' => count($shipmentData),
            ]);
        }

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
                'api_source_version' => 'fulfillment/inbound/v0',
                'api_shipment_payload' => $this->shipmentPayloadV0($shipment),
                'api_items_payload' => null,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];

            $itemsResponse = $this->callWithRetries(
                fn () => $api->getShipmentItemsByShipmentId(
                    shipment_id: $shipmentId,
                    marketplace_id: $marketplaceId
                ),
                'fbaInbound.v0.getShipmentItemsByShipmentId'
            );
            $items = (array) ($itemsResponse->getPayload()?->getItemData() ?? []);
            $shipmentRows[array_key_last($shipmentRows)]['api_items_payload'] = $this->itemsPayloadV0($items);
            $shipmentItemsScanned += count($items);
            $cartonRowsByShipment[$shipmentId] = $this->buildCartonRowsFromV0($shipmentId, $items, $syncedAt);
        }

        $cartonRowsUpserted = $this->persistShipmentRows($shipmentRows, $cartonRowsByShipment);

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
    private function collectShipmentReferences(
        FbaInboundV20240320Api $api,
        string $regionCode,
        string $marketplaceId,
        Carbon $updatedAfter,
        bool $debug = false
    ): array
    {
        $nextToken = null;
        $refs = [];
        $pages = 0;
        $plansTotal = 0;
        $plansMarketplaceMatch = 0;
        $plansWindowMatch = 0;
        $shipmentsDiscovered = 0;
        $samplePlanIds = [];

        do {
            $pages++;
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
                $plansTotal++;

                $planId = trim((string) $planSummary->getInboundPlanId());
                if ($planId !== '' && count($samplePlanIds) < 5) {
                    $samplePlanIds[] = $planId;
                }

                $marketplaces = array_map(
                    static fn ($value) => trim((string) $value),
                    (array) ($planSummary->getMarketplaceIds() ?? [])
                );
                if (!in_array($marketplaceId, $marketplaces, true)) {
                    continue;
                }
                $plansMarketplaceMatch++;

                $lastUpdatedAt = Carbon::instance($planSummary->getLastUpdatedAt());
                if ($lastUpdatedAt->lt($updatedAfter)) {
                    continue;
                }
                $plansWindowMatch++;

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

        if ($debug) {
            $shipmentsDiscovered = count($refs);
            Log::info('Inbound sync plan discovery diagnostics', [
                'region' => $regionCode,
                'marketplace_id' => $marketplaceId,
                'updated_after' => $updatedAfter->toIso8601String(),
                'pages' => $pages,
                'plans_total' => $plansTotal,
                'plans_marketplace_match' => $plansMarketplaceMatch,
                'plans_window_match' => $plansWindowMatch,
                'shipment_refs_discovered' => $shipmentsDiscovered,
                'sample_plan_ids' => $samplePlanIds,
            ]);
        }

        return array_values($refs);
    }

    private function collectShipmentItems(FbaInboundV20240320Api $api, string $inboundPlanId, string $shipmentId): array
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

    private function buildCartonRowsFromV2024(string $shipmentId, array $items, Carbon $syncedAt): array
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
                    'received_units' => 0,
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
                'received_units' => (int) ($line['received_units'] ?? 0),
                'units_per_carton' => $unitsPerCarton,
                'carton_count' => $cartonCount,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        return $rows;
    }

    private function buildCartonRowsFromV0(string $shipmentId, array $items, Carbon $syncedAt): array
    {
        $lineMap = [];

        foreach ($items as $item) {
            $sku = trim((string) $item->getSellerSku());
            $fnsku = trim((string) $item->getFulfillmentNetworkSku());
            $quantityShipped = max(0, (int) $item->getQuantityShipped());
            if ($quantityShipped <= 0) {
                continue;
            }
            $quantityReceived = max(0, (int) ($item->getQuantityReceived() ?? 0));

            $quantityInCase = max(0, (int) ($item->getQuantityInCase() ?? 0));
            $key = $sku . '|' . $fnsku;

            if (!isset($lineMap[$key])) {
                $lineMap[$key] = [
                    'sku' => $sku,
                    'fnsku' => $fnsku,
                    'expected_units' => 0,
                    'received_units' => 0,
                    'units_per_carton' => 0,
                ];
            }

            $lineMap[$key]['expected_units'] += $quantityShipped;
            $lineMap[$key]['received_units'] += $quantityReceived;
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
                'received_units' => (int) ($line['received_units'] ?? 0),
                'units_per_carton' => $unitsPerCarton,
                'carton_count' => $cartonCount,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        return $rows;
    }

    private function persistShipmentRows(array $shipmentRows, array $cartonRowsByShipment): int
    {
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
                    'api_source_version',
                    'api_shipment_payload',
                    'api_items_payload',
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

        return $cartonRowsUpserted;
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
        return ShipmentStatus::getAllowableEnumValues();
    }

    private function shipmentPayloadV0(object $shipment): array
    {
        return [
            'shipment_id' => (string) ($shipment->getShipmentId() ?? ''),
            'shipment_status' => (string) ($shipment->getShipmentStatus() ?? ''),
            'shipment_name' => (string) ($shipment->getShipmentName() ?? ''),
            'destination_fulfillment_center_id' => (string) ($shipment->getDestinationFulfillmentCenterId() ?? ''),
            'label_prep_type' => (string) ($shipment->getLabelPrepType() ?? ''),
        ];
    }

    private function itemsPayloadV0(array $items): array
    {
        return array_map(static function ($item): array {
            return [
                'seller_sku' => (string) ($item->getSellerSku() ?? ''),
                'fnsku' => (string) ($item->getFulfillmentNetworkSku() ?? ''),
                'quantity_shipped' => (int) ($item->getQuantityShipped() ?? 0),
                'quantity_received' => (int) ($item->getQuantityReceived() ?? 0),
                'quantity_in_case' => (int) ($item->getQuantityInCase() ?? 0),
            ];
        }, $items);
    }

    private function shipmentPayloadV2024(object $shipment): array
    {
        return [
            'shipment_id' => (string) ($shipment->getShipmentId() ?? ''),
            'status' => (string) ($shipment->getStatus() ?? ''),
            'name' => (string) ($shipment->getName() ?? ''),
            'destination' => $this->safeJson($shipment->getDestination()),
        ];
    }

    private function itemsPayloadV2024(array $items): array
    {
        return array_map(static function ($item): array {
            return [
                'msku' => (string) ($item->getMsku() ?? ''),
                'quantity' => (int) ($item->getQuantity() ?? 0),
            ];
        }, $items);
    }

    private function safeJson(mixed $value): ?array
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['_serialization_error' => 'json_encode_failed'];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : ['_serialization_error' => 'json_decode_not_array'];
    }

}
