<?php

namespace App\Services;

use App\Models\Marketplace;
use App\Models\MarketplaceListing;
use App\Models\SpApiReportRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Saloon\Http\Response as SaloonResponse;
use SellingPartnerApi\Enums\Endpoint;
use SellingPartnerApi\Seller\ReportsV20210630\Dto\CreateReportSpecification;
use SellingPartnerApi\SellingPartnerApi;

class MarketplaceListingsSyncService
{
    public const DEFAULT_REPORT_TYPE = 'GET_MERCHANT_LISTINGS_ALL_DATA';

    private const EUROPEAN_COUNTRY_CODES = ['BE', 'DE', 'ES', 'FR', 'GB', 'IE', 'IT', 'NL', 'PL', 'SE'];
    private const MAX_BACKGROUND_RETRIES = 20;
    private const STUCK_REPORT_SECONDS = 3600;

    public function syncEurope(
        ?array $marketplaceIds = null,
        int $maxAttempts = 8,
        int $sleepSeconds = 3,
        string $reportType = self::DEFAULT_REPORT_TYPE
    ): array {
        $queueResult = $this->queueEuropeReports($marketplaceIds, $reportType);
        $reportIds = $queueResult['report_ids'] ?? [];
        if (empty($reportIds)) {
            return ['synced' => 0, 'marketplaces' => $queueResult['marketplaces'] ?? []];
        }

        $iterations = max(1, $maxAttempts);
        for ($i = 0; $i < $iterations; $i++) {
            $pollResult = $this->pollQueuedReports(200, $marketplaceIds, $reportType, $reportIds);
            if (($pollResult['outstanding'] ?? 0) <= 0) {
                break;
            }
            sleep(max(1, $sleepSeconds));
        }

        $requests = SpApiReportRequest::query()->whereIn('report_id', $reportIds)->get()->groupBy('marketplace_id');
        $summary = [];
        $totalSynced = 0;

        foreach ($requests as $marketplaceId => $items) {
            $synced = (int) $items->sum('rows_synced');
            $parents = (int) $items->sum('parents_synced');
            $totalSynced += $synced;

            $error = $items->firstWhere('error_message', '!=', null)->error_message ?? null;
            $summary[$marketplaceId] = [
                'synced' => $synced,
                'parents' => $parents,
            ];
            if ($error) {
                $summary[$marketplaceId]['error'] = $error;
            }
        }

        return [
            'synced' => $totalSynced,
            'marketplaces' => $summary,
        ];
    }

    public function queueEuropeReports(?array $marketplaceIds = null, string $reportType = self::DEFAULT_REPORT_TYPE): array
    {
        $marketplaceIds = $this->resolveMarketplaceIds($marketplaceIds);
        if (empty($marketplaceIds)) {
            return ['created' => 0, 'existing' => 0, 'failed' => 0, 'outstanding' => 0, 'report_ids' => [], 'marketplaces' => []];
        }

        $connector = $this->makeConnector();
        $reportsApi = $connector->reportsV20210630();

        $created = 0;
        $existing = 0;
        $failed = 0;
        $reportIds = [];
        $marketplaceSummary = [];

        foreach ($marketplaceIds as $marketplaceId) {
            $reportId = null;
            $error = null;
            $createResponse = null;

            for ($attempt = 0; $attempt < 8; $attempt++) {
                try {
                    $createResponse = $reportsApi->createReport(new CreateReportSpecification($reportType, [$marketplaceId]));
                    $reportId = (string) ($createResponse->json('reportId') ?? '');
                    if ($reportId !== '') {
                        break;
                    }

                    $error = 'No reportId in createReport response';
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                    $response = $this->responseFromThrowable($e);
                    $sleep = $this->withJitter($this->retryAfterSeconds($response, 4 + $attempt), 0.25);
                    Log::warning('SP-API listings createReport retry', [
                        'marketplace_id' => $marketplaceId,
                        'report_type' => $reportType,
                        'attempt' => $attempt,
                        'sleep_seconds' => $sleep,
                        'status' => $response?->status(),
                        'request_id' => $this->extractRequestId($response),
                        'error' => $e->getMessage(),
                    ]);
                    sleep($sleep);
                    continue;
                }
            }

            if (!$reportId) {
                $failed++;
                $marketplaceSummary[$marketplaceId] = ['synced' => 0, 'error' => $error ?: 'Failed to create report'];
                continue;
            }

            $model = SpApiReportRequest::firstOrNew(['report_id' => $reportId]);
            $isNew = !$model->exists;
            $model->fill([
                'marketplace_id' => $marketplaceId,
                'report_type' => $reportType,
                'status' => strtoupper((string) ($createResponse?->json('processingStatus') ?? 'IN_QUEUE')),
                'requested_at' => $model->requested_at ?? now(),
                'next_check_at' => $model->next_check_at ?? now(),
                'retry_count' => 0,
                'last_http_status' => $createResponse ? (string) $createResponse->status() : null,
                'last_request_id' => $this->extractRequestId($createResponse),
                'error_message' => null,
            ]);
            $model->save();

            if ($isNew) {
                $created++;
            } else {
                $existing++;
            }

            $reportIds[] = $reportId;
            $marketplaceSummary[$marketplaceId] = ['synced' => 0];
        }

        $outstanding = SpApiReportRequest::query()
            ->whereIn('report_id', $reportIds)
            ->whereNull('processed_at')
            ->count();

        return [
            'created' => $created,
            'existing' => $existing,
            'failed' => $failed,
            'outstanding' => $outstanding,
            'report_ids' => $reportIds,
            'marketplaces' => $marketplaceSummary,
        ];
    }

