<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SellingPartnerApi\SellingPartnerApi;
use SellingPartnerApi\Seller\ProductFeesV0\Dto\FeesEstimateRequest;
use SellingPartnerApi\Seller\ProductFeesV0\Dto\GetMyFeesEstimateRequest;
use SellingPartnerApi\Seller\ProductFeesV0\Dto\MoneyType;
use SellingPartnerApi\Seller\ProductFeesV0\Dto\PriceToEstimateFees;

class OrderFeeEstimateService
{
    public function refresh(
        int $days = 14,
        int $limit = 300,
        int $maxLookups = 120,
        int $staleMinutes = 360,
        ?string $region = null
    ): array {
        $days = max(1, min($days, 60));
        $limit = max(1, min($limit, 3000));
        $maxLookups = max(1, min($maxLookups, 500));
        $staleMinutes = max(1, min($staleMinutes, 1440));

        $stats = [
            'considered' => 0,
            'orders_updated' => 0,
            'lookup_keys' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'api_calls' => 0,
            'api_success' => 0,
            'api_non_200' => 0,
            'throttle_retries' => 0,
            'payload_missing' => 0,
            'exceptions' => 0,
            'skipped_no_basis' => 0,
        ];

        $rows = $this->candidateItems($days, $limit, $staleMinutes, $region);
        if ($rows->isEmpty()) {
            return $stats;
        }
        $stats['considered'] = $rows->count();

        $regionService = new RegionConfigService();
        $connectors = [];
        $lookups = [];
        $itemBasis = [];

        foreach ($rows as $row) {
            $basis = $this->resolveUnitPriceBasis($row);
            if ($basis === null) {
                $stats['skipped_no_basis']++;
                continue;
            }

            $resolvedRegion = $region ? strtoupper(trim($region)) : $this->regionForCountry((string) ($row->country_code ?? ''));
            if (!in_array($resolvedRegion, ['EU', 'NA', 'FE'], true)) {
                continue;
            }

            $marketplaceId = trim((string) ($row->marketplace_id ?? ''));
            $asin = strtoupper(trim((string) ($row->asin ?? '')));
            if ($marketplaceId === '' || $asin === '') {
                continue;
            }

            $isAmazonFulfilled = strtoupper(trim((string) ($row->fulfillment_channel ?? ''))) === 'AFN';
            $lookupKey = implode('|', [
                $resolvedRegion,
                $marketplaceId,
                $asin,
                $basis['currency'],
                number_format($basis['unit_amount'], 2, '.', ''),
                $isAmazonFulfilled ? '1' : '0',
            ]);

            $lookups[$lookupKey] = [
                'region' => $resolvedRegion,
                'marketplace_id' => $marketplaceId,
                'asin' => $asin,
                'currency' => $basis['currency'],
                'unit_amount' => $basis['unit_amount'],
                'is_amazon_fulfilled' => $isAmazonFulfilled,
            ];

            $itemBasis[] = [
                'order_id' => (string) $row->amazon_order_id,
                'lookup_key' => $lookupKey,
                'qty' => max(0, (int) ($row->quantity_ordered ?? 0)),
                'order_currency' => strtoupper(trim((string) ($row->order_total_currency ?? ''))),
            ];
        }

        if (empty($lookups) || empty($itemBasis)) {
            return $stats;
        }

        $lookups = array_slice($lookups, 0, $maxLookups, true);
        $stats['lookup_keys'] = count($lookups);
        $lookupResults = [];

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

            $cacheKey = 'pending_est_fee:' . sha1($lookupKey);
            $fee = Cache::get($cacheKey);
            if (is_array($fee)) {
                $stats['cache_hits']++;
            } else {
                $stats['cache_misses']++;
                $fee = $this->fetchFeeEstimate($connectors[$regionCode], $lookup, $stats);
                if (is_array($fee)) {
                    Cache::put($cacheKey, $fee, now()->addMinutes(30));
                }
            }

            if (is_array($fee)) {
                $lookupResults[$lookupKey] = $fee;
            }
        }

