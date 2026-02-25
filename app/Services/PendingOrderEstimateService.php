<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SellingPartnerApi\SellingPartnerApi;

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
        $connectors = [];

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
            if (!isset($connectors[$regionCode])) {
                $spConfig = $regionService->spApiConfig($regionCode);
                if (
                    trim((string) ($spConfig['client_id'] ?? '')) === ''
                    || trim((string) ($spConfig['client_secret'] ?? '')) === ''
                    || trim((string) ($spConfig['refresh_token'] ?? '')) === ''
                ) {
                    continue;
                }

                $connectors[$regionCode] = SellingPartnerApi::seller(
                    clientId: (string) $spConfig['client_id'],
                    clientSecret: (string) $spConfig['client_secret'],
                    refreshToken: (string) $spConfig['refresh_token'],
                    endpoint: $regionService->spApiEndpointEnum($regionCode)
                );
            }

            $cacheKey = 'pending_est_price:' . sha1($lookupKey);
            $price = Cache::get($cacheKey);
            if (is_array($price)) {
                $stats['cache_hits']++;
            } else {
                $stats['cache_misses']++;
                $price = $this->fetchSinglePendingApiPrice(
                    $connectors[$regionCode],
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
        SellingPartnerApi $connector,
        string $marketplaceId,
        string $asin,
        bool $isBusinessOrder,
        string $region,
        array &$stats
    ): ?array {
        $pricingApi = $connector->productPricingV0();
        $customerType = $isBusinessOrder ? 'Business' : 'Consumer';
        $maxAttempts = 4;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $stats['api_calls']++;
                $response = $pricingApi->getItemOffers($asin, $marketplaceId, 'New', $customerType);
                $status = $response->status();

                if ($status === 429 && $attempt < $maxAttempts) {
                    $stats['throttle_retries']++;
                    sleep($this->resolveRetryDelaySeconds($response, $attempt));
                    continue;
                }

                if ($status >= 400) {
                    $stats['api_non_200']++;
                    Log::warning('Pending estimate fetch non-200', [
                        'asin' => $asin,
                        'marketplace_id' => $marketplaceId,
                        'region' => $region,
                        'is_business_order' => $isBusinessOrder,
                        'customer_type' => $customerType,
                        'status' => $status,
                        'attempt' => $attempt,
                    ]);
                    return null;
                }

                $json = $response->json();
                $price = $this->extractPriceFromItemOffersPayload(is_array($json) ? $json : []);
                if ($price !== null) {
                    $stats['api_success']++;
                    return $price;
                }

                $stats['payload_missing']++;
                return null;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '429') && $attempt < $maxAttempts) {
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
                    'customer_type' => $customerType,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        return null;
    }

    private function extractPriceFromItemOffersPayload(array $json): ?array
    {
        $payload = (array) ($json['payload'] ?? []);
        $summary = (array) ($payload['Summary'] ?? []);
        $buyBox = (array) (($summary['BuyBoxPrices'] ?? [])[0] ?? []);
        $lowest = (array) (($summary['LowestPrices'] ?? [])[0] ?? []);

        $candidates = [
            data_get($buyBox, 'LandedPrice'),
            data_get($buyBox, 'ListingPrice'),
            data_get($summary, 'ListPrice'),
            data_get($lowest, 'LandedPrice'),
            data_get($lowest, 'ListingPrice'),
            data_get($payload, 'Offers.0.ListingPrice'),
        ];

        foreach ($candidates as $money) {
            if (!is_array($money)) {
                continue;
            }

            $amount = (float) ($money['Amount'] ?? 0);
            $currency = strtoupper(trim((string) ($money['CurrencyCode'] ?? '')));
            if ($amount > 0 && $currency !== '') {
                return ['amount' => $amount, 'currency' => $currency];
            }
        }

        return null;
    }

    private function resolveRetryDelaySeconds($response, int $attempt): int
    {
        try {
            $retryAfter = $response->header('Retry-After');
            if (is_numeric($retryAfter)) {
                return max(1, min(30, (int) $retryAfter));
            }
        } catch (\Throwable) {
            // ignore
        }

        return min(10, 1 + ($attempt * 2));
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