    public function pollQueuedReports(
        int $limit = 100,
        ?array $marketplaceIds = null,
        string $reportType = self::DEFAULT_REPORT_TYPE,
        ?array $reportIds = null
    ): array {
        $connector = $this->makeConnector();
        $reportsApi = $connector->reportsV20210630();

        $query = SpApiReportRequest::query()
            ->whereNull('processed_at')
            ->where(function ($q) {
                $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
            });

        if (!empty($marketplaceIds)) {
            $query->whereIn('marketplace_id', $marketplaceIds);
        }

        if (!empty($reportType)) {
            $query->where('report_type', $reportType);
        }

        if (!empty($reportIds)) {
            $query->whereIn('report_id', $reportIds);
        }

        $requests = $query->orderBy('requested_at')->limit(max(1, $limit))->get();

        $checked = 0;
        $processed = 0;
        $failed = 0;

        foreach ($requests as $request) {
            $checked++;
            $this->maybeAlertStuck($request);

            try {
                $response = $reportsApi->getReport($request->report_id);
                $status = strtoupper((string) ($response->json('processingStatus') ?? 'IN_QUEUE'));
                $documentId = (string) ($response->json('reportDocumentId') ?? '');

                $request->last_checked_at = now();
                $request->check_attempts = (int) $request->check_attempts + 1;
                $request->waited_seconds = $request->requested_at ? $request->requested_at->diffInSeconds(now()) : 0;
                $request->status = $status;
                $request->report_document_id = $documentId !== '' ? $documentId : $request->report_document_id;
                $request->last_http_status = (string) $response->status();
                $request->last_request_id = $this->extractRequestId($response);
                $request->retry_count = 0;

                if ($status === 'DONE' && $request->report_document_id) {
                    try {
                        $documentResponse = $reportsApi->getReportDocument($request->report_document_id, $request->report_type);
                        $request->last_http_status = (string) $documentResponse->status();
                        $request->last_request_id = $this->extractRequestId($documentResponse);

                        $document = $documentResponse->dto();
                        $data = $document->download($request->report_type);
                        $rows = is_array($data) ? collect($data) : collect();

                        $syncedCount = $this->upsertRows($request->marketplace_id, $rows, $request->report_id);
                        $parentCount = $this->syncParentAsinsFromCatalog($connector, $request->marketplace_id, $rows, $request->report_id);

                        $request->rows_synced = $syncedCount;
                        $request->parents_synced = $parentCount;
                        $request->processed_at = now();
                        $request->next_check_at = null;
                        $request->error_message = null;
                        $processed++;
                    } catch (\Throwable $e) {
                        $this->scheduleRetry($request, $this->responseFromThrowable($e), 30);
                        $request->error_message = $e->getMessage();
                    }
                } elseif (in_array($status, ['DONE_NO_DATA'], true)) {
                    $request->processed_at = now();
                    $request->next_check_at = null;
                    $request->error_message = null;
                } elseif (in_array($status, ['CANCELLED', 'FATAL'], true)) {
                    $request->processed_at = now();
                    $request->next_check_at = null;
                    $request->error_message = 'Terminal report status: ' . $status;
                    $failed++;
                } else {
                    $request->next_check_at = now()->addSeconds($this->withJitter(30, 0.2));
                }

                $request->save();
            } catch (\Throwable $e) {
                $response = $this->responseFromThrowable($e);
                $request->last_checked_at = now();
                $request->check_attempts = (int) $request->check_attempts + 1;
                $request->last_http_status = $response ? (string) $response->status() : null;
                $request->last_request_id = $this->extractRequestId($response);
                $request->error_message = $e->getMessage();
                $this->scheduleRetry($request, $response, 20);
                $request->save();
            }
        }

        $outstandingQuery = SpApiReportRequest::query()->whereNull('processed_at');
        if (!empty($marketplaceIds)) {
            $outstandingQuery->whereIn('marketplace_id', $marketplaceIds);
        }
        if (!empty($reportType)) {
            $outstandingQuery->where('report_type', $reportType);
        }
        if (!empty($reportIds)) {
            $outstandingQuery->whereIn('report_id', $reportIds);
        }

        return [
            'checked' => $checked,
            'processed' => $processed,
            'failed' => $failed,
            'outstanding' => $outstandingQuery->count(),
        ];
    }

