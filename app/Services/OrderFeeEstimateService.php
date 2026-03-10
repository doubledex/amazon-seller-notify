<?php

namespace App\Services;

use App\Services\Amazon\OfficialSpApiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SpApi\Model\productFees\v0\FeesEstimateRequest;
use SpApi\Model\productFees\v0\GetMyFeesEstimateRequest;
use SpApi\Model\productFees\v0\MoneyType;
use SpApi\Model\productFees\v0\PriceToEstimateFees;

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
        $feesApisByRegion = [];
        $officialSpApiService = new OfficialSpApiService($regionService);
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
                'asin' => (string) $asin,
                'marketplace_id' => (string) $marketplaceId,
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
            if (!isset($feesApisByRegion[$regionCode])) {
                $spConfig = $regionService->spApiConfig($regionCode);
                if (
                    trim((string) ($spConfig['client_id'] ?? '')) === ''
                    || trim((string) ($spConfig['client_secret'] ?? '')) === ''
                    || trim((string) ($spConfig['refresh_token'] ?? '')) === ''
                ) {
                    continue;
                }

                $api = $officialSpApiService->makeProductFeesV0Api($regionCode);
                if ($api === null) {
                    continue;
                }
                $feesApisByRegion[$regionCode] = $api;
            }

            $cacheKey = 'pending_est_fee:' . sha1($lookupKey);
            $fee = Cache::get($cacheKey);
            if (is_array($fee)) {
                $stats['cache_hits']++;
            } else {
                $stats['cache_misses']++;
                $fee = $this->fetchFeeEstimate($feesApisByRegion[$regionCode], $lookup, $stats);
                if (is_array($fee)) {
                    Cache::put($cacheKey, $fee, now()->addMinutes(30));
                }
            }

            if (is_array($fee)) {
                $lookupResults[$lookupKey] = $fee;
            }
        }

        $orderCurrencyTotals = [];
        $orderLineRows = [];
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
            $lineTotal = round($feePerUnit * $qty, 2);
            $orderCurrencyTotals[$orderId][$currency] = ($orderCurrencyTotals[$orderId][$currency] ?? 0.0) + $lineTotal;

            $breakdown = (array) ($lookupResults[$lookupKey]['breakdown'] ?? []);
            if (!empty($breakdown)) {
                foreach ($breakdown as $detail) {
                    $detailAmount = (float) ($detail['amount'] ?? 0);
                    $detailCurrency = strtoupper(trim((string) ($detail['currency'] ?? '')));
                    if ($detailAmount == 0.0 || $detailCurrency === '') {
                        continue;
                    }

                    $detailLineTotal = round($detailAmount * $qty, 2);
                    $feeType = trim((string) ($detail['fee_type'] ?? ''));
                    $description = $feeType !== '' ? $feeType : 'Estimated fee component';
                    $rawLine = [
                        'detail' => $detail['raw'] ?? null,
                        'payload' => $lookupResults[$lookupKey]['raw_payload'] ?? null,
                    ];
                    $hashBase = implode('|', [
                        $orderId,
                        (string) ($row['asin'] ?? ''),
                        (string) ($row['marketplace_id'] ?? ''),
                        $feeType,
                        number_format($detailLineTotal, 2, '.', ''),
                        $detailCurrency,
                    ]);

                    $orderLineRows[] = [
                        'line_hash' => sha1($hashBase),
                        'amazon_order_id' => $orderId,
                        'asin' => (string) ($row['asin'] ?? ''),
                        'marketplace_id' => (string) ($row['marketplace_id'] ?? ''),
                        'fee_type' => $feeType !== '' ? $feeType : null,
                        'description' => $description,
                        'amount' => $detailLineTotal,
                        'currency' => $detailCurrency,
                        'source' => 'spapi_product_fees',
                        'estimated_at' => now(),
                        'raw_line' => json_encode($rawLine),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            } else {
                $hashBase = implode('|', [
                    $orderId,
                    (string) ($row['asin'] ?? ''),
                    (string) ($row['marketplace_id'] ?? ''),
                    'TotalEstimatedFees',
                    number_format($lineTotal, 2, '.', ''),
                    $currency,
                ]);
                $orderLineRows[] = [
                    'line_hash' => sha1($hashBase),
                    'amazon_order_id' => $orderId,
                    'asin' => (string) ($row['asin'] ?? ''),
                    'marketplace_id' => (string) ($row['marketplace_id'] ?? ''),
                    'fee_type' => 'TotalEstimatedFees',
                    'description' => 'Total estimated fees',
                    'amount' => $lineTotal,
                    'currency' => $currency,
                    'source' => 'spapi_product_fees',
                    'estimated_at' => now(),
                    'raw_line' => json_encode([
                        'detail' => null,
                        'payload' => $lookupResults[$lookupKey]['raw_payload'] ?? null,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($orderLineRows) && DB::getSchemaBuilder()->hasTable('order_fee_estimate_lines')) {
            $orderIdsToReplace = array_values(array_unique(array_map(
                fn (array $row): string => (string) ($row['amazon_order_id'] ?? ''),
                $orderLineRows
            )));
            $orderIdsToReplace = array_values(array_filter($orderIdsToReplace, fn (string $id): bool => $id !== ''));

            foreach (array_chunk($orderIdsToReplace, 500) as $orderChunk) {
                DB::table('order_fee_estimate_lines')
                    ->whereIn('amazon_order_id', $orderChunk)
                    ->where('source', 'spapi_product_fees')
                    ->delete();
            }

            foreach (array_chunk($orderLineRows, 500) as $chunk) {
                DB::table('order_fee_estimate_lines')->upsert(
                    $chunk,
                    ['line_hash'],
                    ['fee_type', 'description', 'amount', 'currency', 'source', 'estimated_at', 'raw_line', 'updated_at']
                );
            }
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
                    ->orWhere('orders.amazon_fee_estimated_at', '<=', $staleCutoff)
                    ->orWhereNotExists(function ($sq) {
                        $sq->select(DB::raw(1))
                            ->from('order_fee_estimate_lines')
                            ->whereColumn('order_fee_estimate_lines.amazon_order_id', 'orders.amazon_order_id');
                    });
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

    private function fetchFeeEstimate(object $feesApi, array $lookup, array &$stats): ?array
    {
        $maxAttempts = 4;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $stats['api_calls']++;
                $request = new GetMyFeesEstimateRequest([
                    'fees_estimate_request' => new FeesEstimateRequest([
                        'marketplace_id' => (string) $lookup['marketplace_id'],
                        'price_to_estimate_fees' => new PriceToEstimateFees([
                            'listing_price' => new MoneyType([
                                'currency_code' => (string) $lookup['currency'],
                                'amount' => (float) $lookup['unit_amount'],
                            ]),
                        ]),
                        'identifier' => 'fee-est-' . substr(sha1(json_encode($lookup)), 0, 12),
                        'is_amazon_fulfilled' => (bool) $lookup['is_amazon_fulfilled'],
                    ]),
                ]);

                [$response, $status, $headers] = $feesApi->getMyFeesEstimateForASINWithHttpInfo((string) $lookup['asin'], $request);
                if ($status === 429 && $attempt < $maxAttempts) {
                    $stats['throttle_retries']++;
                    sleep($this->resolveRetryDelaySecondsFromHeaders($headers, $attempt));
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

                $jsonPayload = $this->modelToArray($response);
                $parsed = $this->extractEstimatedFeeFromPayload($jsonPayload);
                if ($parsed !== null) {
                    $stats['api_success']++;
                    $parsed['raw_payload'] = $jsonPayload;
                    return $parsed;
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

    private function resolveRetryDelaySecondsFromHeaders(array $headers, int $attempt): int
    {
        $retryAfter = $headers['Retry-After'][0] ?? $headers['retry-after'][0] ?? null;
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

    private function extractEstimatedFeeFromPayload(array $json): ?array
    {
        $breakdown = [];
        $detailTotal = 0.0;
        $detailCurrency = '';
        $detailLists = [
            data_get($json, 'payload.FeesEstimateResult.FeesEstimate.FeeDetailList'),
            data_get($json, 'payload.FeesEstimateResult.feesEstimate.feeDetailList'),
            data_get($json, 'payload.feesEstimateResult.FeesEstimate.FeeDetailList'),
            data_get($json, 'payload.feesEstimateResult.feesEstimate.feeDetailList'),
        ];
        foreach ($detailLists as $details) {
            if (!is_array($details)) {
                continue;
            }
            foreach ($details as $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                $feeType = $detail['FeeType'] ?? $detail['feeType'] ?? null;
                $amountNode = $detail['FeeAmount'] ?? $detail['feeAmount'] ?? null;
                $finalNode = $detail['FinalFee'] ?? $detail['finalFee'] ?? null;
                $taxNode = $detail['TaxAmount'] ?? $detail['taxAmount'] ?? null;
                $amount = is_array($amountNode) ? ($amountNode['Amount'] ?? $amountNode['amount'] ?? null) : null;
                $currency = is_array($amountNode) ? ($amountNode['CurrencyCode'] ?? $amountNode['currencyCode'] ?? null) : null;
                $finalAmount = is_array($finalNode) ? ($finalNode['Amount'] ?? $finalNode['amount'] ?? null) : null;
                $taxAmount = is_array($taxNode) ? ($taxNode['Amount'] ?? $taxNode['amount'] ?? null) : null;
                if (!is_numeric($amount) || trim((string) $currency) === '') {
                    continue;
                }
                $amount = (float) $amount;
                $currency = strtoupper(trim((string) $currency));
                $breakdown[] = [
                    'fee_type' => (string) $feeType,
                    'amount' => $amount, // ex-tax component
                    'currency' => $currency,
                    'final_amount' => is_numeric($finalAmount) ? (float) $finalAmount : null,
                    'tax_amount' => is_numeric($taxAmount) ? (float) $taxAmount : null,
                    'raw' => $detail,
                ];
                $detailTotal += $amount;
                if ($detailCurrency === '') {
                    $detailCurrency = $currency;
                }
            }
            if (!empty($breakdown)) {
                break;
            }
        }

        if (!empty($breakdown) && $detailCurrency !== '') {
            return [
                'amount' => round($detailTotal, 2), // ex-tax total from fee detail components
                'currency' => $detailCurrency,
                'breakdown' => $breakdown,
            ];
        }

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
                    'breakdown' => $breakdown,
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
