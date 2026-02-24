<?php

namespace App\Services;

use App\Models\Marketplace;
use App\Models\ReportJob;
use App\Services\ReportJobs\MarketplaceListingsReportJobProcessor;
use App\Services\ReportJobs\ReportJobProcessor;
use App\Services\ReportJobs\UsFcInventoryReportJobProcessor;
use Illuminate\Support\Carbon;
use SellingPartnerApi\Seller\ReportsV20210630\Dto\CreateReportSpecification;
use SellingPartnerApi\SellingPartnerApi;

class ReportJobOrchestrator
{
    public const PROVIDER_SP_API_SELLER = 'sp_api_seller';

    private const DEFAULT_POLL_DELAY_SECONDS = 30;
    private const EU_COUNTRY_CODES = ['BE', 'DE', 'ES', 'FR', 'GB', 'IE', 'IT', 'NL', 'PL', 'SE'];

    public function __construct(
        private readonly SpApiReportLifecycleService $lifecycle,
        private readonly RegionConfigService $regionConfig
    ) {
    }

    public function queueSpApiSellerJobs(
        string $reportType,
        ?array $marketplaceIds = null,
        ?string $region = null,
        ?array $reportOptions = null,
        ?string $processor = null,
        ?\DateTimeInterface $dataStartTime = null,
        ?\DateTimeInterface $dataEndTime = null,
        ?string $externalReportId = null,
        ?string $externalDocumentId = null,
        ?array $scope = null,
        int $pollAfterSeconds = 0
    ): array {
        $region = $this->normalizeRegion($region, $processor);
        $reportType = strtoupper(trim($reportType));
        $reportOptions = $this->normalizeReportOptions($reportType, $reportOptions);
        [$dataStartTime, $dataEndTime] = $this->normalizeDateRange($reportType, $dataStartTime, $dataEndTime);
        $marketplaceIds = $this->resolveMarketplaceIds($marketplaceIds, $processor);
        if (empty($marketplaceIds)) {
            return ['created' => 0, 'jobs' => []];
        }
        $windows = $this->buildDateWindows($reportType, $reportOptions, $dataStartTime, $dataEndTime);

        $jobs = [];
        foreach ($marketplaceIds as $marketplaceId) {
            foreach ($windows as [$windowStart, $windowEnd]) {
                $job = ReportJob::create([
                    'provider' => self::PROVIDER_SP_API_SELLER,
                    'processor' => $processor,
                    'region' => $region,
                    'marketplace_id' => $marketplaceId,
                    'report_type' => $reportType,
                    'status' => $externalReportId ? 'requested' : 'queued',
                    'scope' => $scope,
                    'report_options' => $reportOptions,
                    'data_start_time' => $windowStart,
                    'data_end_time' => $windowEnd,
                    'external_report_id' => $externalReportId,
                    'external_document_id' => $externalDocumentId,
                    'next_poll_at' => now()->addSeconds(max(0, $pollAfterSeconds)),
                ]);
                $jobs[] = $job;
            }
        }

        return [
            'created' => count($jobs),
            'jobs' => $jobs,
        ];
    }

