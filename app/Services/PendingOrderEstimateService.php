<?php

namespace App\Services;

use App\Services\Amazon\OfficialSpApiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SpApi\Model\pricing\v2022_05_01\CompetitiveSummaryBatchRequest;
use SpApi\Model\pricing\v2022_05_01\CompetitiveSummaryIncludedData;
use SpApi\Model\pricing\v2022_05_01\CompetitiveSummaryRequest;
use SpApi\Model\pricing\v2022_05_01\HttpMethod;

class PendingOrderEstimateService
{
    private const VALID_STATUSES = ['PENDING', 'UNSHIPPED'];

    public function refresh(
        int $days = 14,
        int $limit = 300,
        int $maxLookups = 80,
        int $staleMinutes = 180,
        ?string $region = null
    ): array {
        $days = max(1, min($days, 60));
        $limit = max(1, min($limit, 2000));
        $maxLookups = max(1, min($maxLookups, 500));
        $staleMinutes = max(1, min($staleMinutes, 1440));

        $stats = [
            'considered' => 0,
            'updated' => 0,
            'lookup_keys' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'api_calls' => 0,
            'api_success' => 0,
            'api_non_200' => 0,
            'throttle_retries' => 0,
            'payload_missing' => 0,
            'exceptions' => 0,
            'skipped_no_price' => 0,
        ];

        $rows = $this->candidateItems($days, $limit, $staleMinutes, $region);
        if ($rows->isEmpty()) {
            return $stats;
        }

        $stats['considered'] = $rows->count();

        $lookups = [];
        $priceLookup = [];
        $regionService = new RegionConfigService();
        $pricingApisByRegion = [];
        $officialSpApiService = new OfficialSpApiService($regionService);

        foreach ($rows as $row) {
            $marketplaceId = trim((string) ($row->marketplace_id ?? ''));
            $asin = strtoupper(trim((string) ($row->asin ?? '')));
            $isBusinessOrder = ((int) ($row->is_business_order ?? 0)) === 1;
            $countryCode = strtoupper(trim((string) ($row->country_code ?? '')));
            if ($marketplaceId === '' || $asin === '') {
                continue;
            }

            $resolvedRegion = $region ? strtoupper(trim($region)) : $this->regionForCountry($countryCode);
            if (!in_array($resolvedRegion, ['EU', 'NA', 'FE'], true)) {
                continue;
            }

            $key = $resolvedRegion . '|' . $marketplaceId . '|' . ($isBusinessOrder ? '1' : '0') . '|' . $asin;
            $lookups[$key] = [
                'region' => $resolvedRegion,
                'marketplace_id' => $marketplaceId,
                'is_business_order' => $isBusinessOrder,
                'asin' => $asin,
            ];
        }

        if (empty($lookups)) {
            return $stats;
        }

        $lookups = array_slice($lookups, 0, $maxLookups, true);
        $stats['lookup_keys'] = count($lookups);

        foreach ($lookups as $lookupKey => $lookup) {
            $regionCode = $lookup['region'];
            if (!isset($pricingApisByRegion[$regionCode])) {
                $spConfig = $regionService->spApiConfig($regionCode);
                if (
                    trim((string) ($spConfig['client_id'] ?? '')) === ''
                    || trim((string) ($spConfig['client_secret'] ?? '')) === ''
                    || trim((string) ($spConfig['refresh_token'] ?? '')) === ''
                ) {
                    continue;
                }

                $api = $officialSpApiService->makePricingV20220501Api($regionCode);
                if ($api === null) {
                    continue;
                }
                $pricingApisByRegion[$regionCode] = $api;
            }

            $cacheKey = 'pending_est_price:' . sha1($lookupKey);
            $price = Cache::get($cacheKey);
            if (is_array($price)) {
                $stats['cache_hits']++;
            } else {
                $stats['cache_misses']++;
                $price = $this->fetchSinglePendingApiPrice(
                    $pricingApisByRegion[$regionCode],
                    (string) $lookup['marketplace_id'],
                    (string) $lookup['asin'],
                    (bool) $lookup['is_business_order'],
                    $regionCode,
                    $stats
                );
                if (is_array($price)) {
                    Cache::put($cacheKey, $price, now()->addMinutes(30));
                }
            }

            if (is_array($price)) {
                $priceLookup[$lookupKey] = $price;
            }
        }

        $now = now();
        foreach ($rows as $row) {
            $marketplaceId = trim((string) ($row->marketplace_id ?? ''));
            $asin = strtoupper(trim((string) ($row->asin ?? '')));
            $isBusinessOrder = ((int) ($row->is_business_order ?? 0)) === 1;
            $countryCode = strtoupper(trim((string) ($row->country_code ?? '')));
            $qty = max(0, (int) ($row->quantity_ordered ?? 0));
            if ($marketplaceId === '' || $asin === '' || $qty <= 0) {
                continue;
            }

            $resolvedRegion = $region ? strtoupper(trim($region)) : $this->regionForCountry($countryCode);
            if (!in_array($resolvedRegion, ['EU', 'NA', 'FE'], true)) {
                continue;
            }

            $lookupKey = $resolvedRegion . '|' . $marketplaceId . '|' . ($isBusinessOrder ? '1' : '0') . '|' . $asin;
            $price = $priceLookup[$lookupKey] ?? null;
            if (!is_array($price)) {
                $stats['skipped_no_price']++;
                continue;
            }

            $amount = (float) ($price['amount'] ?? 0);
            $currency = strtoupper(trim((string) ($price['currency'] ?? '')));
            if ($amount <= 0 || $currency === '') {
                $stats['skipped_no_price']++;
                continue;
            }

            $lineAmount = round($this->estimateUnitNetExTax($amount, $countryCode) * $qty, 2);
            if ($lineAmount <= 0) {
                $stats['skipped_no_price']++;
                continue;
            }

            DB::table('order_items')
                ->where('id', (int) $row->id)
                ->update([
                    'estimated_line_net_ex_tax' => $lineAmount,
                    'estimated_line_currency' => $currency,
                    'estimated_line_source' => 'spapi_item_offers',
                    'estimated_line_estimated_at' => $now,
                    'updated_at' => $now,
                ]);

            $stats['updated']++;
        }

        return $stats;
    }