        $orderCurrencyTotals = [];
        foreach ($itemBasis as $row) {
            $lookupKey = $row['lookup_key'];
            if (!isset($lookupResults[$lookupKey])) {
                continue;
            }

            $qty = (int) ($row['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $feePerUnit = (float) ($lookupResults[$lookupKey]['amount'] ?? 0);
            $currency = strtoupper(trim((string) ($lookupResults[$lookupKey]['currency'] ?? '')));
            if ($feePerUnit == 0.0 || $currency === '') {
                continue;
            }

            $orderId = (string) $row['order_id'];
            if (!isset($orderCurrencyTotals[$orderId])) {
                $orderCurrencyTotals[$orderId] = [];
            }
            $orderCurrencyTotals[$orderId][$currency] = ($orderCurrencyTotals[$orderId][$currency] ?? 0.0) + round($feePerUnit * $qty, 2);
        }

        $updated = 0;
        foreach ($orderCurrencyTotals as $orderId => $currencyTotals) {
            if (empty($currencyTotals)) {
                continue;
            }

            $orderCurrency = DB::table('orders')->where('amazon_order_id', $orderId)->value('order_total_currency');
            $orderCurrency = strtoupper(trim((string) $orderCurrency));
            if ($orderCurrency !== '' && isset($currencyTotals[$orderCurrency])) {
                $currency = $orderCurrency;
                $amount = $currencyTotals[$orderCurrency];
            } else {
                arsort($currencyTotals, SORT_NUMERIC);
                $currency = (string) array_key_first($currencyTotals);
                $amount = (float) ($currencyTotals[$currency] ?? 0);
            }

            DB::table('orders')
                ->where('amazon_order_id', $orderId)
                ->whereNull('amazon_fee_total')
                ->update([
                    'amazon_fee_estimated_total' => round($amount, 2),
                    'amazon_fee_estimated_currency' => $currency,
                    'amazon_fee_estimated_source' => 'spapi_product_fees',
                    'amazon_fee_estimated_at' => now(),
                    'updated_at' => now(),
                ]);

            $updated++;
        }

        $stats['orders_updated'] = $updated;
        return $stats;
    }

    private function candidateItems(int $days, int $limit, int $staleMinutes, ?string $region)
    {
        $metricDateExpr = "COALESCE(orders.purchase_date_local_date, DATE(orders.purchase_date))";
        $fromDate = Carbon::now()->subDays($days)->toDateString();
        $staleCutoff = Carbon::now()->subMinutes($staleMinutes)->toDateTimeString();
        $region = $region ? strtoupper(trim($region)) : null;

        $query = DB::table('order_items')
            ->join('orders', 'orders.amazon_order_id', '=', 'order_items.amazon_order_id')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->select([
                'order_items.amazon_order_id',
                'order_items.asin',
                'order_items.quantity_ordered',
                'order_items.item_price_amount',
                'order_items.item_price_currency',
                'order_items.line_net_ex_tax',
                'order_items.line_net_currency',
                'order_items.estimated_line_net_ex_tax',
                'order_items.estimated_line_currency',
                'orders.marketplace_id',
                'orders.fulfillment_channel',
                'orders.order_total_currency',
                'marketplaces.country_code',
            ])
            ->whereRaw("{$metricDateExpr} >= ?", [$fromDate])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->whereNull('orders.amazon_fee_total')
            ->where(function ($q) use ($staleCutoff) {
                $q->whereNull('orders.amazon_fee_estimated_at')
                    ->orWhere('orders.amazon_fee_estimated_at', '<=', $staleCutoff);
            })
            ->whereRaw("TRIM(COALESCE(order_items.asin, '')) <> ''")
            ->whereRaw("TRIM(COALESCE(orders.marketplace_id, '')) <> ''")
            ->whereRaw("COALESCE(order_items.quantity_ordered, 0) > 0")
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

    private function resolveUnitPriceBasis($row): ?array
    {
        $qty = max(0, (int) ($row->quantity_ordered ?? 0));
        if ($qty <= 0) {
            return null;
        }

        $lineAmount = (float) ($row->line_net_ex_tax ?? 0);
        $lineCurrency = strtoupper(trim((string) ($row->line_net_currency ?? '')));
        if ($lineAmount > 0 && $lineCurrency !== '') {
            return ['unit_amount' => round($lineAmount / $qty, 2), 'currency' => $lineCurrency];
        }

        $itemAmount = (float) ($row->item_price_amount ?? 0);
        $itemCurrency = strtoupper(trim((string) ($row->item_price_currency ?? '')));
        if ($itemAmount > 0 && $itemCurrency !== '') {
            return ['unit_amount' => round($itemAmount, 2), 'currency' => $itemCurrency];
        }

        $estimatedAmount = (float) ($row->estimated_line_net_ex_tax ?? 0);
        $estimatedCurrency = strtoupper(trim((string) ($row->estimated_line_currency ?? '')));
        if ($estimatedAmount > 0 && $estimatedCurrency !== '') {
            return ['unit_amount' => round($estimatedAmount / $qty, 2), 'currency' => $estimatedCurrency];
        }

        return null;
    }

    private function fetchFeeEstimate(SellingPartnerApi $connector, array $lookup, array &$stats): ?array
    {
        $api = $connector->productFeesV0();
        $maxAttempts = 4;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $stats['api_calls']++;
                $request = new GetMyFeesEstimateRequest(
                    new FeesEstimateRequest(
                        marketplaceId: (string) $lookup['marketplace_id'],
                        priceToEstimateFees: new PriceToEstimateFees(
                            listingPrice: new MoneyType(
                                currencyCode: (string) $lookup['currency'],
                                amount: (float) $lookup['unit_amount']
                            )
                        ),
                        identifier: 'fee-est-' . substr(sha1(json_encode($lookup)), 0, 12),
                        isAmazonFulfilled: (bool) $lookup['is_amazon_fulfilled'],
                    )
                );

                $response = $api->getMyFeesEstimateForAsin((string) $lookup['asin'], $request);
                $status = $response->status();
                if ($status === 429 && $attempt < $maxAttempts) {
                    $stats['throttle_retries']++;
                    sleep(min(10, 1 + ($attempt * 2)));
                    continue;
                }

                if ($status >= 400) {
                    $stats['api_non_200']++;
                    Log::warning('Fee estimate API non-200', [
                        'asin' => $lookup['asin'],
                        'marketplace_id' => $lookup['marketplace_id'],
                        'status' => $status,
                    ]);
                    return null;
                }

                $json = $response->json();
                $parsed = $this->extractEstimatedFeeFromPayload(is_array($json) ? $json : []);
                if ($parsed !== null) {
                    $stats['api_success']++;
                    return $parsed;
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
                Log::warning('Fee estimate API exception', [
                    'asin' => $lookup['asin'] ?? null,
                    'marketplace_id' => $lookup['marketplace_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        return null;
    }

    private function extractEstimatedFeeFromPayload(array $json): ?array
    {
        $candidates = [
            data_get($json, 'payload.FeesEstimateResult.FeesEstimate.TotalFeesEstimate'),
            data_get($json, 'payload.FeesEstimateResult.feesEstimate.totalFeesEstimate'),
            data_get($json, 'payload.feesEstimateResult.FeesEstimate.TotalFeesEstimate'),
            data_get($json, 'payload.feesEstimateResult.feesEstimate.totalFeesEstimate'),
        ];

        foreach ($candidates as $money) {
            if (!is_array($money)) {
                continue;
            }

            $amount = $money['Amount'] ?? $money['amount'] ?? null;
            $currency = $money['CurrencyCode'] ?? $money['currencyCode'] ?? null;
            $amount = is_numeric($amount) ? (float) $amount : 0.0;
            $currency = strtoupper(trim((string) $currency));
            if ($amount != 0.0 && $currency !== '') {
                return [
                    'amount' => $amount,
                    'currency' => $currency,
                ];
            }
        }

        return null;
    }

    private function regionForCountry(string $countryCode): string
    {
        $countryCode = strtoupper(trim($countryCode));
        if (in_array($countryCode, ['US', 'CA', 'MX', 'BR'], true)) {
            return 'NA';
        }
        if (in_array($countryCode, ['JP', 'AU', 'SG', 'AE', 'IN', 'SA'], true)) {
            return 'FE';
        }
        return 'EU';
    }
}

