<?php

namespace App\Services;

use App\Models\Order;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SpApi\AuthAndAuth\LWAAuthorizationCredentials;
use SpApi\Configuration as OfficialSpApiConfiguration;

class AmazonOrderFeeSyncService
{
    private const MAX_ATTEMPTS = 6;
    private const EVENT_TYPE_V20240619 = 'FinancesTransactionV20240619';

    public function sync(Carbon $from, Carbon $to, ?string $region = null): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $maxPostedBefore = now()->subMinutes(2);
        if ($to->gt($maxPostedBefore)) {
            $to = $maxPostedBefore->copy();
        }
        if ($from->gte($to)) {
            $from = $to->copy()->subDay();
        }

        $regionService = new RegionConfigService();
        $regions = $region ? [strtoupper(trim($region))] : $regionService->spApiRegions();
        $regions = array_values(array_filter($regions, fn (string $r) => in_array($r, ['EU', 'NA', 'FE'], true)));

        $summary = [
            'regions' => count($regions),
            'events' => 0,
            'orders_updated' => 0,
        ];

        foreach ($regions as $configuredRegion) {
            $config = $regionService->spApiConfig($configuredRegion);
            $feeLineRows = [];
            $orderIdsWithV2024Fees = [];
            $events = 0;

            $events += $this->accumulateFeesFromTransactionsV20240619(
                region: $configuredRegion,
                config: $config,
                from: $from,
                to: $to,
                feeLineRows: $feeLineRows,
                orderIdsWithV2024Fees: $orderIdsWithV2024Fees
            );

            $this->persistFeeLines($feeLineRows);
            $updated = $this->recalculateOrderFeesFromLines(
                $configuredRegion,
                $from,
                $to,
                array_keys($orderIdsWithV2024Fees)
            );
            $summary['events'] += $events;
            $summary['orders_updated'] += $updated;
        }

