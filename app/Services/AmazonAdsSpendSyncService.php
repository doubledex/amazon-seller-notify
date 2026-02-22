<?php

namespace App\Services;

use App\Models\AmazonAdsReportDailySpend;
use App\Models\AmazonAdsReportRequest;
use App\Models\DailyRegionAdSpend;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AmazonAdsSpendSyncService
{
    private const SOURCE = 'amazon_ads_api';
    private const MAX_BACKGROUND_RETRIES = 20;
    private const STUCK_REPORT_SECONDS = 3600;

    private const EU_COUNTRY_CODES = [
        'AT', 'BE', 'DE', 'DK', 'ES', 'FI', 'FR', 'IE', 'IT', 'LU', 'NL', 'NO', 'PL', 'SE', 'CH', 'TR',
    ];

    private const NA_COUNTRY_CODES = ['US', 'CA', 'MX', 'BR'];

    public function __construct(
        private readonly FxRateService $fxRateService,
        private readonly RegionConfigService $regionConfigService
    )
    {
    }

    public function syncRange(
        Carbon $from,
        Carbon $to,
        ?array $profileIds = null,
        ?int $maxProfiles = null,
        int $pollAttempts = 20,
        ?array $adProducts = null
    ): array {
        [$profiles, $tokenByApiRegion, $adsConfigByApiRegion] = $this->loadProfilesByConfiguredAdsRegions();
        if (empty($profiles)) {
            return ['ok' => false, 'message' => 'No Amazon Ads profiles available.', 'rows' => 0];
        }

        if (!empty($profileIds)) {
            $allowed = array_fill_keys(array_map('strval', $profileIds), true);
            $profiles = array_values(array_filter($profiles, function ($profile) use ($allowed) {
                return isset($allowed[(string) ($profile['profileId'] ?? '')]);
            }));
        }

        if ($maxProfiles !== null && $maxProfiles > 0) {
            $profiles = array_slice($profiles, 0, $maxProfiles);
        }

        $aggregated = [];
        $rowsByProfile = [];

        foreach ($profiles as $profile) {
            $profileId = (string) ($profile['profileId'] ?? '');
            $countryCode = strtoupper((string) ($profile['countryCode'] ?? ''));
            $currency = strtoupper((string) ($profile['currencyCode'] ?? ''));
            $apiRegion = strtoupper((string) ($profile['_ads_api_region'] ?? ''));
            if ($profileId === '' || $countryCode === '' || $currency === '') {
                continue;
            }

            $region = $this->toRegion($countryCode);
            if ($region === null) {
                continue;
            }

            $adsConfig = $adsConfigByApiRegion[$apiRegion] ?? null;
            $token = $tokenByApiRegion[$apiRegion] ?? null;
            if (!is_array($adsConfig) || !is_string($token) || $token === '') {
                Log::warning('Skipping profile due to missing ads API config/token', [
                    'profile_id' => $profileId,
                    'api_region' => $apiRegion,
                    'country_code' => $countryCode,
                ]);
                continue;
            }
            $rowsByProfile[$profileId] = 0;

            foreach ($this->splitRange($from, $to, 31) as [$chunkFrom, $chunkTo]) {
                $rows = $this->fetchSpendRowsForProfile($token, $adsConfig, $profileId, $chunkFrom, $chunkTo, $pollAttempts, $adProducts);
                foreach ($rows as $row) {
                    $date = (string) ($row['date'] ?? '');
                    $amount = (float) ($row['spend'] ?? 0);
                    if ($date === '' || $amount <= 0) {
                        continue;
                    }

                    $targetCurrency = $this->targetCurrencyForRegion($region);
                    $converted = $this->fxRateService->convert($amount, $currency, $targetCurrency, $date);
                    if ($converted === null) {
                        Log::warning('Amazon Ads spend conversion failed', [
                            'date' => $date,
                            'profile_id' => $profileId,
                            'from_currency' => $currency,
                            'to_currency' => $targetCurrency,
                            'amount' => $amount,
                        ]);
                        continue;
                    }

                    $key = "{$date}|{$region}|{$targetCurrency}";
                    $aggregated[$key] = ($aggregated[$key] ?? 0) + $converted;
                    $rowsByProfile[$profileId]++;
                }
            }

            if ($rowsByProfile[$profileId] === 0) {
                $legacyRows = $this->fetchLegacySponsoredProductsSpend($token, $adsConfig, $profileId, $from, $to);
                foreach ($legacyRows as $row) {
                    $date = (string) ($row['date'] ?? '');
                    $amount = (float) ($row['spend'] ?? 0);
                    if ($date === '' || $amount <= 0) {
                        continue;
                    }

                    $targetCurrency = $this->targetCurrencyForRegion($region);
                    $converted = $this->fxRateService->convert($amount, $currency, $targetCurrency, $date);
                    if ($converted === null) {
                        continue;
                    }

                    $key = "{$date}|{$region}|{$targetCurrency}";
                    $aggregated[$key] = ($aggregated[$key] ?? 0) + $converted;
                    $rowsByProfile[$profileId]++;
                }
            }
        }

        $upsertRows = [];
        foreach ($aggregated as $key => $amount) {
            [$date, $region, $currency] = explode('|', $key);
            $upsertRows[] = [
                'metric_date' => $date,
                'region' => $region,
                'currency' => $currency,
                'amount_local' => round($amount, 2),
                'source' => self::SOURCE,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($upsertRows)) {
            DailyRegionAdSpend::upsert(
                $upsertRows,
                ['metric_date', 'region', 'currency', 'source'],
                ['amount_local', 'updated_at']
            );
        }

        return [
            'ok' => true,
            'message' => 'Amazon Ads spend sync complete.',
            'rows' => count($upsertRows),
            'rows_by_profile' => $rowsByProfile,
        ];
    }

    public function queueRangeReports(
        Carbon $from,
        Carbon $to,
        ?array $profileIds = null,
        ?int $maxProfiles = null,
        ?array $adProducts = null
    ): array {
        [$profiles, $tokenByApiRegion, $adsConfigByApiRegion] = $this->loadProfilesByConfiguredAdsRegions();
        if (empty($profiles)) {
            return ['ok' => false, 'message' => 'No Amazon Ads profiles available.'];
        }

        if (!empty($profileIds)) {
            $allowed = array_fill_keys(array_map('strval', $profileIds), true);
            $profiles = array_values(array_filter($profiles, function ($profile) use ($allowed) {
                return isset($allowed[(string) ($profile['profileId'] ?? '')]);
            }));
        }

        if ($maxProfiles !== null && $maxProfiles > 0) {
            $profiles = array_slice($profiles, 0, $maxProfiles);
        }

        $configs = $this->reportConfigs($adProducts);

        $created = 0;
        $existing = 0;
        $failed = 0;

        foreach ($profiles as $profile) {
            $profileId = (string) ($profile['profileId'] ?? '');
            $countryCode = strtoupper((string) ($profile['countryCode'] ?? ''));
            $currency = strtoupper((string) ($profile['currencyCode'] ?? ''));
            $apiRegion = strtoupper((string) ($profile['_ads_api_region'] ?? ''));
            if ($profileId === '' || $countryCode === '' || $currency === '') {
                continue;
            }

            $region = $this->toRegion($countryCode);
            if ($region === null) {
                continue;
            }

            $adsConfig = $adsConfigByApiRegion[$apiRegion] ?? null;
            $token = $tokenByApiRegion[$apiRegion] ?? null;
            if (!is_array($adsConfig) || !is_string($token) || $token === '') {
                $failed++;
                Log::warning('Ads queue skipped profile due missing config/token', [
                    'profile_id' => $profileId,
                    'api_region' => $apiRegion,
                    'country_code' => $countryCode,
                ]);
                continue;
            }

            $baseUrl = rtrim((string) ($adsConfig['base_url'] ?? ''), '/');
            $clientId = trim((string) ($adsConfig['client_id'] ?? ''));

            foreach ($this->splitRange($from, $to, 31) as [$chunkFrom, $chunkTo]) {
                foreach ($configs as $config) {
                    $existingOutstanding = $this->findOutstandingReportRequest(
                        $profileId,
                        $config['adProduct'],
                        $config['reportTypeId'],
                        $chunkFrom->toDateString(),
                        $chunkTo->toDateString()
                    );
                    if ($existingOutstanding) {
                        if ($existingOutstanding->next_check_at === null || $existingOutstanding->next_check_at->gt(now())) {
                            $existingOutstanding->next_check_at = now();
                            $existingOutstanding->save();
                        }
                        $existing++;
                        continue;
                    }

                    $reportName = strtolower($config['adProduct']) . '_daily_spend_' . $profileId . '_' . $chunkFrom->format('Ymd') . '_' . $chunkTo->format('Ymd');
                    $createResponse = null;
                    $reportId = '';

                    for ($attempt = 0; $attempt < 10; $attempt++) {
                        $createResponse = Http::timeout(30)
                            ->withToken($token)
                            ->withHeaders([
                                'Amazon-Advertising-API-ClientId' => $clientId,
                                'Amazon-Advertising-API-Scope' => $profileId,
                                'Content-Type' => 'application/vnd.createasyncreportrequest.v3+json',
                                'Accept' => 'application/vnd.createasyncreportresponse.v3+json',
                            ])
                            ->post($baseUrl . '/reporting/reports', [
                                'name' => $reportName,
                                'startDate' => $chunkFrom->toDateString(),
                                'endDate' => $chunkTo->toDateString(),
                                'configuration' => [
                                    'adProduct' => $config['adProduct'],
                                    'groupBy' => ['campaign'],
                                    'columns' => ['date', 'cost'],
                                    'reportTypeId' => $config['reportTypeId'],
                                    'timeUnit' => 'DAILY',
                                    'format' => 'GZIP_JSON',
                                ],
                            ]);

                        $statusCode = $createResponse->status();
                        Log::info('Ads queue create attempt', [
                            'profile_id' => $profileId,
                            'ad_product' => $config['adProduct'],
                            'attempt' => $attempt,
                            'status' => $statusCode,
                            'request_id' => $this->extractRequestId($createResponse),
                            'start' => $chunkFrom->toDateString(),
                            'end' => $chunkTo->toDateString(),
                        ]);

                        if ($statusCode === 425) {
                            $this->logRetryHeaders($createResponse, 'queue-create', $profileId, $config['adProduct'], $attempt);
                            $reportId = $this->extractReportId($createResponse) ?? '';
                            if ($reportId !== '') {
                                break;
                            }
                            sleep($this->withJitter($this->resolveRetryDelaySeconds($createResponse, 4 + $attempt), 0.25));
                            continue;
                        }

                        if ($statusCode === 429) {
                            $this->logRetryHeaders($createResponse, 'queue-create', $profileId, $config['adProduct'], $attempt);
                            sleep($this->withJitter($this->resolveRetryDelaySeconds($createResponse, 5 + ($attempt * 2)), 0.25));
                            continue;
                        }

                        break;
                    }

                    if ($createResponse === null) {
                        $failed++;
                        continue;
                    }

                    if ($reportId === '') {
                        $reportId = $this->extractReportId($createResponse) ?? '';
                    }

                    if ($reportId === '') {
                        $failed++;
                        Log::warning('Ads queue create failed (no report id)', [
                            'profile_id' => $profileId,
                            'ad_product' => $config['adProduct'],
                            'status' => $createResponse->status(),
                            'request_id' => $this->extractRequestId($createResponse),
                            'body' => $createResponse->body(),
                        ]);
                        continue;
                    }

                    $status = strtoupper((string) ($createResponse->json('status') ?? 'PENDING'));
                    $requestedAt = now();
                    $createdAtRaw = (string) ($createResponse->json('createdAt') ?? '');
                    if ($createdAtRaw !== '') {
                        try {
                            $requestedAt = Carbon::parse($createdAtRaw);
                        } catch (\Throwable) {
                            // Keep now() fallback.
                        }
                    }

                    $model = AmazonAdsReportRequest::firstOrNew(['report_id' => $reportId]);
                    $isNew = !$model->exists;
                    $model->fill([
                        'report_name' => $reportName,
                        'profile_id' => $profileId,
                        'country_code' => $countryCode,
                        'currency_code' => $currency,
                        'region' => $region,
                        'ad_product' => $config['adProduct'],
                        'report_type_id' => $config['reportTypeId'],
                        'start_date' => $chunkFrom->toDateString(),
                        'end_date' => $chunkTo->toDateString(),
                        'status' => $status,
                        'requested_at' => $model->requested_at ?? $requestedAt,
                        'next_check_at' => $model->next_check_at ?? now(),
                        'retry_count' => 0,
                        'last_http_status' => (string) $createResponse->status(),
                        'last_request_id' => $this->extractRequestId($createResponse),
                        'download_url' => (string) ($createResponse->json('url') ?? $model->download_url),
                    ]);
                    $model->save();

                    if ($isNew) {
                        $created++;
                    } else {
                        $existing++;
                    }
                }
            }
        }

        $outstanding = $this->outstandingSummary();

        return [
            'ok' => true,
            'message' => 'Amazon Ads report queueing complete.',
            'created' => $created,
            'existing' => $existing,
            'failed' => $failed,
            'outstanding' => $outstanding['count'],
            'oldest_wait_seconds' => $outstanding['oldest_wait_seconds'],
        ];
    }

    public function processPendingReports(int $limit = 100): array
    {
        $requests = AmazonAdsReportRequest::query()
            ->whereNull('processed_at')
            ->whereNotIn('status', ['FAILED', 'CANCELLED'])
            ->where(function ($q) {
                $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
            })
            ->orderBy('requested_at')
            ->limit(max(1, $limit))
            ->get();

        $checked = 0;
        $completed = 0;
        $processed = 0;
        $failed = 0;
        $affectedDates = [];
        $tokenByApiRegion = [];
        $adsConfigByApiRegion = [];

        foreach ($requests as $request) {
            $checked++;

            $this->maybeAlertStuckReport($request);

            $apiRegion = $this->resolveAdsApiRegionForRequest($request);
            if (!isset($adsConfigByApiRegion[$apiRegion])) {
                $adsConfigByApiRegion[$apiRegion] = $this->adsConfigForRegion($apiRegion);
            }
            $adsConfig = $adsConfigByApiRegion[$apiRegion] ?? null;
            if (!is_array($adsConfig)) {
                $request->retry_count = (int) $request->retry_count + 1;
                $request->processing_error = 'Missing Amazon Ads configuration for API region [' . $apiRegion . '].';
                $request->next_check_at = now()->addSeconds($this->withJitter(300, 0.2));
                if ((int) $request->retry_count >= self::MAX_BACKGROUND_RETRIES) {
                    $request->status = 'FAILED';
                    $request->failure_reason = $request->processing_error;
                    $request->processed_at = now();
                    $request->completed_at = now();
                    $request->next_check_at = null;
                    $failed++;
                }
                $request->save();
                continue;
            }

            if (!isset($tokenByApiRegion[$apiRegion])) {
                $tokenByApiRegion[$apiRegion] = $this->getAccessToken($adsConfig);
            }
            $token = $tokenByApiRegion[$apiRegion] ?? null;
            if (!is_string($token) || $token === '') {
                $request->retry_count = (int) $request->retry_count + 1;
                $request->processing_error = 'Unable to obtain Amazon Ads token for API region [' . $apiRegion . '].';
                $request->next_check_at = now()->addSeconds($this->withJitter(300, 0.2));
                if ((int) $request->retry_count >= self::MAX_BACKGROUND_RETRIES) {
                    $request->status = 'FAILED';
                    $request->failure_reason = $request->processing_error;
                    $request->processed_at = now();
                    $request->completed_at = now();
                    $request->next_check_at = null;
                    $failed++;
                }
                $request->save();
                continue;
            }

            $baseUrl = rtrim((string) ($adsConfig['base_url'] ?? ''), '/');
            $clientId = trim((string) ($adsConfig['client_id'] ?? ''));

            $statusResponse = Http::timeout(30)
                ->withToken($token)
                ->withHeaders([
                    'Amazon-Advertising-API-ClientId' => $clientId,
                    'Amazon-Advertising-API-Scope' => $request->profile_id,
                    'Accept' => 'application/vnd.asyncreportstatusresponse.v3+json',
                ])
                ->get($baseUrl . '/reporting/reports/' . $request->report_id);

            $request->last_checked_at = now();
            $request->check_attempts = (int) $request->check_attempts + 1;
            $request->last_http_status = (string) $statusResponse->status();
            $request->last_request_id = $this->extractRequestId($statusResponse);
            if ($request->requested_at !== null) {
                $request->waited_seconds = max(0, $request->requested_at->diffInSeconds(now()));
            }

            if (!$statusResponse->ok()) {
                if ($statusResponse->status() === 429) {
                    $this->logRetryHeaders($statusResponse, 'background-status', (string) $request->profile_id, (string) $request->ad_product, (int) $request->check_attempts);
                }
                $this->scheduleBackgroundRetry($request, $statusResponse, 5);
                $request->save();
                continue;
            }

            $status = strtoupper((string) ($statusResponse->json('status') ?? 'PENDING'));
            $request->status = $status;
            $request->download_url = (string) ($statusResponse->json('url') ?? $request->download_url);
            $request->next_check_at = null;
            $request->retry_count = 0;

            if (in_array($status, ['FAILED', 'CANCELLED'], true)) {
                $request->failure_reason = (string) ($statusResponse->json('failureReason') ?? $request->failure_reason);
                $request->completed_at = now();
                $request->processed_at = now();
                $request->next_check_at = null;
                $request->save();
                $failed++;
                continue;
            }

            if ($status !== 'COMPLETED' || empty($request->download_url)) {
                $request->next_check_at = now()->addSeconds($this->withJitter(30, 0.2));
                $request->save();
                continue;
            }

            $completed++;
            $rows = $this->downloadReportRows($request->download_url);
            if ($rows === null) {
                $request->processing_error = 'Unable to decode completed report payload.';
                $request->next_check_at = now()->addSeconds($this->withJitter(60, 0.2));
                $request->retry_count = (int) $request->retry_count + 1;
                if ((int) $request->retry_count >= self::MAX_BACKGROUND_RETRIES) {
                    $request->status = 'FAILED';
                    $request->failure_reason = 'Exceeded max payload decode retries.';
                    $request->processed_at = now();
                    $request->completed_at = now();
                    $request->next_check_at = null;
                }
                $request->save();
                continue;
            }

            [$processedRows, $dates] = $this->persistReportRows($request, $rows);
            $request->processed_rows = $processedRows;
            $request->completed_at = $request->completed_at ?? now();
            $request->processed_at = now();
            $request->processing_error = null;
            $request->next_check_at = null;
            $request->retry_count = 0;
            $request->save();

            if ($processedRows > 0) {
                $processed++;
                foreach ($dates as $d) {
                    $affectedDates[$d] = true;
                }
            }
        }

        $aggregateRows = 0;
        if (!empty($affectedDates)) {
            $aggregateRows = $this->refreshAggregatesForDates(array_keys($affectedDates));
        }

        $outstanding = $this->outstandingSummary();
        Log::info('Ads outstanding reports', $outstanding);

        return [
            'ok' => true,
            'message' => 'Amazon Ads pending reports processed.',
            'checked' => $checked,
            'completed' => $completed,
            'processed' => $processed,
            'failed' => $failed,
            'aggregated_rows' => $aggregateRows,
            'outstanding' => $outstanding['count'],
            'oldest_wait_seconds' => $outstanding['oldest_wait_seconds'],
        ];
    }

    public function testConnection(): array
    {
        [$profiles, $tokensByApiRegion, $adsConfigByApiRegion] = $this->loadProfilesByConfiguredAdsRegions();
        if (empty($tokensByApiRegion) || empty($adsConfigByApiRegion)) {
            return ['ok' => false, 'message' => 'Unable to obtain Amazon Ads access token.', 'profiles' => []];
        }

        return [
            'ok' => true,
            'message' => 'Amazon Ads connection successful.',
            'profiles' => $profiles,
        ];
    }

    private function getAccessToken(array $adsConfig): ?string
    {
        $clientId = trim((string) ($adsConfig['client_id'] ?? ''));
        $clientSecret = trim((string) ($adsConfig['client_secret'] ?? ''));
        $refreshToken = trim((string) ($adsConfig['refresh_token'] ?? ''));
        $apiRegion = strtoupper((string) ($adsConfig['region'] ?? ''));
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            Log::warning('Amazon Ads credentials are missing.', ['api_region' => $apiRegion]);
            return null;
        }

        $cacheKey = 'amazon_ads:access_token:' . strtolower($apiRegion) . ':' . sha1($clientId . '|' . $refreshToken);
        $cachedToken = Cache::get($cacheKey);
        if (is_string($cachedToken) && trim($cachedToken) !== '') {
            return $cachedToken;
        }

        $response = Http::asForm()->timeout(20)->post('https://api.amazon.com/auth/o2/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ]);

        if (!$response->ok()) {
            Log::warning('Amazon Ads token request failed', [
                'api_region' => $apiRegion,
                'status' => $response->status(),
                'request_id' => $this->extractRequestId($response),
                'body' => $response->body(),
            ]);
            return null;
        }

        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            return null;
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        $cacheSeconds = max(60, $expiresIn - 120);
        Cache::put($cacheKey, $token, now()->addSeconds($cacheSeconds));

        return $token;
    }

    private function getProfiles(string $token, array $adsConfig): array
    {
        $baseUrl = rtrim((string) ($adsConfig['base_url'] ?? ''), '/');
        $clientId = trim((string) ($adsConfig['client_id'] ?? ''));
        $apiRegion = strtoupper((string) ($adsConfig['region'] ?? ''));
        if ($baseUrl === '' || $clientId === '') {
            Log::warning('Amazon Ads base URL/client id missing for profiles request', ['api_region' => $apiRegion]);
            return [];
        }

        $response = Http::timeout(30)
            ->withToken($token)
            ->withHeaders([
                'Amazon-Advertising-API-ClientId' => $clientId,
            ])
            ->get($baseUrl . '/v2/profiles');

        if (!$response->ok()) {
            Log::warning('Amazon Ads profiles request failed', [
                'api_region' => $apiRegion,
                'status' => $response->status(),
                'request_id' => $this->extractRequestId($response),
                'body' => $response->body(),
            ]);
            return [];
        }

        $json = $response->json();
        return is_array($json) ? $json : [];
    }

    private function fetchSpendRowsForProfile(
        string $token,
        array $adsConfig,
        string $profileId,
        Carbon $from,
        Carbon $to,
        int $pollAttempts,
        ?array $adProducts = null
    ): array {
        $baseUrl = rtrim((string) ($adsConfig['base_url'] ?? ''), '/');
        $clientId = trim((string) ($adsConfig['client_id'] ?? ''));
        $configs = $this->reportConfigs($adProducts);

        $daily = [];

        foreach ($configs as $config) {
            $createResponse = null;
            $reportId = '';
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $createResponse = Http::timeout(30)
                    ->withToken($token)
                    ->withHeaders([
                        'Amazon-Advertising-API-ClientId' => $clientId,
                        'Amazon-Advertising-API-Scope' => $profileId,
                        'Content-Type' => 'application/vnd.createasyncreportrequest.v3+json',
                        'Accept' => 'application/vnd.createasyncreportresponse.v3+json',
                    ])
                    ->post($baseUrl . '/reporting/reports', [
                        'name' => strtolower($config['adProduct']) . '_daily_spend_' . $profileId . '_' . $from->format('Ymd') . '_' . $to->format('Ymd'),
                        'startDate' => $from->toDateString(),
                        'endDate' => $to->toDateString(),
                        'configuration' => [
                            'adProduct' => $config['adProduct'],
                            'groupBy' => ['campaign'],
                            'columns' => ['date', 'cost'],
                            'reportTypeId' => $config['reportTypeId'],
                            'timeUnit' => 'DAILY',
                            'format' => 'GZIP_JSON',
                        ],
                    ]);

                $statusCode = $createResponse->status();
                Log::info('Ads create attempt', [
                    'profile_id' => $profileId,
                    'ad_product' => $config['adProduct'],
                    'attempt' => $attempt,
                    'status' => $statusCode,
                    'request_id' => $this->extractRequestId($createResponse),
                    'start' => $from->toDateString(),
                    'end' => $to->toDateString(),
                ]);
                if ($statusCode === 425) {
                    $this->logRetryHeaders($createResponse, 'create', $profileId, $config['adProduct'], $attempt);
                    $reportId = $this->extractReportId($createResponse) ?? '';
                    if ($reportId !== '') {
                        Log::info('Ads duplicate report id parsed', [
                            'profile_id' => $profileId,
                            'ad_product' => $config['adProduct'],
                            'report_id' => $reportId,
                        ]);
                        break;
                    }
                    sleep($this->withJitter($this->resolveRetryDelaySeconds($createResponse, 4 + $attempt), 0.25));
                    continue;
                }

                if ($statusCode === 429) {
                    $this->logRetryHeaders($createResponse, 'create', $profileId, $config['adProduct'], $attempt);
                    sleep($this->withJitter($this->resolveRetryDelaySeconds($createResponse, 5 + ($attempt * 2)), 0.25));
                    continue;
                }

                break;
            }

            if ($createResponse === null) {
                continue;
            }

            if (!$createResponse->ok()) {
                if ($reportId === '') {
                    Log::info('Amazon Ads report create skipped', [
                        'profile_id' => $profileId,
                        'ad_product' => $config['adProduct'],
                        'status' => $createResponse->status(),
                        'request_id' => $this->extractRequestId($createResponse),
                        'body' => $createResponse->body(),
                        'start' => $from->toDateString(),
                        'end' => $to->toDateString(),
                    ]);
                    continue;
                }
            }

            if ($reportId === '') {
                $reportId = (string) ($createResponse->json('reportId') ?? '');
            }
            if ($reportId === '') {
                Log::info('Ads missing report id', [
                    'profile_id' => $profileId,
                    'ad_product' => $config['adProduct'],
                    'body' => $createResponse->body(),
                ]);
                continue;
            }
            Log::info('Ads polling report', [
                'profile_id' => $profileId,
                'ad_product' => $config['adProduct'],
                'report_id' => $reportId,
            ]);

            $downloadUrl = null;
            for ($i = 0; $i < max(1, $pollAttempts); $i++) {
                sleep(3);
                $statusResponse = Http::timeout(30)
                    ->withToken($token)
                    ->withHeaders([
                        'Amazon-Advertising-API-ClientId' => $clientId,
                        'Amazon-Advertising-API-Scope' => $profileId,
                        'Accept' => 'application/vnd.asyncreportstatusresponse.v3+json',
                    ])
                    ->get($baseUrl . '/reporting/reports/' . $reportId);

                if (!$statusResponse->ok()) {
                    if ($statusResponse->status() === 429) {
                        $this->logRetryHeaders($statusResponse, 'status', $profileId, $config['adProduct'], $i);
                        sleep($this->withJitter($this->resolveRetryDelaySeconds($statusResponse, 3), 0.25));
                    }
                    continue;
                }

                $status = strtoupper((string) ($statusResponse->json('status') ?? ''));
                if ($status === 'COMPLETED') {
                    $downloadUrl = (string) ($statusResponse->json('url') ?? '');
                    Log::info('Ads report completed', [
                        'profile_id' => $profileId,
                        'ad_product' => $config['adProduct'],
                        'report_id' => $reportId,
                        'download_url_present' => $downloadUrl !== '',
                    ]);
                    break;
                }
                if (in_array($status, ['FAILED', 'CANCELLED'], true)) {
                    break;
                }
            }

            if (empty($downloadUrl)) {
                Log::info('Ads report polling timed out', [
                    'profile_id' => $profileId,
                    'ad_product' => $config['adProduct'],
                    'report_id' => $reportId,
                    'attempts' => max(1, $pollAttempts),
                ]);
                continue;
            }

            $data = $this->downloadReportRows($downloadUrl);
            if (!is_array($data)) {
                Log::info('Ads report payload not array', [
                    'profile_id' => $profileId,
                    'ad_product' => $config['adProduct'],
                    'report_id' => $reportId,
                ]);
                continue;
            }
            Log::info('Ads report rows parsed', [
                'profile_id' => $profileId,
                'ad_product' => $config['adProduct'],
                'report_id' => $reportId,
                'row_count' => count($data),
            ]);

            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $date = (string) ($row['date'] ?? '');
                $cost = (float) ($row['cost'] ?? 0);
                if ($date === '' || $cost <= 0) {
                    continue;
                }
                $daily[$date] = ($daily[$date] ?? 0) + $cost;
            }
        }

        $rows = [];
        foreach ($daily as $date => $spend) {
            $rows[] = ['date' => $date, 'spend' => $spend];
        }
        return $rows;
    }

    private function reportConfigs(?array $adProducts = null): array
    {
        $allConfigs = [
            ['adProduct' => 'SPONSORED_PRODUCTS', 'reportTypeId' => 'spCampaigns'],
            ['adProduct' => 'SPONSORED_BRANDS', 'reportTypeId' => 'sbCampaigns'],
            ['adProduct' => 'SPONSORED_DISPLAY', 'reportTypeId' => 'sdCampaigns'],
        ];

        if (empty($adProducts)) {
            return $allConfigs;
        }

        $allowed = array_fill_keys(array_map('strtoupper', $adProducts), true);
        return array_values(array_filter($allConfigs, fn ($c) => isset($allowed[$c['adProduct']])));
    }

    private function extractReportId(Response $response): ?string
    {
        $reportId = trim((string) ($response->json('reportId') ?? ''));
        if ($reportId !== '') {
            return $reportId;
        }

        $detail = (string) ($response->json('detail') ?? '');
        if (preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $detail, $m)) {
            return $m[1];
        }

        return null;
    }

    private function downloadReportRows(string $downloadUrl): ?array
    {
        $download = Http::timeout(120)->get($downloadUrl);
        if (!$download->ok()) {
            return null;
        }

        $raw = (string) $download->body();
        $decoded = @gzdecode($raw);
        if ($decoded === false) {
            $decoded = $raw;
        }

        $data = json_decode($decoded, true);
        return is_array($data) ? $data : null;
    }

    private function persistReportRows(AmazonAdsReportRequest $request, array $rows): array
    {
        $region = (string) $request->region;
        $currencyCode = strtoupper((string) $request->currency_code);
        $targetCurrency = $this->targetCurrencyForRegion($region);
        if ($region === '' || $currencyCode === '') {
            return [0, []];
        }

        $dailySource = [];
        $dailyTarget = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = (string) ($row['date'] ?? '');
            $cost = (float) ($row['cost'] ?? 0);
            if ($date === '' || $cost <= 0) {
                continue;
            }

            $converted = $this->fxRateService->convert($cost, $currencyCode, $targetCurrency, $date);
            if ($converted === null) {
                continue;
            }

            $dailySource[$date] = ($dailySource[$date] ?? 0) + $cost;
            $dailyTarget[$date] = ($dailyTarget[$date] ?? 0) + $converted;
        }

        if (empty($dailyTarget)) {
            return [0, []];
        }

        $upsertRows = [];
        foreach ($dailyTarget as $date => $amount) {
            $upsertRows[] = [
                'report_request_id' => $request->id,
                'report_id' => $request->report_id,
                'profile_id' => $request->profile_id,
                'metric_date' => $date,
                'region' => $region,
                'currency' => $targetCurrency,
                'source_currency' => $currencyCode,
                'source_amount' => round((float) ($dailySource[$date] ?? 0), 2),
                'amount_local' => round($amount, 2),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        AmazonAdsReportDailySpend::upsert(
            $upsertRows,
            ['report_id', 'metric_date', 'region', 'currency'],
            ['source_currency', 'source_amount', 'amount_local', 'updated_at']
        );

        return [count($upsertRows), array_keys($dailyTarget)];
    }

    private function refreshAggregatesForDates(array $dates): int
    {
        $latestAdsRequests = DB::table('amazon_ads_report_daily_spends as ds_latest')
            ->join('amazon_ads_report_requests as rr_latest', 'rr_latest.id', '=', 'ds_latest.report_request_id')
            ->selectRaw("
                ds_latest.metric_date as metric_date,
                ds_latest.profile_id as profile_id,
                rr_latest.ad_product as ad_product,
                MAX(rr_latest.id) as latest_request_id
            ")
            ->whereIn('ds_latest.metric_date', $dates)
            ->groupBy('ds_latest.metric_date', 'ds_latest.profile_id', 'rr_latest.ad_product');

        $rows = DB::table('amazon_ads_report_daily_spends as ds')
            ->join('amazon_ads_report_requests as rr', 'rr.id', '=', 'ds.report_request_id')
            ->joinSub($latestAdsRequests, 'latest_ads', function ($join) {
                $join->on('latest_ads.metric_date', '=', 'ds.metric_date')
                    ->on('latest_ads.profile_id', '=', 'ds.profile_id')
                    ->on('latest_ads.ad_product', '=', 'rr.ad_product')
                    ->on('latest_ads.latest_request_id', '=', 'rr.id');
            })
            ->selectRaw('ds.metric_date as metric_date, ds.region as region, ds.currency as currency, SUM(ds.amount_local) as amount_local')
            ->whereIn('ds.metric_date', $dates)
            ->groupBy('ds.metric_date', 'ds.region', 'ds.currency')
            ->get();

        $upsertRows = [];
        foreach ($rows as $row) {
            $upsertRows[] = [
                'metric_date' => (string) $row->metric_date,
                'region' => (string) $row->region,
                'currency' => (string) $row->currency,
                'amount_local' => round((float) $row->amount_local, 2),
                'source' => self::SOURCE,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($upsertRows)) {
            DailyRegionAdSpend::upsert(
                $upsertRows,
                ['metric_date', 'region', 'currency', 'source'],
                ['amount_local', 'updated_at']
            );
        }

        return count($upsertRows);
    }

    private function outstandingSummary(): array
    {
        $query = AmazonAdsReportRequest::query()
            ->whereNull('processed_at')
            ->whereNotIn('status', ['FAILED', 'CANCELLED']);

        $count = (clone $query)->count();
        $oldest = (clone $query)->orderBy('requested_at')->value('requested_at');
        $oldestWait = 0;
        if ($oldest !== null) {
            $oldestWait = Carbon::parse((string) $oldest)->diffInSeconds(now());
        }

        return [
            'count' => $count,
            'oldest_wait_seconds' => $oldestWait,
        ];
    }

    private function toRegion(string $countryCode): ?string
    {
        if ($countryCode === 'GB' || $countryCode === 'UK') {
            return 'UK';
        }

        if (in_array($countryCode, self::NA_COUNTRY_CODES, true)) {
            return 'NA';
        }

        return in_array($countryCode, self::EU_COUNTRY_CODES, true) ? 'EU' : null;
    }

    private function targetCurrencyForRegion(string $region): string
    {
        return match (strtoupper(trim($region))) {
            'UK' => 'GBP',
            'NA' => 'USD',
            default => 'EUR',
        };
    }

    private function loadProfilesByConfiguredAdsRegions(): array
    {
        $profiles = [];
        $tokenByApiRegion = [];
        $adsConfigByApiRegion = [];
        foreach ($this->regionConfigService->adsRegions() as $adsApiRegion) {
            $adsConfig = $this->adsConfigForRegion((string) $adsApiRegion);
            if (!is_array($adsConfig)) {
                continue;
            }

            $token = $this->getAccessToken($adsConfig);
            if ($token === null) {
                continue;
            }

            $apiProfiles = $this->getProfiles($token, $adsConfig);
            foreach ($apiProfiles as $apiProfile) {
                if (!is_array($apiProfile)) {
                    continue;
                }
                $apiProfile['_ads_api_region'] = $adsApiRegion;
                $profiles[] = $apiProfile;
            }

            $tokenByApiRegion[$adsApiRegion] = $token;
            $adsConfigByApiRegion[$adsApiRegion] = $adsConfig;
        }

        return [$profiles, $tokenByApiRegion, $adsConfigByApiRegion];
    }

    private function adsConfigForRegion(string $region): ?array
    {
        $normalizedRegion = strtoupper(trim($region));
        if ($normalizedRegion === 'UK') {
            $normalizedRegion = 'EU';
        }

        $config = $this->regionConfigService->adsConfig($normalizedRegion);
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $refreshToken = trim((string) ($config['refresh_token'] ?? ''));
        $baseUrl = trim((string) ($config['base_url'] ?? ''));
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '' || $baseUrl === '') {
            return null;
        }

        $config['region'] = $normalizedRegion;

        return $config;
    }

    private function resolveAdsApiRegionForRequest(AmazonAdsReportRequest $request): string
    {
        $countryCode = strtoupper(trim((string) $request->country_code));
        if ($countryCode !== '') {
            $businessRegion = $this->toRegion($countryCode);
            if ($businessRegion !== null) {
                return $businessRegion === 'UK' ? 'EU' : $businessRegion;
            }
        }

        $requestRegion = strtoupper(trim((string) $request->region));
        if ($requestRegion === 'UK') {
            return 'EU';
        }

        return in_array($requestRegion, ['EU', 'NA', 'FE'], true) ? $requestRegion : $this->regionConfigService->defaultAdsRegion();
    }

    private function splitRange(Carbon $from, Carbon $to, int $maxDaysPerChunk): array
    {
        $chunks = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $chunkFrom = $cursor->copy();
            $chunkTo = $cursor->copy()->addDays($maxDaysPerChunk - 1);
            if ($chunkTo->gt($end)) {
                $chunkTo = $end->copy();
            }
            $chunks[] = [$chunkFrom, $chunkTo];
            $cursor = $chunkTo->copy()->addDay();
        }

        return $chunks;
    }

    private function fetchLegacySponsoredProductsSpend(string $token, array $adsConfig, string $profileId, Carbon $from, Carbon $to): array
    {
        $baseUrl = rtrim((string) ($adsConfig['base_url'] ?? ''), '/');
        $clientId = trim((string) ($adsConfig['client_id'] ?? ''));
        $date = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $daily = [];

        while ($date->lte($end)) {
            $dateYmd = $date->format('Ymd');

            $create = Http::timeout(30)
                ->withToken($token)
                ->withHeaders([
                    'Amazon-Advertising-API-ClientId' => $clientId,
                    'Amazon-Advertising-API-Scope' => $profileId,
                    'Content-Type' => 'application/json',
                ])
                ->post($baseUrl . '/v2/sp/campaigns/report', [
                    'reportDate' => $dateYmd,
                    'metrics' => 'cost',
                ]);

            if (!$create->ok()) {
                $date->addDay();
                continue;
            }

            $reportId = (string) ($create->json('reportId') ?? '');
            if ($reportId === '') {
                $date->addDay();
                continue;
            }

            $location = null;
            for ($i = 0; $i < 10; $i++) {
                sleep(2);
                $status = Http::timeout(30)
                    ->withToken($token)
                    ->withHeaders([
                        'Amazon-Advertising-API-ClientId' => $clientId,
                        'Amazon-Advertising-API-Scope' => $profileId,
                    ])
                    ->get($baseUrl . '/v2/reports/' . $reportId);

                if (!$status->ok()) {
                    continue;
                }

                $statusText = strtoupper((string) ($status->json('status') ?? ''));
                if ($statusText === 'SUCCESS') {
                    $location = (string) ($status->json('location') ?? '');
                    break;
                }
                if (in_array($statusText, ['FAILURE', 'FAILED'], true)) {
                    break;
                }
            }

            if (!empty($location)) {
                $rows = $this->downloadReportRows($location);
                if (is_array($rows)) {
                    $sum = 0.0;
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $sum += (float) ($row['cost'] ?? 0);
                    }
                    if ($sum > 0) {
                        $daily[] = ['date' => $date->toDateString(), 'spend' => $sum];
                    }
                }
            }

            $date->addDay();
        }

        return $daily;
    }

    private function scheduleBackgroundRetry(AmazonAdsReportRequest $request, Response $response, int $fallbackSeconds): void
    {
        $request->retry_count = (int) $request->retry_count + 1;
        $delay = $this->withJitter($this->resolveRetryDelaySeconds($response, $fallbackSeconds), 0.25);
        $request->next_check_at = now()->addSeconds($delay);

        if ((int) $request->retry_count >= self::MAX_BACKGROUND_RETRIES) {
            $request->status = 'FAILED';
            $request->failure_reason = 'Exceeded max background retries.';
            $request->processed_at = now();
            $request->completed_at = now();
            Log::warning('Ads report marked failed due retry cap', [
                'report_id' => $request->report_id,
                'profile_id' => $request->profile_id,
                'retry_count' => $request->retry_count,
                'last_status' => $response->status(),
                'request_id' => $this->extractRequestId($response),
            ]);
        }
    }

    private function maybeAlertStuckReport(AmazonAdsReportRequest $request): void
    {
        if ($request->requested_at === null || $request->processed_at !== null) {
            return;
        }

        if ($request->stuck_alerted_at !== null) {
            return;
        }

        $wait = $request->requested_at->diffInSeconds(now());
        if ($wait < self::STUCK_REPORT_SECONDS) {
            return;
        }

        $request->stuck_alerted_at = now();
        Log::warning('Ads report appears stuck', [
            'report_id' => $request->report_id,
            'profile_id' => $request->profile_id,
            'status' => $request->status,
            'wait_seconds' => $wait,
            'requested_at' => optional($request->requested_at)->toIso8601String(),
        ]);
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

    private function extractRequestId(Response $response): ?string
    {
        $value = (string) ($response->header('x-amzn-requestid')
            ?? $response->header('x-amzn-request-id')
            ?? $response->header('x-amz-request-id')
            ?? $response->header('x-request-id')
            ?? '');
        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function resolveRetryDelaySeconds(Response $response, int $fallbackSeconds): int
    {
        $status = $response->status();
        $retryAfter = trim((string) $response->header('Retry-After', ''));
        if ($retryAfter !== '') {
            if (ctype_digit($retryAfter)) {
                $delay = max(1, (int) $retryAfter);
                Log::info('Ads retry delay resolved', [
                    'status' => $status,
                    'source' => 'retry-after-seconds',
                    'retry_after' => $retryAfter,
                    'delay_seconds' => $delay,
                ]);
                return $delay;
            }

            try {
                $when = Carbon::parse($retryAfter);
                $seconds = now()->diffInSeconds($when, false);
                if ($seconds > 0) {
                    $delay = (int) ceil($seconds);
                    Log::info('Ads retry delay resolved', [
                        'status' => $status,
                        'source' => 'retry-after-date',
                        'retry_after' => $retryAfter,
                        'delay_seconds' => $delay,
                    ]);
                    return $delay;
                }
            } catch (\Throwable) {
                // Ignore malformed dates and fall back.
            }
        }

        $resetHeader = trim((string) ($response->header('x-amzn-ratelimit-reset')
            ?? $response->header('x-amz-ratelimit-reset')
            ?? $response->header('x-ratelimit-reset')
            ?? ''));
        if ($resetHeader !== '' && is_numeric($resetHeader)) {
            $reset = (float) $resetHeader;
            if ($reset > 0 && $reset <= 3600) {
                $delay = max(1, (int) ceil($reset));
                Log::info('Ads retry delay resolved', [
                    'status' => $status,
                    'source' => 'ratelimit-reset-seconds',
                    'ratelimit_reset' => $resetHeader,
                    'delay_seconds' => $delay,
                ]);
                return $delay;
            }

            $eta = (int) ceil($reset - microtime(true));
            if ($eta > 0) {
                Log::info('Ads retry delay resolved', [
                    'status' => $status,
                    'source' => 'ratelimit-reset-epoch',
                    'ratelimit_reset' => $resetHeader,
                    'delay_seconds' => $eta,
                ]);
                return $eta;
            }
        }

        $delay = max(1, $fallbackSeconds);
        Log::info('Ads retry delay resolved', [
            'status' => $status,
            'source' => 'fallback',
            'delay_seconds' => $delay,
        ]);
        return $delay;
    }

    private function logRetryHeaders(
        Response $response,
        string $phase,
        string $profileId,
        string $adProduct,
        int $attempt
    ): void {
        Log::info('Ads retry headers', [
            'phase' => $phase,
            'profile_id' => $profileId,
            'ad_product' => $adProduct,
            'attempt' => $attempt,
            'status' => $response->status(),
            'request_id' => $this->extractRequestId($response),
            'retry_after' => $response->header('Retry-After'),
            'x_amzn_ratelimit_limit' => $response->header('x-amzn-ratelimit-limit'),
            'x_amzn_ratelimit_remaining' => $response->header('x-amzn-ratelimit-remaining'),
            'x_amzn_ratelimit_reset' => $response->header('x-amzn-ratelimit-reset'),
            'x_amz_ratelimit_reset' => $response->header('x-amz-ratelimit-reset'),
            'x_ratelimit_reset' => $response->header('x-ratelimit-reset'),
        ]);
    }

    private function findOutstandingReportRequest(
        string $profileId,
        string $adProduct,
        string $reportTypeId,
        string $startDate,
        string $endDate
    ): ?AmazonAdsReportRequest {
        return AmazonAdsReportRequest::query()
            ->where('profile_id', $profileId)
            ->where('ad_product', $adProduct)
            ->where('report_type_id', $reportTypeId)
            ->whereDate('start_date', $startDate)
            ->whereDate('end_date', $endDate)
            ->whereNull('processed_at')
            ->whereNotIn('status', ['FAILED', 'CANCELLED'])
            ->orderByDesc('id')
            ->first();
    }
}
