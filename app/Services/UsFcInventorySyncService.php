<?php

namespace App\Services;

use App\Models\UsFcInventory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use SellingPartnerApi\Seller\ReportsV20210630\Dto\CreateReportSpecification;
use SellingPartnerApi\SellingPartnerApi;

class UsFcInventorySyncService
{
    public const DEFAULT_REPORT_TYPE = 'GET_FBA_MYI_UNSUPPRESSED_INVENTORY_DATA';
    public const DEFAULT_US_MARKETPLACE_ID = 'ATVPDKIKX0DER';
    private const FALLBACK_REPORT_TYPES = [
        'GET_FBA_MYI_UNSUPPRESSED_INVENTORY_DATA',
        'GET_LEDGER_SUMMARY_VIEW_DATA',
    ];
    public function __construct(
        private readonly SpApiReportLifecycleService $reportLifecycle,
        private readonly FcLocationRegistryService $fcLocationRegistry
    ) {
    }

    public function sync(
        string $region = 'NA',
        string $marketplaceId = self::DEFAULT_US_MARKETPLACE_ID,
        string $reportType = self::DEFAULT_REPORT_TYPE,
        int $maxAttempts = 30,
        int $sleepSeconds = 5,
        ?string $startDate = null,
        ?string $endDate = null,
        bool $debugJson = false
    ): array {
        $reportType = strtoupper(trim($reportType));
        if ($reportType === '') {
            $reportType = self::DEFAULT_REPORT_TYPE;
        }
        $maxAttempts = max(1, min($maxAttempts, 120));
        $sleepSeconds = max(1, min($sleepSeconds, 20));
        [$dataStartTime, $dataEndTime] = $this->resolveReportWindow($startDate, $endDate);

        $connector = $this->makeConnector($region);
        $reportsApi = $connector->reportsV20210630();
        $reportTypes = $this->resolveReportTypes($reportType);

        $attempted = [];
        $last = null;
        foreach ($reportTypes as $candidateType) {
            $result = $this->syncSingleReportType(
                $reportsApi,
                $marketplaceId,
                $candidateType,
                $maxAttempts,
                $sleepSeconds,
                $dataStartTime,
                $dataEndTime,
                $debugJson
            );
            $result['attempted_report_types'] = array_values(array_unique([...$attempted, $candidateType]));
            $last = $result;
            $attempted[] = $candidateType;

            if (($result['ok'] ?? false) && (int) ($result['rows'] ?? 0) > 0) {
                $result['report_type_used'] = $candidateType;
                return $result;
            }
        }

        if (is_array($last)) {
            $last['attempted_report_types'] = array_values(array_unique($attempted));
            $last['message'] = trim((string) ($last['message'] ?? '')) !== ''
                ? (string) $last['message']
                : 'No inventory rows returned from any report type attempted.';
            return $last;
        }

        return [
            'ok' => false,
            'message' => 'FC inventory sync failed before any report attempt.',
            'rows' => 0,
            'attempted_report_types' => array_values(array_unique($attempted)),
        ];
    }