    public function pollSpApiSellerJobs(
        int $limit = 100,
        ?string $processor = null,
        ?string $region = null,
        ?array $marketplaceIds = null,
        ?string $reportType = null
    ): array {
        $query = ReportJob::query()
            ->where('provider', self::PROVIDER_SP_API_SELLER)
            ->whereNull('completed_at')
            ->whereIn('status', ['queued', 'requested', 'processing'])
            ->where(function ($q) {
                $q->whereNull('next_poll_at')
                    ->orWhere('next_poll_at', '<=', now());
            });

        if ($processor !== null && trim($processor) !== '') {
            $query->where('processor', trim($processor));
        }

        if ($region !== null && trim($region) !== '') {
            $query->where('region', strtoupper(trim($region)));
        }
        if (!empty($marketplaceIds)) {
            $query->whereIn('marketplace_id', array_values(array_filter(array_map('strval', $marketplaceIds))));
        }
        if ($reportType !== null && trim($reportType) !== '') {
            $query->where('report_type', strtoupper(trim($reportType)));
        }

        $jobs = $query->orderBy('id')->limit(max(1, $limit))->get();

        $checked = 0;
        $processed = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $checked++;
            $job->attempt_count = (int) $job->attempt_count + 1;
            $job->last_polled_at = now();

            $connector = $this->makeConnector($this->normalizeRegion($job->region, $job->processor));
            $reportsApi = $connector->reportsV20210630();

            if (trim((string) $job->external_report_id) === '') {
                $createResult = $this->lifecycle->createReportWithRetry(
                    $reportsApi,
                    new CreateReportSpecification(
                        reportType: $job->report_type,
                        marketplaceIds: [(string) $job->marketplace_id],
                        reportOptions: is_array($job->report_options) ? $job->report_options : null,
                        dataStartTime: $job->data_start_time,
                        dataEndTime: $job->data_end_time,
                    ),
                    [
                        'report_job_id' => $job->id,
                        'report_type' => $job->report_type,
                        'marketplace_id' => $job->marketplace_id,
                    ]
                );

                if (!($createResult['ok'] ?? false) || empty($createResult['report_id'])) {
                    $job->status = 'failed';
                    $job->last_error = (string) ($createResult['error'] ?? 'Unable to create report.');
                    $job->completed_at = now();
                    $failed++;
                    $job->save();
                    continue;
                }

                $job->external_report_id = (string) $createResult['report_id'];
                $job->requested_at = now();
                $job->status = 'requested';
            }

            $poll = $this->lifecycle->pollReportOnce($reportsApi, (string) $job->external_report_id);
            $status = strtoupper((string) ($poll['processing_status'] ?? 'IN_QUEUE'));
            $documentId = trim((string) ($poll['report_document_id'] ?? ''));
            if ($documentId !== '') {
                $job->external_document_id = $documentId;
            }

            if ($status === 'DONE' && $job->external_document_id) {
                $download = $this->lifecycle->downloadReportRows(
                    $reportsApi,
                    (string) $job->external_document_id,
                    (string) $job->report_type
                );
                if (!($download['ok'] ?? false)) {
                    $job->status = 'failed';
                    $job->last_error = (string) ($download['error'] ?? 'Failed downloading report document.');
                    $job->completed_at = now();
                    $failed++;
                    $job->save();
                    continue;
                }

                $rows = is_array($download['rows'] ?? null) ? $download['rows'] : [];
                $job->rows_parsed = count($rows);
                $job->document_url_sha256 = $download['report_document_url_sha256'] ?? null;
                $job->debug_payload = [
                    'document_payload' => $download['document_payload'] ?? null,
                    'row_preview' => array_slice($rows, 0, 2),
                ];

                $ingested = $this->ingestRows($job, $rows);
                $job->rows_ingested = (int) ($ingested['rows_ingested'] ?? 0);
                $job->status = 'done';
                $job->completed_at = now();
                $job->last_error = null;
                $processed++;
                $job->save();
                continue;
            }

            if ($status === 'DONE_NO_DATA') {
                $job->status = 'done_no_data';
                $job->completed_at = now();
                $job->last_error = null;
                $processed++;
                $job->save();
                continue;
            }

            if (in_array($status, ['CANCELLED', 'FATAL'], true)) {
                $job->status = strtolower($status);
                $job->completed_at = now();
                $job->last_error = "Terminal report status: {$status}";
                $failed++;
                $job->save();
                continue;
            }

            $job->status = 'processing';
            $job->next_poll_at = now()->addSeconds(self::DEFAULT_POLL_DELAY_SECONDS);
            $job->save();
        }

        $outstanding = ReportJob::query()
            ->where('provider', self::PROVIDER_SP_API_SELLER)
            ->whereNull('completed_at')
            ->count();