    private function candidateItems(int $days, int $limit, int $staleMinutes, ?string $region): Collection
    {
        $metricDateExpr = "COALESCE(orders.purchase_date_local_date, DATE(orders.purchase_date))";
        $fromDate = Carbon::now()->subDays($days)->toDateString();
        $staleCutoff = Carbon::now()->subMinutes($staleMinutes)->toDateTimeString();
        $region = $region ? strtoupper(trim($region)) : null;

        $query = DB::table('order_items')
            ->join('orders', 'orders.amazon_order_id', '=', 'order_items.amazon_order_id')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->select([
                'order_items.id',
                'order_items.amazon_order_id',
                'order_items.asin',
                'order_items.quantity_ordered',
                'orders.marketplace_id',
                'orders.is_business_order',
                'marketplaces.country_code',
            ])
            ->whereRaw("{$metricDateExpr} >= ?", [$fromDate])
            ->whereIn(DB::raw("UPPER(COALESCE(orders.order_status, ''))"), self::VALID_STATUSES)
            ->whereRaw("COALESCE(order_items.line_net_ex_tax, 0) <= 0")
            ->whereRaw("TRIM(COALESCE(order_items.asin, '')) <> ''")
            ->whereRaw("TRIM(COALESCE(orders.marketplace_id, '')) <> ''")
            ->whereRaw("COALESCE(order_items.quantity_ordered, 0) > 0")
            ->where(function ($q) use ($staleCutoff) {
                $q->whereNull('order_items.estimated_line_estimated_at')
                    ->orWhere('order_items.estimated_line_estimated_at', '<=', $staleCutoff);
            })
            ->orderByDesc('orders.purchase_date')
            ->limit($limit);

        if ($region !== null) {
            if ($region === 'NA') {
                $query->whereIn(DB::raw("UPPER(COALESCE(marketplaces.country_code, ''))"), ['US', 'CA', 'MX', 'BR']);
            } elseif ($region === 'EU') {
                $query->whereIn(DB::raw("UPPER(COALESCE(marketplaces.country_code, ''))"), ['GB', 'UK', 'AT', 'BE', 'DE', 'DK', 'ES', 'FI', 'FR', 'IE', 'IT', 'LU', 'NL', 'NO', 'PL', 'SE', 'CH', 'TR']);
            } elseif ($region === 'FE') {
                $query->whereIn(DB::raw("UPPER(COALESCE(marketplaces.country_code, ''))"), ['JP', 'AU', 'SG', 'AE', 'IN', 'SA']);
            }
        }

        return $query->get();
    }