    private function syncSingleReportType(
        object $reportsApi,
        string $marketplaceId,
        string $reportType,
        int $maxAttempts,
        int $sleepSeconds,
        ?\DateTimeInterface $dataStartTime = null,
        ?\DateTimeInterface $dataEndTime = null,
        bool $debugJson = false
    ): array {
        $debugPayload = [
            'create_report' => null,
            'get_report_polls' => [],
            'get_report_document' => null,
        ];
        $createResult = $this->reportLifecycle->createReportWithRetry(
            $reportsApi,
            new CreateReportSpecification(
                reportType: $reportType,
                marketplaceIds: [$marketplaceId],
                reportOptions: $this->reportOptionsForType($reportType),
                dataStartTime: $dataStartTime,
                dataEndTime: $dataEndTime,
            ),
            [
                'report_type' => $reportType,
                'marketplace_id' => $marketplaceId,
            ]
        );
        if ($debugJson) {
            $debugPayload['create_report'] = $createResult['create_payload'] ?? null;
        }

        $reportId = trim((string) ($createResult['report_id'] ?? ''));
        if ($reportId === '') {
            return [
                'ok' => false,
                'message' => 'Unable to create inventory report. '
                    . (!empty($createResult['error']) ? 'Last error: ' . (string) $createResult['error'] : ''),
                'rows' => 0,
                'report_type' => $reportType,
                'debug_payload' => $debugJson ? $debugPayload : null,
            ];
        }

        $pollResult = $this->reportLifecycle->pollReportUntilTerminal(
            $reportsApi,
            $reportId,
            $maxAttempts,
            $sleepSeconds,
            $debugJson
        );
        if ($debugJson) {
            $debugPayload['get_report_polls'] = is_array($pollResult['polls'] ?? null)
                ? $pollResult['polls']
                : [];
        }

        $status = strtoupper((string) ($pollResult['processing_status'] ?? 'IN_QUEUE'));
        $reportDate = $pollResult['report_date'] ?? null;
        $reportDocumentId = trim((string) ($pollResult['report_document_id'] ?? ''));

        if ($status === 'DONE_NO_DATA') {
            return [
                'ok' => true,
                'message' => "Inventory report ended with status {$status}.",
                'rows' => 0,
                'rows_parsed' => 0,
                'rows_missing_fc' => 0,
                'rows_missing_sku' => 0,
                'sample_row_keys' => [],
                'report_id' => $reportId,
                'report_type' => $reportType,
                'report_date' => $reportDate,
                'processing_status' => $status,
                'debug_payload' => $debugJson ? $debugPayload : null,
            ];
        }

        if (in_array($status, ['CANCELLED', 'FATAL'], true)) {
            return [
                'ok' => false,
                'message' => "Inventory report ended with status {$status}.",
                'rows' => 0,
                'rows_parsed' => 0,
                'rows_missing_fc' => 0,
                'rows_missing_sku' => 0,
                'sample_row_keys' => [],
                'report_id' => $reportId,
                'report_type' => $reportType,
                'report_date' => $reportDate,
                'processing_status' => $status,
                'debug_payload' => $debugJson ? $debugPayload : null,
            ];
        }

        if ($status !== 'DONE' || $reportDocumentId === '' || !($pollResult['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => "Inventory report not ready. Last status {$status}.",
                'rows' => 0,
                'report_id' => $reportId,
                'report_type' => $reportType,
                'debug_payload' => $debugJson ? $debugPayload : null,
            ];
        }

        $downloadResult = $this->reportLifecycle->downloadReportRows(
            $reportsApi,
            $reportDocumentId,
            $reportType
        );
        if ($debugJson) {
            $debugPayload['get_report_document'] = $downloadResult['document_payload'] ?? null;
        }
        if (!($downloadResult['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'Failed downloading report document via SDK. Last error: '
                    . (string) ($downloadResult['error'] ?? 'Unknown error'),
                'report_id' => $reportId,
                'rows' => 0,
                'report_type' => $reportType,
                'debug_payload' => $debugJson ? $debugPayload : null,
                'report_document_url_sha256' => $downloadResult['report_document_url_sha256'] ?? null,
            ];
        }
        $rows = is_array($downloadResult['rows'] ?? null) ? $downloadResult['rows'] : [];
        $documentUrlSha256 = $downloadResult['report_document_url_sha256'] ?? null;
        $locationRowsUpserted = $this->fcLocationRegistry->ingestRows($rows, $marketplaceId);

        $parsedRowPreview = array_slice($rows, 0, 2);
        $upsertRows = [];
        $missingFcRows = 0;
        $missingSkuRows = 0;
        $sampleKeys = [];
        $now = now();
        foreach ($rows as $row) {
            $normalized = $this->normalizeRow($row);
            $fc = $normalized['fulfillment_center_id'];
            $sku = $normalized['seller_sku'];
            $fnsku = $normalized['fnsku'];
            $condition = $normalized['item_condition'];

            if ($fc === '') {
                $missingFcRows++;
                if (count($sampleKeys) < 3) {
                    $sampleKeys[] = array_keys($row);
                }
                continue;
            }

            if ($sku === '' && $fnsku === '') {
                $missingSkuRows++;
                continue;
            }

            $upsertRows[] = [
                'marketplace_id' => $marketplaceId,
                'fulfillment_center_id' => $fc,
                'seller_sku' => $sku !== '' ? $sku : null,
                'asin' => $normalized['asin'] !== '' ? $normalized['asin'] : null,
                'fnsku' => $fnsku !== '' ? $fnsku : null,
                'item_condition' => $condition !== '' ? $condition : null,
                'quantity_available' => $normalized['quantity_available'],
                'raw_row' => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'report_id' => $reportId,
                'report_type' => $reportType,
                'report_date' => $reportDate,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($upsertRows)) {
            UsFcInventory::upsert(
                $upsertRows,
                ['marketplace_id', 'fulfillment_center_id', 'seller_sku', 'fnsku', 'item_condition'],
                ['asin', 'quantity_available', 'raw_row', 'report_id', 'report_type', 'report_date', 'last_seen_at', 'updated_at']
            );
        }

        Log::info('FC inventory sync complete', [
            'report_id' => $reportId,
            'report_type' => $reportType,
            'rows_parsed' => count($rows),
            'rows_upserted' => count($upsertRows),
        ]);

        return [
            'ok' => true,
            'message' => 'FC inventory sync complete.',
            'report_id' => $reportId,
            'rows' => count($upsertRows),
            'rows_parsed' => count($rows),
            'rows_missing_fc' => $missingFcRows,
            'rows_missing_sku' => $missingSkuRows,
            'location_rows_upserted' => $locationRowsUpserted,
            'sample_row_keys' => $sampleKeys,
            'parsed_row_preview' => $parsedRowPreview,
            'report_type' => $reportType,
            'report_date' => $reportDate,
            'debug_payload' => $debugJson ? $debugPayload : null,
            'report_document_url_sha256' => $documentUrlSha256,
        ];
    }

    private function makeConnector(string $region): SellingPartnerApi
    {
        $regionService = new RegionConfigService();
        $config = $regionService->spApiConfig($region);

        return SellingPartnerApi::seller(
            clientId: (string) $config['client_id'],
            clientSecret: (string) $config['client_secret'],
            refreshToken: (string) $config['refresh_token'],
            endpoint: $regionService->spApiEndpointEnum($region)
        );
    }

    private function normalizeRow(array $row): array
    {
        $flat = [];
        foreach ($row as $key => $value) {
            $flat[strtolower(trim((string) $key))] = is_scalar($value) || $value === null ? (string) ($value ?? '') : '';
        }

        $fc = $this->pick($flat, ['fulfillment-center-id', 'fulfillment_center_id', 'fulfillment center id', 'fulfillmentcenterid']);
        if ($fc === '') {
            $fc = $this->pick($flat, [
                'warehouse-id',
                'warehouse_id',
                'warehouse id',
                'warehouseid',
                'location-id',
                'location_id',
                'location id',
                'locationid',
                'location',
                'store',
                'fc',
            ]);
        }

        $sku = $this->pick($flat, ['seller-sku', 'seller_sku', 'sku', 'merchant-sku', 'merchant_sku', 'merchant sku']);
        $asin = $this->pick($flat, ['asin']);
        $fnsku = $this->pick($flat, ['fnsku', 'fnsku']);
        $condition = $this->pick($flat, ['condition', 'item-condition', 'item_condition']);
        $qtyRaw = $this->pick($flat, [
            'quantity',
            'afn-fulfillable-quantity',
            'afn_fulfillable_quantity',
            'fulfillable-quantity',
            'fulfillable_quantity',
            'total-quantity',
            'total_quantity',
            'available',
            'available_quantity',
            'available quantity',
            'sellable-quantity',
            'sellable_quantity',
            'sellable quantity',
            'ending warehouse balance',
            'ending_warehouse_balance',
            'ending-warehouse-balance',
            'warehouse balance',
            'warehouse_balance',
        ]);
        $qty = $this->parseQuantity($qtyRaw);
        if ($qty === 0) {
            $qty = $this->inferQuantityFromFlat($flat);
        }

        return [
            'fulfillment_center_id' => strtoupper(trim($fc)),
            'seller_sku' => trim($sku),
            'asin' => strtoupper(trim($asin)),
            'fnsku' => strtoupper(trim($fnsku)),
            'item_condition' => trim($condition),
            'quantity_available' => $qty,
        ];
    }

    private function pick(array $flat, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $flat)) {
                return (string) $flat[$key];
            }
        }

        return '';
    }