        return [
            'checked' => $checked,
            'processed' => $processed,
            'failed' => $failed,
            'outstanding' => $outstanding,
        ];
    }

    private function ingestRows(ReportJob $job, array $rows): array
    {
        $processorKey = trim((string) ($job->processor ?? ''));
        if ($processorKey === '') {
            return ['rows_ingested' => 0];
        }

        $processor = $this->processorFor($processorKey);
        if (!$processor) {
            return ['rows_ingested' => 0];
        }

        return $processor->process($job, $rows);
    }

    private function processorFor(string $key): ?ReportJobProcessor
    {
        return match ($key) {
            'us_fc_inventory' => app(UsFcInventoryReportJobProcessor::class),
            'marketplace_listings' => app(MarketplaceListingsReportJobProcessor::class),
            default => null,
        };
    }

    private function resolveMarketplaceIds(?array $marketplaceIds, ?string $processor): array
    {
        $ids = array_values(array_filter(array_map('strval', $marketplaceIds ?? [])));
        if (!empty($ids)) {
            return $ids;
        }

        if (trim((string) $processor) === 'marketplace_listings') {
            return Marketplace::query()
                ->whereIn('country_code', self::EU_COUNTRY_CODES)
                ->pluck('id')
                ->values()
                ->all();
        }

        return [];
    }

    private function makeConnector(string $region): SellingPartnerApi
    {
        $config = $this->regionConfig->spApiConfig($region);

        return SellingPartnerApi::seller(
            clientId: (string) $config['client_id'],
            clientSecret: (string) $config['client_secret'],
            refreshToken: (string) $config['refresh_token'],
            endpoint: $this->regionConfig->spApiEndpointEnum($region),
        );
    }

    private function normalizeRegion(?string $region, ?string $processor): string
    {
        $region = strtoupper(trim((string) $region));
        if ($region !== '') {
            return $region;
        }

        return match (trim((string) $processor)) {
            'marketplace_listings' => 'EU',
            'us_fc_inventory' => 'NA',
            default => 'NA',
        };
    }

    private function normalizeReportOptions(string $reportType, ?array $reportOptions): ?array
    {
        $normalized = is_array($reportOptions) ? $reportOptions : [];
        if ($reportType === 'GET_LEDGER_SUMMARY_VIEW_DATA') {
            // Canonicalize report option keys expected by SP-API.
            if (array_key_exists('aggregateByLocation', $normalized) && !array_key_exists('aggregatedByLocation', $normalized)) {
                $normalized['aggregatedByLocation'] = $normalized['aggregateByLocation'];
            }
            $normalized['aggregatedByLocation'] = 'LOCAL';
            unset($normalized['aggregateByLocation']);
            unset($normalized['aggregateByTimePeriod']);
            $normalized['aggregatedByTimePeriod'] = 'DAILY';
        }

        return !empty($normalized) ? $normalized : null;
    }

    private function normalizeDateRange(
        string $reportType,
        ?\DateTimeInterface $dataStartTime,
        ?\DateTimeInterface $dataEndTime
    ): array {
        if ($reportType !== 'GET_LEDGER_SUMMARY_VIEW_DATA') {
            return [$dataStartTime, $dataEndTime];
        }

        $end = $dataEndTime ? Carbon::instance($dataEndTime) : Carbon::now('UTC')->subDays(3)->endOfDay();
        $start = $dataStartTime ? Carbon::instance($dataStartTime) : $end->copy()->subDays(30)->startOfDay();

        if ($end->lessThan($start)) {
            $start = $end->copy()->subDays(30)->startOfDay();
        }

        return [$start, $end];
    }

    private function buildDateWindows(
        string $reportType,
        ?array $reportOptions,
        ?\DateTimeInterface $dataStartTime,
        ?\DateTimeInterface $dataEndTime
    ): array {
        if ($dataStartTime === null || $dataEndTime === null) {
            return [[$dataStartTime, $dataEndTime]];
        }

        $aggregatedByLocation = strtoupper(trim((string) ($reportOptions['aggregatedByLocation'] ?? '')));
        $requiresChunking = $reportType === 'GET_LEDGER_SUMMARY_VIEW_DATA' && $aggregatedByLocation === 'LOCAL';
        if (!$requiresChunking) {
            return [[$dataStartTime, $dataEndTime]];
        }

        $start = Carbon::instance($dataStartTime)->startOfDay();
        $end = Carbon::instance($dataEndTime)->endOfDay();
        if ($end->lessThan($start)) {
            return [[$dataStartTime, $dataEndTime]];
        }

        $windows = [];
        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            $windowStart = $cursor->copy();
            $windowEnd = $cursor->copy()->addDays(30)->endOfDay();
            if ($windowEnd->greaterThan($end)) {
                $windowEnd = $end->copy();
            }
            $windows[] = [$windowStart, $windowEnd];
            $cursor = $windowEnd->copy()->addSecond();
        }

        return !empty($windows) ? $windows : [[$dataStartTime, $dataEndTime]];
    }
}