        return $summary;
    }

    private function accumulateFeesFromTransactionsV20240619(
        string $region,
        array $config,
        Carbon $from,
        Carbon $to,
        array &$feeLineRows,
        array &$orderIdsWithV2024Fees
    ): int {
        if (!class_exists(LWAAuthorizationCredentials::class) || !class_exists(OfficialSpApiConfiguration::class)) {
            return 0;
        }

        $host = $this->officialHostForRegion($region);
        if ($host === null) {
            return 0;
        }

        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $refreshToken = trim((string) ($config['refresh_token'] ?? ''));
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return 0;
        }

        try {
            $lwa = new LWAAuthorizationCredentials([
                'clientId' => $clientId,
                'clientSecret' => $clientSecret,
                'refreshToken' => $refreshToken,
                'endpoint' => 'https://api.amazon.com/auth/o2/token',
            ]);
            $officialConfig = new OfficialSpApiConfiguration([], $lwa);
            $officialConfig->setHost($host);
        } catch (\Throwable $e) {
            Log::warning('Fee sync: unable to initialize official SP-API configuration', [
                'region' => $region,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        $http = new GuzzleClient();
        $url = $host . '/finances/2024-06-19/transactions';
        $events = 0;
        $marketplaceIds = array_values(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            (array) ($config['marketplace_ids'] ?? [])
        )));
        // Always include one unfiltered pass so missing marketplace config
        // does not silently drop transactions.
        $marketplaceIds[] = null;
        $marketplaceIds = array_values(array_unique(array_map(
            static fn ($v) => $v === null ? '__ALL__' : $v,
            $marketplaceIds
        )));
        $marketplaceIds = array_map(static fn ($v) => $v === '__ALL__' ? null : $v, $marketplaceIds);

        foreach ($marketplaceIds as $marketplaceId) {
            $nextToken = null;
            do {
                $params = $nextToken
                    ? ['nextToken' => $nextToken]
                    : [
                        'postedAfter' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                        'postedBefore' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    ];
                if ($marketplaceId !== null) {
                    $params['marketplaceId'] = $marketplaceId;
                }

                $request = new Psr7Request('GET', $url . '?' . http_build_query($params), ['accept' => 'application/json']);
                $response = $this->callSignedJsonWithRetries(
                    fn () => $http->send($officialConfig->sign($request)),
                    'finances.v2024.listTransactions'
                );

                if (!$response || ($response['status'] ?? 500) >= 400) {
                    Log::warning('Fee sync: v2024 transactions call failed', [
                        'region' => $region,
                        'marketplace_id' => $marketplaceId,
                        'status' => $response['status'] ?? null,
                    ]);
                    break;
                }

                $payload = (array) (($response['json']['payload'] ?? null) ?: []);
                $transactions = (array) ($payload['transactions'] ?? []);
                $events += count($transactions);

                foreach ($transactions as $txn) {
                    if (!is_array($txn)) {
                        continue;
                    }
                    $orderId = $this->extractOrderIdFromTransaction($txn);
                    if ($orderId === '') {
                        continue;
                    }

                    $feeRows = $this->extractNetFeeRowsFromTransaction($txn, $orderId, $region);
                    if (empty($feeRows)) {
                        continue;
                    }

                    $orderIdsWithV2024Fees[$orderId] = true;
                    foreach ($feeRows as $row) {
                        $feeLineRows[] = $row;
                    }
                }

                $nextToken = trim((string) ($payload['nextToken'] ?? ''));
                if ($nextToken === '') {
                    $nextToken = null;
                }
            } while ($nextToken !== null);
        }

        return $events;
    }

    private function recalculateOrderFeesFromLines(string $region, Carbon $from, Carbon $to, array $processedOrderIds = []): int
    {
        if (!DB::getSchemaBuilder()->hasTable('amazon_order_fee_lines')) {
            return 0;
        }

        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $windowOrderIds = DB::table('orders')
            ->whereIn('marketplace_id', $this->marketplaceIdsForRegion($region))
            ->whereRaw("COALESCE(purchase_date_local_date, DATE(purchase_date)) >= ?", [$fromDate])
            ->whereRaw("COALESCE(purchase_date_local_date, DATE(purchase_date)) <= ?", [$toDate])
            ->pluck('amazon_order_id')
            ->filter()
            ->values()
            ->all();

        $targetOrderIds = array_values(array_unique(array_filter(array_merge(
            $windowOrderIds,
            array_map(static fn ($v) => trim((string) $v), $processedOrderIds)
        ))));

        if (empty($targetOrderIds)) {
            return 0;
        }

        $lines = DB::table('amazon_order_fee_lines')
            ->select('amazon_order_id', 'currency', DB::raw('SUM(amount) as fee_total'))
            ->where('region', $region)
            ->where('event_type', self::EVENT_TYPE_V20240619)
            ->whereIn('amazon_order_id', $targetOrderIds)
            ->groupBy('amazon_order_id', 'currency')
            ->get();

        $byOrder = [];
        foreach ($lines as $line) {
            $orderId = (string) ($line->amazon_order_id ?? '');
            $currency = strtoupper(trim((string) ($line->currency ?? '')));
            if ($orderId === '' || $currency === '') {
                continue;
            }
            if (!isset($byOrder[$orderId])) {
                $byOrder[$orderId] = [];
            }
            $byOrder[$orderId][$currency] = (float) ($line->fee_total ?? 0);
        }

        $updated = 0;
        $orders = Order::query()
            ->whereIn('amazon_order_id', $targetOrderIds)
            ->get(['id', 'amazon_order_id', 'order_total_currency', 'amazon_fee_total', 'amazon_fee_currency', 'amazon_fee_last_synced_at']);

        foreach ($orders as $order) {
            $currencyMap = $byOrder[$order->amazon_order_id] ?? [];
            if (empty($currencyMap)) {
                if ($order->amazon_fee_total !== null || $order->amazon_fee_currency !== null || $order->amazon_fee_last_synced_at !== null) {
                    $order->amazon_fee_total = null;
                    $order->amazon_fee_currency = null;
                    $order->amazon_fee_last_synced_at = null;
                    $order->save();
                    $updated++;
                }
                continue;
            }
            $preferredCurrency = strtoupper(trim((string) ($order->order_total_currency ?? '')));
            if ($preferredCurrency !== '' && isset($currencyMap[$preferredCurrency])) {
                $currency = $preferredCurrency;
                $amount = $currencyMap[$preferredCurrency];
            } else {
                arsort($currencyMap, SORT_NUMERIC);
                $currency = (string) array_key_first($currencyMap);
                $amount = (float) ($currencyMap[$currency] ?? 0);
                if (count($currencyMap) > 1) {
                    Log::warning('Fee sync picked fallback currency for order', [
                        'order_id' => $order->amazon_order_id,
                        'region' => $region,
                        'currencies' => array_keys($currencyMap),
                        'picked' => $currency,
                    ]);
                }
            }

            $order->amazon_fee_total = round($amount, 2);
            $order->amazon_fee_currency = $currency;
            $order->amazon_fee_last_synced_at = now();
            $order->save();
            $updated++;
        }

        return $updated;
    }

    private function persistFeeLines(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $orderIdsByRegion = [];
        foreach ($rows as $row) {
            $region = strtoupper(trim((string) ($row['region'] ?? '')));
            $orderId = trim((string) ($row['amazon_order_id'] ?? ''));
            if ($region === '' || $orderId === '') {
                continue;
            }
            $orderIdsByRegion[$region][$orderId] = true;
        }

        foreach ($orderIdsByRegion as $region => $orderIdsAssoc) {
            $orderIds = array_keys($orderIdsAssoc);
            foreach (array_chunk($orderIds, 500) as $chunk) {
                DB::table('amazon_order_fee_lines')
                    ->where('region', $region)
                    ->whereIn('amazon_order_id', $chunk)
                    ->delete();
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('amazon_order_fee_lines')->upsert(
                $chunk,
                ['fee_hash'],
                ['region', 'event_type', 'fee_type', 'description', 'amount', 'currency', 'posted_date', 'raw_line', 'updated_at']
            );
        }
    }

    private function normalizePostedDate($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function callSignedJsonWithRetries(callable $callback, string $operation): ?array
    {
        $last = null;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $callback();
                $status = (int) $response->getStatusCode();
                $body = (string) $response->getBody();
                $json = json_decode($body, true);
                $last = ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => $body];

                if ($status < 400) {
                    return $last;
                }
                if ($status !== 429 && $status < 500) {
                    return $last;
                }

                $retryAfter = $response->getHeaderLine('Retry-After');
                sleep(is_numeric($retryAfter) ? max(1, (int) $retryAfter) : 10);
            } catch (\Throwable $e) {
                Log::warning('Fee sync API exception', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $last;
    }

    private function moneyAmount($money): ?float
    {
        if (!is_array($money)) {
            return null;
        }

        $amount = $money['CurrencyAmount'] ?? $money['Amount'] ?? $money['amount'] ?? null;
        return is_numeric($amount) ? (float) $amount : null;
    }

    private function moneyCurrency($money): ?string
    {
        if (!is_array($money)) {
            return null;
        }

        $currency = trim((string) ($money['CurrencyCode'] ?? $money['currencyCode'] ?? ''));
        return $currency !== '' ? strtoupper($currency) : null;
    }

    private function marketplaceIdsForRegion(string $region): array
    {
        $region = strtoupper(trim($region));
        if ($region === 'NA') {
            $countries = ['US', 'CA', 'MX', 'BR'];
        } elseif ($region === 'FE') {
            $countries = ['JP', 'AU', 'SG', 'AE', 'IN', 'SA'];
        } else {
            $countries = ['GB', 'UK', 'AT', 'BE', 'DE', 'DK', 'ES', 'FI', 'FR', 'IE', 'IT', 'LU', 'NL', 'NO', 'PL', 'SE', 'CH', 'TR'];
        }

        return DB::table('marketplaces')
            ->whereIn(DB::raw("UPPER(COALESCE(country_code, ''))"), $countries)
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
    }

    private function officialHostForRegion(string $region): ?string
    {
        return match (strtoupper(trim($region))) {
            'NA' => 'https://sellingpartnerapi-na.amazon.com',
            'EU' => 'https://sellingpartnerapi-eu.amazon.com',
            'FE' => 'https://sellingpartnerapi-fe.amazon.com',
            default => null,
        };
    }

    private function extractOrderIdFromTransaction(array $txn): string
    {
        $related = (array) ($txn['relatedIdentifiers'] ?? []);
        foreach ($related as $identifier) {
            if (!is_array($identifier)) {
                continue;
            }
            $name = strtoupper(trim((string) ($identifier['relatedIdentifierName'] ?? '')));
            if ($name !== 'ORDER_ID') {
                continue;
            }
            $value = trim((string) ($identifier['relatedIdentifierValue'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function canonicalTransactionId(array $txn): string
    {
        $transactionId = trim((string) ($txn['transactionId'] ?? ''));
        $related = (array) ($txn['relatedIdentifiers'] ?? []);
        foreach ($related as $identifier) {
            if (!is_array($identifier)) {
                continue;
            }
            $name = strtoupper(trim((string) ($identifier['relatedIdentifierName'] ?? '')));
            if ($name !== 'RELEASE_TRANSACTION_ID') {
                continue;
            }
            $value = trim((string) ($identifier['relatedIdentifierValue'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $transactionId !== '' ? $transactionId : sha1(json_encode($txn));
    }

    private function extractNetFeeRowsFromTransaction(array $txn, string $orderId, string $region): array
    {
        $postedDate = $this->normalizePostedDate($txn['postedDate'] ?? $txn['transactionDate'] ?? null);
        $transactionType = trim((string) ($txn['transactionType'] ?? 'Transaction'));
        $transactionId = trim((string) ($txn['transactionId'] ?? ''));
        $canonicalTransactionId = $this->canonicalTransactionId($txn);
        $transactionStatus = trim((string) ($txn['transactionStatus'] ?? ''));
        $breakdownSources = $this->transactionBreakdownSources($txn);
        if (empty($breakdownSources)) {
            return [];
        }

        $rows = [];
        foreach ($breakdownSources as $source) {
            $sourcePath = (string) ($source['path'] ?? 'transaction.breakdowns');
            $breakdowns = (array) ($source['breakdowns'] ?? []);
            $candidateNodes = [];
            foreach ($breakdowns as $breakdown) {
                if (!is_array($breakdown)) {
                    continue;
                }
                $this->collectCandidateFeeBreakdowns($breakdown, $candidateNodes);
            }

            foreach ($candidateNodes as $index => $breakdown) {
                $type = trim((string) ($breakdown['breakdownType'] ?? 'AmazonFee'));
                $grossAmount = $this->moneyAmount($breakdown['breakdownAmount'] ?? null);
                $grossCurrency = $this->moneyCurrency($breakdown['breakdownAmount'] ?? null);

                $baseLeaves = [];
                $taxLeaves = [];
                $this->collectBreakdownLeaves($breakdown, $baseLeaves, $taxLeaves);

                $netAmount = null;
                $currency = $grossCurrency;
                if (!empty($baseLeaves)) {
                    $netAmount = 0.0;
                    foreach ($baseLeaves as $leaf) {
                        $leafAmount = (float) ($leaf['amount'] ?? 0);
                        $leafCurrency = (string) ($leaf['currency'] ?? '');
                        if ($leafCurrency === '') {
                            continue;
                        }
                        if ($currency === null || $currency === '') {
                            $currency = $leafCurrency;
                        }
                        if ($leafCurrency !== $currency) {
                            continue;
                        }
                        $netAmount += $leafAmount;
                    }
                } elseif ($grossAmount !== null && $grossCurrency !== null) {
                    $netAmount = (float) $grossAmount;
                    $taxTotal = 0.0;
                    foreach ($taxLeaves as $leaf) {
                        $leafCurrency = (string) ($leaf['currency'] ?? '');
                        if ($leafCurrency !== $grossCurrency) {
                            continue;
                        }
                        $taxTotal += (float) ($leaf['amount'] ?? 0);
                    }
                    $netAmount -= $taxTotal;
                }

                $currency = strtoupper(trim((string) $currency));
                if ($netAmount === null || $currency === '' || round((float) $netAmount, 2) == 0.0) {
                    continue;
                }

                $hashBase = implode('|', [
                    $orderId,
                    strtoupper(trim($region)),
                    self::EVENT_TYPE_V20240619,
                    $canonicalTransactionId,
                    $transactionType,
                    $sourcePath,
                    $type,
                    number_format((float) $netAmount, 2, '.', ''),
                    $currency,
                    (string) $index,
                ]);

                $rows[] = [
                    'fee_hash' => sha1($hashBase),
                    'amazon_order_id' => $orderId,
                    'region' => strtoupper(trim($region)),
                    'event_type' => self::EVENT_TYPE_V20240619,
                    'fee_type' => $type !== '' ? $type : null,
                    'description' => $type !== '' ? $type : 'AmazonFee',
                    'amount' => round((float) $netAmount, 2),
                    'currency' => $currency,
                    'posted_date' => $postedDate,
                    'raw_line' => json_encode([
                        'transaction_id' => $transactionId,
                        'canonical_transaction_id' => $canonicalTransactionId,
                        'transaction_status' => $transactionStatus,
                        'transaction_type' => $transactionType,
                        'source_path' => $sourcePath,
                        'breakdown' => $breakdown,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        return $rows;
    }

    private function transactionBreakdownSources(array $txn): array
    {
        $sources = [];

        $top = (array) ($txn['breakdowns'] ?? []);
        if (!empty($top)) {
            $sources[] = ['path' => 'transaction.breakdowns', 'breakdowns' => $top];
        }

        if (!empty($sources)) {
            return $sources;
        }

        foreach ((array) ($txn['items'] ?? []) as $itemIndex => $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemBreakdowns = (array) ($item['breakdowns'] ?? []);
            if (!empty($itemBreakdowns)) {
                $sources[] = [
                    'path' => 'transaction.items[' . $itemIndex . '].breakdowns',
                    'breakdowns' => $itemBreakdowns,
                ];
            }
        }

        return $sources;
    }

    private function collectCandidateFeeBreakdowns(array $node, array &$out): void
    {
        $type = strtoupper(trim((string) ($node['breakdownType'] ?? '')));
        $children = (array) ($node['breakdowns'] ?? []);

        if ($type === 'AMAZONFEES' && !empty($children)) {
            foreach ($children as $child) {
                if (is_array($child)) {
                    $this->collectCandidateFeeBreakdowns($child, $out);
                }
            }
            return;
        }

        if ($this->isFeeBreakdownType($type)) {
            $out[] = $node;
            return;
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $this->collectCandidateFeeBreakdowns($child, $out);
            }
        }
    }

    private function isFeeBreakdownType(string $type): bool
    {
        if ($type === '') {
            return false;
        }

        if (str_contains($type, 'FEE')) {
            return true;
        }

        return in_array($type, [
            'COMMISSION',
            'VARIABLECLOSINGFEE',
            'FIXEDCLOSINGFEE',
            'DIGITALSERVICESFEE',
            'PERUNITFULFILLMENTFEE',
        ], true);
    }

    private function collectBreakdownLeaves(array $node, array &$baseLeaves, array &$taxLeaves): void
    {
        $type = strtoupper(trim((string) ($node['breakdownType'] ?? '')));
        $amount = $this->moneyAmount($node['breakdownAmount'] ?? null);
        $currency = $this->moneyCurrency($node['breakdownAmount'] ?? null);
        $children = (array) ($node['breakdowns'] ?? []);
        $hasChildren = !empty($children);

        if (!$hasChildren && $amount !== null && $currency !== null) {
            if ($type === 'BASE') {
                $baseLeaves[] = ['amount' => (float) $amount, 'currency' => (string) $currency];
            } elseif ($type === 'TAX') {
                $taxLeaves[] = ['amount' => (float) $amount, 'currency' => (string) $currency];
            }
            return;
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $this->collectBreakdownLeaves($child, $baseLeaves, $taxLeaves);
            }
        }
    }
}