    private function fetchSinglePendingApiPrice(
        object $pricingApi,
        string $marketplaceId,
        string $asin,
        bool $isBusinessOrder,
        string $region,
        array &$stats
    ): ?array {
        $maxAttempts = 4;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $stats['api_calls']++;
                $request = new CompetitiveSummaryRequest([
                    'asin' => $asin,
                    'marketplace_id' => $marketplaceId,
                    'included_data' => [
                        CompetitiveSummaryIncludedData::FEATURED_BUYING_OPTIONS,
                        CompetitiveSummaryIncludedData::LOWEST_PRICED_OFFERS,
                    ],
                    'method' => HttpMethod::GET,
                    'uri' => '/products/pricing/2022-05-01/items/' . rawurlencode($asin) . '/competitiveSummary',
                ]);
                $batchRequest = new CompetitiveSummaryBatchRequest([
                    'requests' => [$request],
                ]);
                [$response, $status, $headers] = $pricingApi->getCompetitiveSummaryWithHttpInfo($batchRequest);

                if ($status === 429 && $attempt < $maxAttempts) {
                    $stats['throttle_retries']++;
                    sleep($this->resolveRetryDelaySeconds($headers, $attempt));
                    continue;
                }

                if ($status >= 400) {
                    $stats['api_non_200']++;
                    Log::warning('Pending estimate fetch non-200', [
                        'asin' => $asin,
                        'marketplace_id' => $marketplaceId,
                        'region' => $region,
                        'is_business_order' => $isBusinessOrder,
                        'status' => $status,
                        'attempt' => $attempt,
                    ]);
                    return null;
                }

                $responseBody = $this->modelToArray($response);
                $batchStatus = (int) data_get($responseBody, 'responses.0.status.statusCode', 200);
                if ($batchStatus >= 400) {
                    $stats['api_non_200']++;
                    Log::warning('Pending estimate competitive summary non-200', [
                        'asin' => $asin,
                        'marketplace_id' => $marketplaceId,
                        'region' => $region,
                        'is_business_order' => $isBusinessOrder,
                        'http_status' => $status,
                        'batch_status' => $batchStatus,
                        'attempt' => $attempt,
                    ]);
                    return null;
                }

                $price = $this->extractPriceFromCompetitiveSummaryPayload($responseBody);
                if ($price !== null) {
                    $stats['api_success']++;
                    return $price;
                }

                $stats['payload_missing']++;
                return null;
            } catch (\Throwable $e) {
                if ((int) $e->getCode() === 429 && $attempt < $maxAttempts) {
                    $stats['throttle_retries']++;
                    sleep(min(10, 1 + ($attempt * 2)));
                    continue;
                }

                $stats['exceptions']++;
                Log::warning('Pending estimate fetch failed', [
                    'asin' => $asin,
                    'marketplace_id' => $marketplaceId,
                    'region' => $region,
                    'is_business_order' => $isBusinessOrder,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        return null;
    }

    private function extractPriceFromCompetitiveSummaryPayload(array $json): ?array
    {
        $candidates = [
            data_get($json, 'responses.0.body.featuredBuyingOptions.0.segmentedFeaturedOffers.0.listingPrice'),
            data_get($json, 'responses.0.body.lowestPricedOffers.0.offers.0.listingPrice'),
            data_get($json, 'responses.0.body.lowestPricedOffers.0.offers.1.listingPrice'),
            data_get($json, 'responses.0.body.lowestPricedOffers.1.offers.0.listingPrice'),
        ];

        foreach ($candidates as $money) {
            if (!is_array($money)) {
                continue;
            }

            $amount = (float) ($money['amount'] ?? 0);
            $currency = strtoupper(trim((string) ($money['currencyCode'] ?? '')));
            if ($amount > 0 && $currency !== '') {
                return ['amount' => $amount, 'currency' => $currency];
            }
        }

        return null;
    }

    private function resolveRetryDelaySeconds($response, int $attempt): int
    {
        $retryAfter = $response['Retry-After'][0] ?? $response['retry-after'][0] ?? null;
        if (is_numeric($retryAfter)) {
            return max(1, min(30, (int) $retryAfter));
        }

        return min(10, 1 + ($attempt * 2));
    }

    private function modelToArray(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $json = json_encode($value);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function regionForCountry(string $countryCode): ?string
    {
        if (in_array($countryCode, ['US', 'CA', 'MX', 'BR'], true)) {
            return 'NA';
        }

        if (in_array($countryCode, ['GB', 'UK', 'AT', 'BE', 'DE', 'DK', 'ES', 'FI', 'FR', 'IE', 'IT', 'LU', 'NL', 'NO', 'PL', 'SE', 'CH', 'TR'], true)) {
            return 'EU';
        }

        if (in_array($countryCode, ['JP', 'AU', 'SG', 'AE', 'IN', 'SA'], true)) {
            return 'FE';
        }

        return null;
    }

    private function estimateUnitNetExTax(float $unitAmount, string $countryCode): float
    {
        $countryCode = strtoupper(trim($countryCode));
        $vatRate = $this->vatRateForCountry($countryCode);
        if ($vatRate <= 0) {
            return max(0.0, $unitAmount);
        }

        return max(0.0, round($unitAmount / (1 + $vatRate), 6));
    }

    private function vatRateForCountry(string $countryCode): float
    {
        return match ($countryCode) {
            'GB', 'UK' => 0.20,
            'DE' => 0.19,
            'FR' => 0.20,
            'IT' => 0.22,
            'ES' => 0.21,
            'NL' => 0.21,
            'BE' => 0.21,
            'SE' => 0.25,
            'PL' => 0.23,
            'IE' => 0.23,
            'AT' => 0.20,
            'DK' => 0.25,
            'FI' => 0.25,
            'NO' => 0.25,
            'LU' => 0.17,
            'CH' => 0.081,
            default => 0.0,
        };
    }
}
