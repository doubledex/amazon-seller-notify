<?php

namespace App\Http\Controllers;

use App\Services\FxRateService;
use App\Services\RegionConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SellingPartnerApi\SellingPartnerApi;

class MetricsController extends Controller
{
    public function index(Request $request, FxRateService $fxRateService)
    {
        $fromInput = $request->input('from');
        $toInput = $request->input('to');

        $from = $fromInput ? Carbon::parse($fromInput)->toDateString() : now()->subDays(30)->toDateString();
        $to = $toInput ? Carbon::parse($toInput)->toDateString() : now()->toDateString();
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $metricDateExpr = "COALESCE(orders.purchase_date_local_date, DATE(orders.purchase_date))";

        $itemTotals = DB::table('order_items')
            ->selectRaw("
                amazon_order_id,
                MAX(COALESCE(line_net_currency, item_price_currency, '')) as item_currency,
                SUM(COALESCE(line_net_ex_tax, 0)) as item_total,
                SUM(COALESCE(quantity_ordered, 0)) as item_units
            ")
            ->groupBy('amazon_order_id');

        $salesRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->selectRaw("
                {$metricDateExpr} as metric_date,
                marketplaces.country_code as country_code,
                COALESCE(
                    NULLIF(orders.order_net_ex_tax_currency, ''),
                    NULLIF(item_totals.item_currency, ''),
                    NULLIF(orders.order_total_currency, ''),
                    NULLIF(marketplaces.default_currency, ''),
                    'GBP'
                ) as currency,
                SUM(
                    CASE
                        WHEN COALESCE(orders.order_net_ex_tax, 0) > 0 THEN orders.order_net_ex_tax
                        ELSE COALESCE(item_totals.item_total, 0)
                    END
                ) as sales_amount,
                COUNT(*) as order_count
            ")
            ->whereRaw("{$metricDateExpr} >= ?", [$from])
            ->whereRaw("{$metricDateExpr} <= ?", [$to])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->groupByRaw("
                {$metricDateExpr},
                marketplaces.country_code,
                COALESCE(NULLIF(orders.order_net_ex_tax_currency, ''), NULLIF(item_totals.item_currency, ''), NULLIF(orders.order_total_currency, ''), NULLIF(marketplaces.default_currency, ''), 'GBP')
            ")
            ->get();

        $pendingSalesRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->selectRaw("
                {$metricDateExpr} as metric_date,
                marketplaces.country_code as country_code,
                COUNT(*) as pending_count
            ")
            ->whereRaw("{$metricDateExpr} >= ?", [$from])
            ->whereRaw("{$metricDateExpr} <= ?", [$to])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) IN ('PENDING', 'UNSHIPPED')")
            ->whereRaw("COALESCE(orders.order_net_ex_tax, 0) <= 0")
            ->whereRaw("COALESCE(item_totals.item_total, 0) <= 0")
            ->groupByRaw("{$metricDateExpr}, marketplaces.country_code")
            ->get();

        $pendingOrderItemsForEstimate = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->join('order_items', 'order_items.amazon_order_id', '=', 'orders.amazon_order_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->selectRaw("
                {$metricDateExpr} as metric_date,
                marketplaces.country_code as country_code,
                orders.marketplace_id as marketplace_id,
                COALESCE(orders.is_business_order, 0) as is_business_order,
                UPPER(COALESCE(order_items.asin, '')) as asin,
                SUM(COALESCE(order_items.quantity_ordered, 0)) as units
            ")
            ->whereRaw("{$metricDateExpr} >= ?", [$from])
            ->whereRaw("{$metricDateExpr} <= ?", [$to])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) IN ('PENDING', 'UNSHIPPED')")
            ->whereRaw("COALESCE(orders.order_net_ex_tax, 0) <= 0")
            ->whereRaw("COALESCE(item_totals.item_total, 0) <= 0")
            ->whereRaw("TRIM(COALESCE(order_items.asin, '')) <> ''")
            ->whereRaw("TRIM(COALESCE(orders.marketplace_id, '')) <> ''")
            ->groupByRaw("
                {$metricDateExpr},
                marketplaces.country_code,
                orders.marketplace_id,
                COALESCE(orders.is_business_order, 0),
                UPPER(COALESCE(order_items.asin, ''))
            ")
            ->get();

        $unitRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoin('order_items', 'order_items.amazon_order_id', '=', 'orders.amazon_order_id')
            ->selectRaw("
                {$metricDateExpr} as metric_date,
                marketplaces.country_code as country_code,
                SUM(COALESCE(order_items.quantity_ordered, 0)) as units
            ")
            ->whereRaw("{$metricDateExpr} >= ?", [$from])
            ->whereRaw("{$metricDateExpr} <= ?", [$to])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->groupByRaw("{$metricDateExpr}, marketplaces.country_code")
            ->get();

        $latestAdsRequests = DB::table('amazon_ads_report_daily_spends as ds_latest')
            ->join('amazon_ads_report_requests as rr_latest', 'rr_latest.id', '=', 'ds_latest.report_request_id')
            ->selectRaw("
                ds_latest.metric_date as metric_date,
                ds_latest.profile_id as profile_id,
                rr_latest.ad_product as ad_product,
                MAX(rr_latest.id) as latest_request_id
            ")
            ->groupBy('ds_latest.metric_date', 'ds_latest.profile_id', 'rr_latest.ad_product');

        $adRows = DB::table('amazon_ads_report_daily_spends as ds')
            ->join('amazon_ads_report_requests as rr', 'rr.id', '=', 'ds.report_request_id')
            ->joinSub($latestAdsRequests, 'latest_ads', function ($join) {
                $join->on('latest_ads.metric_date', '=', 'ds.metric_date')
                    ->on('latest_ads.profile_id', '=', 'ds.profile_id')
                    ->on('latest_ads.ad_product', '=', 'rr.ad_product')
                    ->on('latest_ads.latest_request_id', '=', 'rr.id');
            })
            ->selectRaw("
                ds.metric_date as metric_date,
                rr.country_code as country_code,
                COALESCE(ds.source_currency, rr.currency_code, ds.currency, 'GBP') as currency,
                SUM(COALESCE(ds.source_amount, ds.amount_local)) as ad_amount
            ")
            ->whereDate('ds.metric_date', '>=', $from)
            ->whereDate('ds.metric_date', '<=', $to)
            ->groupBy('ds.metric_date', 'rr.country_code', 'ds.source_currency', 'rr.currency_code', 'ds.currency')
            ->get();

        $dates = [];
        $breakdown = [];

        foreach ($salesRows as $row) {
            $date = (string) $row->metric_date;
            $country = $this->normalizeMarketplaceCode((string) $row->country_code);
            $currency = strtoupper((string) $row->currency);
            $amount = (float) $row->sales_amount;
            if ($date === '' || $country === '') {
                continue;
            }

            $dates[$date] = true;
            $key = $date . '|' . $country;
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'date' => $date,
                    'country' => $country,
                    'currency' => $currency,
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'ad_local' => 0.0,
                    'ad_gbp' => 0.0,
                    'pending_sales_data' => false,
                ];
            }

            $breakdown[$key]['currency'] = $currency;
            $breakdown[$key]['sales_local'] += $amount;
            $breakdown[$key]['sales_gbp'] += $fxRateService->convert($amount, $currency, 'GBP', $date) ?? 0.0;
            $breakdown[$key]['order_count'] += (int) ($row->order_count ?? 0);
        }

        foreach ($unitRows as $row) {
            $date = (string) $row->metric_date;
            $country = $this->normalizeMarketplaceCode((string) $row->country_code);
            if ($date === '' || $country === '') {
                continue;
            }

            $dates[$date] = true;
            $key = $date . '|' . $country;
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'date' => $date,
                    'country' => $country,
                    'currency' => 'GBP',
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'ad_local' => 0.0,
                    'ad_gbp' => 0.0,
                    'pending_sales_data' => false,
                ];
            }
            $breakdown[$key]['units'] += (int) ($row->units ?? 0);
        }

        foreach ($pendingSalesRows as $row) {
            $date = (string) $row->metric_date;
            $country = $this->normalizeMarketplaceCode((string) $row->country_code);
            if ($date === '' || $country === '') {
                continue;
            }

            $dates[$date] = true;
            $key = $date . '|' . $country;
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'date' => $date,
                    'country' => $country,
                    'currency' => 'GBP',
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'ad_local' => 0.0,
                    'ad_gbp' => 0.0,
                    'pending_sales_data' => false,
                ];
            }
            $breakdown[$key]['pending_sales_data'] = ((int) ($row->pending_count ?? 0)) > 0;
        }

        $pendingPricingDebug = [
            'considered_rows' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'api_calls' => 0,
            'api_success' => 0,
            'priced_rows' => 0,
            'skipped_no_price' => 0,
            'throttle_retries' => 0,
            'api_non_200' => 0,
            'payload_missing' => 0,
            'exceptions' => 0,
        ];
        $apiPriceLookup = $this->fetchPendingApiPrices($pendingOrderItemsForEstimate, $pendingPricingDebug);
        foreach ($pendingOrderItemsForEstimate as $row) {
            $pendingPricingDebug['considered_rows']++;
            $date = (string) $row->metric_date;
            $country = $this->normalizeMarketplaceCode((string) $row->country_code);
            if ($date === '' || $country === '') {
                continue;
            }

            $units = (int) ($row->units ?? 0);
            if ($units <= 0) {
                continue;
            }

            $marketplaceId = trim((string) ($row->marketplace_id ?? ''));
            $isBusinessOrder = ((int) ($row->is_business_order ?? 0)) === 1;
            $asin = strtoupper(trim((string) ($row->asin ?? '')));
            if ($marketplaceId === '' || $asin === '') {
                continue;
            }

            $price = $this->findApiPrice($apiPriceLookup, $marketplaceId, $isBusinessOrder, $asin);
            if ($price === null) {
                $pendingPricingDebug['skipped_no_price']++;
                continue;
            }

            $sourceCurrency = strtoupper(trim((string) ($price['currency'] ?? '')));
            $unitPrice = (float) ($price['amount'] ?? 0);
            if ($sourceCurrency === '' || $unitPrice <= 0) {
                continue;
            }

            $estimatedAmount = $unitPrice * $units;
            if ($estimatedAmount <= 0) {
                continue;
            }

            $dates[$date] = true;
            $key = $date . '|' . $country;
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'date' => $date,
                    'country' => $country,
                    'currency' => $sourceCurrency,
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'ad_local' => 0.0,
                    'ad_gbp' => 0.0,
                    'pending_sales_data' => false,
                ];
            }

            $entryCurrency = strtoupper((string) ($breakdown[$key]['currency'] ?? ''));
            if ($entryCurrency === '') {
                $entryCurrency = $sourceCurrency;
                $breakdown[$key]['currency'] = $entryCurrency;
            }

            $localAmount = $estimatedAmount;
            if ($sourceCurrency !== $entryCurrency) {
                $convertedLocal = $fxRateService->convert($estimatedAmount, $sourceCurrency, $entryCurrency, $date);
                if ($convertedLocal !== null) {
                    $localAmount = $convertedLocal;
                }
            }

            $breakdown[$key]['sales_local'] += $localAmount;
            $breakdown[$key]['sales_gbp'] += $fxRateService->convert($estimatedAmount, $sourceCurrency, 'GBP', $date) ?? 0.0;
            $breakdown[$key]['pending_sales_data'] = true;
            $pendingPricingDebug['priced_rows']++;
        }

