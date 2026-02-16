<?php

namespace App\Services;

use App\Models\UsFcInventory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use SellingPartnerApi\Seller\ReportsV20210630\Dto\CreateReportSpecification;
use SellingPartnerApi\SellingPartnerApi;

class UsFcInventorySyncService
{
    public const DEFAULT_REPORT_TYPE = 'GET_FBA_FULFILLMENT_CURRENT_INVENTORY_DATA';
    public const DEFAULT_US_MARKETPLACE_ID = 'ATVPDKIKX0DER';
    private const CREATE_REPORT_MAX_RETRIES = 8;
    private const CREATE_REPORT_BASE_BACKOFF_SECONDS = 15;

    public function sync(
        string $region = 'NA',
        string $marketplaceId = self::DEFAULT_US_MARKETPLACE_ID,
        string $reportType = self::DEFAULT_REPORT_TYPE,
        int $maxAttempts = 30,
        int $sleepSeconds = 5
    ): array {
        $maxAttempts = max(1, min($maxAttempts, 120));
        $sleepSeconds = max(1, min($sleepSeconds, 20));

        $connector = $this->makeConnector($region);
        $reportsApi = $connector->reportsV20210630();

        $reportId = '';
        $lastCreateError = null;
        for ($attempt = 0; $attempt < self::CREATE_REPORT_MAX_RETRIES; $attempt++) {
            try {
                $createResponse = $reportsApi->createReport(
                    new CreateReportSpecification($reportType, [$marketplaceId])
                );
                $reportId = trim((string) ($createResponse->json('reportId') ?? ''));
                if ($reportId !== '') {
                    break;
                }

                $lastCreateError = 'No reportId returned from createReport.';
            } catch (\Throwable $e) {
                $lastCreateError = $e->getMessage();
                if (!$this->isQuotaExceededError($e)) {
                    break;
                }

                $delay = $this->retryDelaySeconds($attempt);
                Log::warning('US FC inventory createReport quota retry', [
                    'attempt' => $attempt,
                    'sleep_seconds' => $delay,
                    'report_type' => $reportType,
                    'marketplace_id' => $marketplaceId,
                    'error' => $e->getMessage(),
                ]);
                sleep($delay);
                continue;
            }
        }

        if ($reportId === '') {
            return [
                'ok' => false,
                'message' => 'Unable to create inventory report. ' . ($lastCreateError ? 'Last error: ' . $lastCreateError : ''),
                'rows' => 0,
            ];
        }

        $status = 'IN_QUEUE';
        $reportDocumentId = '';
        $reportDate = null;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $poll = $reportsApi->getReport($reportId);
            $status = strtoupper((string) ($poll->json('processingStatus') ?? 'IN_QUEUE'));
            $reportDocumentId = trim((string) ($poll->json('reportDocumentId') ?? ''));

            $completedAt = trim((string) ($poll->json('processingEndTime') ?? ''));
            if ($completedAt !== '') {
                try {
                    $reportDate = Carbon::parse($completedAt)->toDateString();
                } catch (\Throwable) {
                    $reportDate = null;
                }
            }

            if ($status === 'DONE' && $reportDocumentId !== '') {
                break;
            }

            if (in_array($status, ['DONE_NO_DATA', 'CANCELLED', 'FATAL'], true)) {
                return [
                    'ok' => false,
                    'message' => "Inventory report ended with status {$status}.",
                    'rows' => 0,
                ];
            }

            sleep($sleepSeconds);
        }

        if ($status !== 'DONE' || $reportDocumentId === '') {
            return [
                'ok' => false,
                'message' => "Inventory report not ready. Last status {$status}.",
                'rows' => 0,
            ];
        }

        $documentResponse = $reportsApi->getReportDocument($reportDocumentId, $reportType);
        $document = $documentResponse->dto();
        $downloaded = $document->download($reportType);
        $rows = $this->normalizeDownloadedRows($downloaded);

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
                'raw_row' => $row,
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

        Log::info('US FC inventory sync complete', [
            'report_id' => $reportId,
            'report_type' => $reportType,
            'rows_parsed' => count($rows),
            'rows_upserted' => count($upsertRows),
        ]);

        return [
            'ok' => true,
            'message' => 'US FC inventory sync complete.',
            'report_id' => $reportId,
            'rows' => count($upsertRows),
            'rows_parsed' => count($rows),
            'rows_missing_fc' => $missingFcRows,
            'rows_missing_sku' => $missingSkuRows,
            'sample_row_keys' => $sampleKeys,
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

    private function normalizeDownloadedRows(mixed $downloaded): array
    {
        if (is_array($downloaded)) {
            if (array_is_list($downloaded)) {
                return array_values(array_filter($downloaded, fn ($row) => is_array($row)));
            }
            return [$downloaded];
        }

        if (is_string($downloaded)) {
            return $this->parseDelimitedText($downloaded);
        }

        return [];
    }

    private function parseDelimitedText(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($text));
        if (!$lines || count($lines) < 2) {
            return [];
        }

        $delimiter = str_contains($lines[0], "\t") ? "\t" : ',';
        $headers = str_getcsv($lines[0], $delimiter);
        $headers = array_map(fn ($h) => trim((string) $h), $headers);

        $rows = [];
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }
            $values = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    $header = 'col_' . ($index + 1);
                }
                $row[$header] = (string) ($values[$index] ?? '');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function normalizeRow(array $row): array
    {
        $flat = [];
        foreach ($row as $key => $value) {
            $flat[strtolower(trim((string) $key))] = is_scalar($value) || $value === null ? (string) ($value ?? '') : '';
        }

        $fc = $this->pick($flat, ['fulfillment-center-id', 'fulfillment_center_id', 'fulfillment center id', 'fulfillmentcenterid']);
        if ($fc === '') {
            $fc = $this->pick($flat, ['warehouse-id', 'warehouse_id', 'warehouse id', 'warehouseid', 'location-id', 'location_id', 'location id', 'locationid', 'fc']);
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
            'sellable-quantity',
            'sellable_quantity',
        ]);
        $qty = is_numeric($qtyRaw) ? (int) round((float) $qtyRaw) : 0;

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

    private function isQuotaExceededError(\Throwable $e): bool
    {
        $msg = strtoupper($e->getMessage());
        return str_contains($msg, '429')
            || str_contains($msg, 'QUOTAEXCEEDED')
            || str_contains($msg, 'TOO MANY REQUESTS');
    }

    private function retryDelaySeconds(int $attempt): int
    {
        $exp = self::CREATE_REPORT_BASE_BACKOFF_SECONDS * (2 ** $attempt);
        $cap = min(300, $exp);
        return max(1, $cap + random_int(0, 7));
    }
}