    private function parseQuantity(string $raw): int
    {
        $value = trim($raw);
        if ($value === '') {
            return 0;
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/', $value, $matches)) {
            $value = trim((string) ($matches[1] ?? ''));
            $negative = true;
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', str_replace([',', ' '], '', $value));
        $normalized = is_string($normalized) ? $normalized : '';
        if (!is_numeric($normalized)) {
            return 0;
        }

        $number = (float) $normalized;
        if ($negative) {
            $number *= -1;
        }

        return (int) round($number);
    }

    private function inferQuantityFromFlat(array $flat): int
    {
        $priorityPatterns = [
            'ending warehouse balance',
            'warehouse balance',
            'fulfillable',
            'available',
            'sellable',
            'quantity',
            'balance',
        ];

        foreach ($priorityPatterns as $pattern) {
            foreach ($flat as $key => $value) {
                $k = strtolower(trim((string) $key));
                if ($k === '' || !str_contains($k, $pattern)) {
                    continue;
                }
                if (str_contains($k, 'date') || str_contains($k, 'time') || str_contains($k, 'id')) {
                    continue;
                }

                $qty = $this->parseQuantity((string) $value);
                if ($qty !== 0) {
                    return $qty;
                }
            }
        }

        return 0;
    }

    private function resolveReportTypes(string $reportType): array
    {
        $requested = strtoupper(trim($reportType));

        if ($requested === '' || $requested === 'AUTO') {
            return self::FALLBACK_REPORT_TYPES;
        }

        if ($requested === self::DEFAULT_REPORT_TYPE) {
            return array_values(array_unique([$requested, ...self::FALLBACK_REPORT_TYPES]));
        }

        return [$requested];
    }

    private function reportOptionsForType(string $reportType): ?array
    {
        $reportType = strtoupper(trim($reportType));

        if ($reportType === 'GET_LEDGER_SUMMARY_VIEW_DATA') {
            return [
                'aggregateByLocation' => 'FC',
                'aggregatedByTimePeriod' => 'DAILY',
            ];
        }

        return null;
    }

    private function resolveReportWindow(?string $startDate, ?string $endDate): array
    {
        $startDate = trim((string) $startDate);
        $endDate = trim((string) $endDate);

        if ($startDate === '' && $endDate === '') {
            return [null, null];
        }

        $tz = config('app.timezone', 'UTC');
        $start = null;
        $end = null;

        if ($startDate !== '') {
            try {
                $start = Carbon::parse($startDate, $tz)->startOfDay();
            } catch (\Throwable) {
                $start = null;
            }
        }

        if ($endDate !== '') {
            try {
                $end = Carbon::parse($endDate, $tz)->endOfDay();
            } catch (\Throwable) {
                $end = null;
            }
        }

        return [$start, $end];
    }

}