        foreach ($adRows as $row) {
            $date = (string) $row->metric_date;
            $country = $this->normalizeMarketplaceCode((string) $row->country_code);
            $currency = strtoupper((string) $row->currency);
            $amount = (float) $row->ad_amount;
            if ($date === '' || $country === '') {
                continue;
            }

            $dates[$date] = true;
            $key = $date . '|' . $country;
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'date' => $date,
                    'country' => $country,
                    'currency' => $currency,
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'ad_local' => 0.0,
                    'ad_gbp' => 0.0,
                    'pending_sales_data' => false,
                ];
            }

            $breakdown[$key]['currency'] = $currency;
            $breakdown[$key]['ad_local'] += $amount;
            $breakdown[$key]['ad_gbp'] += $fxRateService->convert($amount, $currency, 'GBP', $date) ?? 0.0;
        }

        $dailyRows = [];
        $dateList = array_keys($dates);
        rsort($dateList);

        foreach ($dateList as $date) {
            $items = [];
            $totalSalesGbp = 0.0;
            $totalAdGbp = 0.0;
            $totalOrders = 0;
            $totalUnits = 0;
            $hasPendingSalesData = false;

            foreach ($breakdown as $entry) {
                if ($entry['date'] !== $date) {
                    continue;
                }

                $acos = $entry['sales_gbp'] > 0 ? ($entry['ad_gbp'] / $entry['sales_gbp']) * 100 : null;
                $entry['acos_percent'] = $acos;
                $entry['currency_symbol'] = $this->currencySymbol($entry['currency']);
                $items[] = $entry;
                $totalSalesGbp += $entry['sales_gbp'];
                $totalAdGbp += $entry['ad_gbp'];
                $totalOrders += (int) ($entry['order_count'] ?? 0);
                $totalUnits += (int) ($entry['units'] ?? 0);
                $hasPendingSalesData = $hasPendingSalesData || (bool) ($entry['pending_sales_data'] ?? false);
            }

            usort($items, fn ($a, $b) => strcmp($a['country'], $b['country']));
            $dailyRows[] = [
                'date' => $date,
                'sales_gbp' => $totalSalesGbp,
                'ad_gbp' => $totalAdGbp,
                'order_count' => $totalOrders,
                'units' => $totalUnits,
                'acos_percent' => $totalSalesGbp > 0 ? ($totalAdGbp / $totalSalesGbp) * 100 : null,
                'pending_sales_data' => $hasPendingSalesData,
                'items' => $items,
            ];
        }

        return view('metrics.index', [
            'rows' => $dailyRows,
            'from' => $from,
            'to' => $to,
            'pendingPricingDebug' => $pendingPricingDebug,
        ]);
    }

    private function currencySymbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            'SEK' => 'kr',
            'PLN' => 'zł',
            default => strtoupper($currency) . ' ',
        };
    }

    private function normalizeMarketplaceCode(string $countryCode): string
    {
        $code = strtoupper(trim($countryCode));
        if ($code === 'UK') {
            return 'GB';
        }

        return $code;
    }

    private function fetchPendingApiPrices($pendingOrderItems, array &$stats): array
    {
        $lookup = [];
        if ($pendingOrderItems->isEmpty()) {
            return $lookup;
        }

        $regionService = new RegionConfigService();
        $connectors = [];

        foreach ($pendingOrderItems as $row) {
            $marketplaceId = trim((string) ($row->marketplace_id ?? ''));
            $asin = strtoupper(trim((string) ($row->asin ?? '')));
            $isBusinessOrder = ((int) ($row->is_business_order ?? 0)) === 1;
            if ($marketplaceId === '' || $asin === '') {
                continue;
            }

            $country = $this->normalizeMarketplaceCode((string) ($row->country_code ?? ''));
            $region = $this->regionForCountry($country);
            if ($region === null) {
                continue;
            }

            if (!isset($connectors[$region])) {
                $spConfig = $regionService->spApiConfig($region);
                if (
                    trim((string) ($spConfig['client_id'] ?? '')) === ''
                    || trim((string) ($spConfig['client_secret'] ?? '')) === ''
                    || trim((string) ($spConfig['refresh_token'] ?? '')) === ''
                ) {
                    continue;
                }

                $connectors[$region] = SellingPartnerApi::seller(
                    clientId: (string) $spConfig['client_id'],
                    clientSecret: (string) $spConfig['client_secret'],
                    refreshToken: (string) $spConfig['refresh_token'],
                    endpoint: $regionService->spApiEndpointEnum($region)
                );
            }

            $cacheKey = 'pending_price:' . sha1($marketplaceId . '|' . $asin . '|' . ($isBusinessOrder ? '1' : '0'));
            $price = Cache::get($cacheKey);
            if (is_array($price)) {
                $stats['cache_hits']++;
            } else {
                $stats['cache_misses']++;
                $price = $this->fetchSinglePendingApiPrice(
                    $connectors[$region],
                    $marketplaceId,
                    $asin,
                    $isBusinessOrder,
                    $region,
                    $stats
                );
                if (is_array($price)) {
                    Cache::put($cacheKey, $price, now()->addMinutes(30));
                }
            }

            if (!is_array($price)) {
                continue;
            }

            $specificKey = $marketplaceId . '|' . ($isBusinessOrder ? '1' : '0') . '|' . $asin;
            $lookup[$specificKey] = $price;
            $genericKey = $marketplaceId . '|*|' . $asin;
            $lookup[$genericKey] = $lookup[$genericKey] ?? $price;
        }

        return $lookup;
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
        $lastStatus = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $stats['api_calls']++;
                $response = $pricingApi->getItemOffers($asin, $marketplaceId, 'New', $customerType);
                $lastStatus = $response->status();

                if ($lastStatus === 429 && $attempt < $maxAttempts) {
                    $stats['throttle_retries']++;
                    sleep($this->resolveRetryDelaySeconds($response, $attempt));
                    continue;
                }

                if ($lastStatus >= 400) {
                    $stats['api_non_200']++;
                    Log::warning('Pending item price fetch non-200', [
                        'asin' => $asin,
                        'marketplace_id' => $marketplaceId,
                        'region' => $region,
                        'is_business_order' => $isBusinessOrder,
                        'customer_type' => $customerType,
                        'status' => $lastStatus,
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
                Log::warning('Pending item price missing in payload', [
                    'asin' => $asin,
                    'marketplace_id' => $marketplaceId,
                    'region' => $region,
                    'is_business_order' => $isBusinessOrder,
                    'customer_type' => $customerType,
                    'status' => $lastStatus,
                    'attempt' => $attempt,
                ]);
                return null;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '429') && $attempt < $maxAttempts) {
                    $stats['throttle_retries']++;
                    sleep(min(10, 1 + ($attempt * 2)));
                    continue;
                }

                $stats['exceptions']++;
                Log::warning('Pending item price fetch failed', [
                    'asin' => $asin,
                    'marketplace_id' => $marketplaceId,
                    'region' => $region,
                    'is_business_order' => $isBusinessOrder,
                    'customer_type' => $customerType,
                    'status' => $lastStatus,
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

    private function findApiPrice(array $lookup, string $marketplaceId, bool $isBusinessOrder, string $asin): ?array
    {
        $specificKey = $marketplaceId . '|' . ($isBusinessOrder ? '1' : '0') . '|' . $asin;
        if (isset($lookup[$specificKey])) {
            return $lookup[$specificKey];
        }

        $genericKey = $marketplaceId . '|*|' . $asin;
        return $lookup[$genericKey] ?? null;
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
        $countryCode = strtoupper(trim($countryCode));

        if (in_array($countryCode, ['US', 'CA', 'MX', 'BR'], true)) {
            return 'NA';
        }

        if (in_array($countryCode, ['GB', 'UK', 'AT', 'BE', 'DE', 'DK', 'ES', 'FI', 'FR', 'IE', 'IT', 'LU', 'NL', 'NO', 'PL', 'SE', 'CH', 'TR'], true)) {
            return 'EU';
        }

        return null;
    }

}