    private function resolveMarketplaceIds(?array $marketplaceIds = null): array
    {
        if ($marketplaceIds !== null && !empty($marketplaceIds)) {
            return array_values(array_filter(array_map('strval', $marketplaceIds)));
        }

        return Marketplace::query()
            ->whereIn('country_code', self::EUROPEAN_COUNTRY_CODES)
            ->pluck('id')
            ->values()
            ->all();
    }

    private function makeConnector(): SellingPartnerApi
    {
        return SellingPartnerApi::seller(
            clientId: (string) config('services.amazon_sp_api.client_id'),
            clientSecret: (string) config('services.amazon_sp_api.client_secret'),
            refreshToken: (string) config('services.amazon_sp_api.refresh_token'),
            endpoint: Endpoint::tryFrom(strtoupper((string) config('services.amazon_sp_api.endpoint', 'EU'))) ?? Endpoint::EU,
        );
    }

    private function upsertRows(string $marketplaceId, Collection $rows, string $reportId): int
    {
        $synced = 0;
        $now = now();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sku = $this->pick($row, ['seller-sku', 'seller_sku', 'sku']);
            if ($sku === '') {
                continue;
            }

            $asin = $this->pick($row, ['asin1', 'asin', 'asin-1', 'product-id']);
            $itemName = $this->pick($row, ['item-name', 'item_name', 'title']);
            $status = $this->pick($row, ['status', 'listing-status', 'listing_status', 'add-delete', 'add_delete']);
            $quantityRaw = $this->pick($row, ['quantity', 'pending-quantity', 'fulfillment-channel-quantity']);
            $parentage = strtolower($this->pick($row, ['parent-child', 'parent_child', 'parentage', 'relationship-type']));
            $isParent = in_array($parentage, ['parent', 'variation_parent'], true);
            $quantity = is_numeric($quantityRaw) ? (int) $quantityRaw : null;

            MarketplaceListing::updateOrCreate(
                [
                    'marketplace_id' => $marketplaceId,
                    'seller_sku' => $sku,
                ],
                [
                    'asin' => $asin !== '' ? $asin : null,
                    'item_name' => $itemName !== '' ? $itemName : null,
                    'listing_status' => $status !== '' ? $status : null,
                    'quantity' => $quantity,
                    'parentage' => $parentage !== '' ? $parentage : null,
                    'is_parent' => $isParent,
                    'source_report_id' => $reportId,
                    'last_seen_at' => $now,
                    'raw_listing' => $row,
                ]
            );

            $synced++;
        }

        return $synced;
    }

    private function pick(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function syncParentAsinsFromCatalog($connector, string $marketplaceId, Collection $rows, string $reportId): int
    {
        $childAsins = $rows
            ->map(fn ($row) => is_array($row) ? trim((string) ($row['asin1'] ?? $row['asin'] ?? '')) : '')
            ->filter()
            ->unique()
            ->values();

        if ($childAsins->isEmpty()) {
            return 0;
        }

        $catalogApi = $connector->catalogItemsV20220401();
        $parentAsins = collect();

        foreach ($childAsins as $asin) {
            for ($attempt = 0; $attempt < 4; $attempt++) {
                try {
                    $response = $catalogApi->getCatalogItem($asin, [$marketplaceId], ['relationships']);
                    $relationshipsByMarketplace = $response->json('relationships', []);
                    if (!is_array($relationshipsByMarketplace)) {
                        usleep(250000);
                        break;
                    }

                    foreach ($relationshipsByMarketplace as $marketplaceRelationships) {
                        foreach (($marketplaceRelationships['relationships'] ?? []) as $relationship) {
                            foreach (($relationship['parentAsins'] ?? []) as $parentAsin) {
                                $parentAsin = trim((string) $parentAsin);
                                if ($parentAsin !== '') {
                                    $parentAsins->push($parentAsin);
                                }
                            }
                        }
                    }

                    usleep(250000);
                    break;
                } catch (\Throwable $e) {
                    $response = $this->responseFromThrowable($e);
                    $sleep = $this->withJitter($this->retryAfterSeconds($response, 2 + $attempt), 0.25);
                    Log::warning('Catalog parent lookup retry', [
                        'marketplace_id' => $marketplaceId,
                        'asin' => $asin,
                        'attempt' => $attempt,
                        'sleep_seconds' => $sleep,
                        'status' => $response?->status(),
                        'request_id' => $this->extractRequestId($response),
                        'error' => $e->getMessage(),
                    ]);
                    usleep((int) ($sleep * 1000000));
                }
            }
        }

        $parentAsins = $parentAsins->unique()->values();
        foreach ($parentAsins as $parentAsin) {
            $updated = MarketplaceListing::query()
                ->where('marketplace_id', $marketplaceId)
                ->where('asin', $parentAsin)
                ->update([
                    'is_parent' => true,
                    'parentage' => 'catalog_parent',
                    'last_seen_at' => now(),
                ]);

            if ($updated > 0) {
                MarketplaceListing::query()
                    ->where('marketplace_id', $marketplaceId)
                    ->where('seller_sku', '__PARENT__' . $parentAsin)
                    ->delete();
                continue;
            }

            MarketplaceListing::updateOrCreate(
                [
                    'marketplace_id' => $marketplaceId,
                    'seller_sku' => '__PARENT__' . $parentAsin,
                ],
                [
                    'asin' => $parentAsin,
                    'item_name' => 'Parent ASIN (catalog relationship)',
                    'listing_status' => 'PARENT',
                    'quantity' => null,
                    'parentage' => 'catalog_parent',
                    'is_parent' => true,
                    'source_report_id' => $reportId,
                    'last_seen_at' => now(),
                    'raw_listing' => ['source' => 'catalogItems.relationships', 'asin' => $parentAsin],
                ]
            );
        }

        return $parentAsins->count();
    }

    private function scheduleRetry(SpApiReportRequest $request, ?SaloonResponse $response, int $fallbackSeconds): void
    {
        $request->retry_count = (int) $request->retry_count + 1;
        $delay = $this->withJitter($this->retryAfterSeconds($response, $fallbackSeconds), 0.25);
        $request->next_check_at = now()->addSeconds($delay);

        if ((int) $request->retry_count >= self::MAX_BACKGROUND_RETRIES) {
            $request->status = 'FAILED';
            $request->processed_at = now();
            $request->next_check_at = null;
            $request->error_message = $request->error_message ?: 'Exceeded max retries.';
        }
    }

    private function maybeAlertStuck(SpApiReportRequest $request): void
    {
        if ($request->requested_at === null || $request->processed_at !== null || $request->stuck_alerted_at !== null) {
            return;
        }

        $wait = $request->requested_at->diffInSeconds(now());
        if ($wait < self::STUCK_REPORT_SECONDS) {
            return;
        }

        $request->stuck_alerted_at = now();
        Log::warning('SP-API listing report appears stuck', [
            'report_id' => $request->report_id,
            'marketplace_id' => $request->marketplace_id,
            'status' => $request->status,
            'wait_seconds' => $wait,
        ]);
    }

    private function retryAfterSeconds(?SaloonResponse $response, int $fallback): int
    {
        if ($response === null) {
            return max(1, $fallback);
        }

        $retryAfter = $response->header('Retry-After');
        if (is_numeric($retryAfter)) {
            return max(1, (int) $retryAfter);
        }

        $limitReset = $response->header('x-amzn-RateLimit-Reset') ?? $response->header('X-Amzn-RateLimit-Reset');
        if (is_numeric($limitReset)) {
            return max(1, (int) ceil((float) $limitReset));
        }

        return max(1, $fallback);
    }

    private function withJitter(int $seconds, float $ratio = 0.25): int
    {
        $seconds = max(1, $seconds);
        $jitter = (int) ceil($seconds * max(0.0, $ratio));
        if ($jitter <= 0) {
            return $seconds;
        }

        try {
            $offset = random_int(-$jitter, $jitter);
        } catch (\Throwable) {
            $offset = 0;
        }

        return max(1, $seconds + $offset);
    }

    private function extractRequestId(?SaloonResponse $response): ?string
    {
        if ($response === null) {
            return null;
        }

        $value = (string) ($response->header('x-amzn-RequestId')
            ?? $response->header('x-amzn-requestid')
            ?? $response->header('x-amz-request-id')
            ?? $response->header('x-request-id')
            ?? '');

        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function responseFromThrowable(\Throwable $e): ?SaloonResponse
    {
        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if ($response instanceof SaloonResponse) {
                return $response;
            }
        }

        return null;
    }
}
