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

class AmazonOrderFeeSyncV2Service
{
    private const MAX_ATTEMPTS = 6;

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
            'transactions' => 0,
            'orders_touched' => 0,
            'orders_updated' => 0,
        ];

        foreach ($regions as $configuredRegion) {
            $config = $regionService->spApiConfig($configuredRegion);
            [$rows, $transactions] = $this->collectRowsForRegion($configuredRegion, $config, $from, $to);
            $summary['transactions'] += $transactions;

            $touchedOrderIds = array_values(array_unique(array_filter(array_map(
                static fn ($row) => trim((string) ($row['amazon_order_id'] ?? '')),
                $rows
            ))));
            $summary['orders_touched'] += count($touchedOrderIds);

            $this->replaceRowsForOrders($configuredRegion, $touchedOrderIds, $rows);
            $summary['orders_updated'] += $this->recalculateOrders($configuredRegion, $touchedOrderIds);
        }

        return $summary;
    }

    private function collectRowsForRegion(string $region, array $config, Carbon $from, Carbon $to): array
    {
        if (!class_exists(LWAAuthorizationCredentials::class) || !class_exists(OfficialSpApiConfiguration::class)) {
            return [[], 0];
        }

        $host = $this->hostForRegion($region);
        if ($host === null) {
            return [[], 0];
        }

        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $refreshToken = trim((string) ($config['refresh_token'] ?? ''));
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            return [[], 0];
        }

        $lwa = new LWAAuthorizationCredentials([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'refreshToken' => $refreshToken,
            'endpoint' => 'https://api.amazon.com/auth/o2/token',
        ]);
        $spConfig = new OfficialSpApiConfiguration([], $lwa);
        $spConfig->setHost($host);

        $http = new GuzzleClient();
        $url = $host . '/finances/2024-06-19/transactions';

        $marketplaceIds = array_values(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            (array) ($config['marketplace_ids'] ?? [])
        )));
        $marketplaceIds[] = null; // safety pass

        $rows = [];
        $transactionsSeen = 0;
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
                    fn () => $http->send($spConfig->sign($request)),
                    'fees-v2.listTransactions'
                );
                if (!$response || ($response['status'] ?? 500) >= 400) {
                    break;
                }

                $payload = (array) (($response['json']['payload'] ?? null) ?: []);
                $transactions = (array) ($payload['transactions'] ?? []);
                $transactionsSeen += count($transactions);

                foreach ($transactions as $txn) {
                    if (!is_array($txn)) {
                        continue;
                    }
                    $orderId = $this->extractOrderId($txn);
                    if ($orderId === '') {
                        continue;
                    }
                    foreach ($this->extractFeeRows($txn, $orderId, $region) as $row) {
                        $rows[] = $row;
                    }
                }

                $nextToken = trim((string) ($payload['nextToken'] ?? ''));
                if ($nextToken === '') {
                    $nextToken = null;
                }
            } while ($nextToken !== null);
        }

        return [$rows, $transactionsSeen];
    }

    private function replaceRowsForOrders(string $region, array $orderIds, array $rows): void
    {
        if (empty($orderIds)) {
            return;
        }

        foreach (array_chunk($orderIds, 500) as $chunk) {
            DB::table('amazon_order_fee_lines_v2')
                ->where('region', strtoupper(trim($region)))
                ->whereIn('amazon_order_id', $chunk)
                ->delete();
        }

        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('amazon_order_fee_lines_v2')->upsert(
                $chunk,
                ['line_hash'],
                [
                    'marketplace_id',
                    'transaction_id',
                    'canonical_transaction_id',
                    'transaction_status',
                    'transaction_type',
                    'posted_date',
                    'fee_type',
                    'description',
                    'gross_amount',
                    'base_amount',
                    'tax_amount',
                    'net_ex_tax_amount',
                    'currency',
                    'raw_line',
                    'updated_at',
                ]
            );
        }
    }

    private function recalculateOrders(string $region, array $orderIds): int
    {
        if (empty($orderIds)) {
            return 0;
        }

        $totals = DB::table('amazon_order_fee_lines_v2')
            ->select('amazon_order_id', 'currency', DB::raw('SUM(COALESCE(net_ex_tax_amount, 0)) as fee_total'))
            ->where('region', strtoupper(trim($region)))
            ->whereIn('amazon_order_id', $orderIds)
            ->groupBy('amazon_order_id', 'currency')
            ->get();

        $byOrder = [];
        foreach ($totals as $line) {
            $orderId = (string) ($line->amazon_order_id ?? '');
            $currency = strtoupper(trim((string) ($line->currency ?? '')));
            if ($orderId === '' || $currency === '') {
                continue;
            }
            $byOrder[$orderId][$currency] = (float) ($line->fee_total ?? 0);
        }

        $updated = 0;
        $orders = Order::query()
            ->whereIn('amazon_order_id', $orderIds)
            ->get(['id', 'amazon_order_id', 'order_total_currency', 'amazon_fee_total_v2', 'amazon_fee_currency_v2']);

        foreach ($orders as $order) {
            $currencyMap = $byOrder[$order->amazon_order_id] ?? [];
            if (empty($currencyMap)) {
                if ($order->amazon_fee_total_v2 !== null || $order->amazon_fee_currency_v2 !== null) {
                    $order->amazon_fee_total_v2 = null;
                    $order->amazon_fee_currency_v2 = null;
                    $order->amazon_fee_last_synced_at_v2 = null;
                    $order->save();
                    $updated++;
                }
                continue;
            }

            $preferred = strtoupper(trim((string) ($order->order_total_currency ?? '')));
            if ($preferred !== '' && isset($currencyMap[$preferred])) {
                $currency = $preferred;
                $amount = $currencyMap[$preferred];
            } else {
                arsort($currencyMap, SORT_NUMERIC);
                $currency = (string) array_key_first($currencyMap);
                $amount = (float) ($currencyMap[$currency] ?? 0);
            }

            $order->amazon_fee_total_v2 = round($amount, 2);
            $order->amazon_fee_currency_v2 = $currency;
            $order->amazon_fee_last_synced_at_v2 = now();
            $order->save();
            $updated++;
        }

        return $updated;
    }

    private function extractOrderId(array $txn): string
    {
        $relatedIdentifiers = $this->firstArray($txn, ['relatedIdentifiers', 'RelatedIdentifiers']);
        foreach ($relatedIdentifiers as $identifier) {
            if (!is_array($identifier)) {
                continue;
            }
            $name = strtoupper(trim((string) $this->firstValue($identifier, ['relatedIdentifierName', 'RelatedIdentifierName'])));
            if ($name !== 'ORDER_ID') {
                continue;
            }
            $value = trim((string) $this->firstValue($identifier, ['relatedIdentifierValue', 'RelatedIdentifierValue']));
            if ($value !== '') {
                return $value;
            }
        }

        // Fallback: walk full payload for ORDER_ID identifier pairs.
        $fallback = $this->findOrderIdRecursive($txn);
        if ($fallback !== '') {
            return $fallback;
        }

        return '';
    }

    private function findOrderIdRecursive($node): string
    {
        if (!is_array($node)) {
            return '';
        }

        $name = strtoupper(trim((string) $this->firstValue($node, ['relatedIdentifierName', 'RelatedIdentifierName'])));
        if ($name === 'ORDER_ID') {
            $value = trim((string) $this->firstValue($node, ['relatedIdentifierValue', 'RelatedIdentifierValue']));
            if ($value !== '') {
                return $value;
            }
        }

        $directOrder = trim((string) $this->firstValue($node, ['orderId', 'OrderId', 'amazonOrderId', 'AmazonOrderId']));
        if ($directOrder !== '') {
            return $directOrder;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $found = $this->findOrderIdRecursive($value);
                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }

    private function extractFeeRows(array $txn, string $orderId, string $region): array
    {
        $marketplaceMetadata = $this->firstArray($txn, ['sellingPartnerMetadata', 'SellingPartnerMetadata']);
        $marketplaceId = trim((string) $this->firstValue($marketplaceMetadata, ['marketplaceId', 'MarketplaceId']));
        $transactionId = trim((string) $this->firstValue($txn, ['transactionId', 'TransactionId']));
        $canonicalTransactionId = $this->canonicalTransactionId($txn);
        $transactionStatus = trim((string) $this->firstValue($txn, ['transactionStatus', 'TransactionStatus']));
        $transactionType = trim((string) $this->firstValue($txn, ['transactionType', 'TransactionType']));
        $postedDate = $this->normalizePostedDate($this->firstValue($txn, ['postedDate', 'PostedDate', 'transactionDate', 'TransactionDate']));

        $sources = $this->transactionBreakdownSources($txn);
        $rows = [];
        foreach ($sources as $source) {
            $sourcePath = (string) ($source['path'] ?? 'transaction.breakdowns');
            foreach ((array) ($source['breakdowns'] ?? []) as $breakdown) {
                if (!is_array($breakdown)) {
                    continue;
                }
                $candidateNodes = [];
                $this->collectCandidateFeeBreakdowns($breakdown, $candidateNodes);

                foreach ($candidateNodes as $index => $feeNode) {
                    $type = trim((string) ($feeNode['breakdownType'] ?? 'AmazonFee'));
                    $gross = $this->moneyAmount($feeNode['breakdownAmount'] ?? null);
                    $currency = $this->moneyCurrency($feeNode['breakdownAmount'] ?? null);
                    if ($currency === null || $gross === null) {
                        continue;
                    }

                    $baseLeaves = [];
                    $taxLeaves = [];
                    $this->collectBreakdownLeaves($feeNode, $baseLeaves, $taxLeaves);
                    $base = $this->sumLeavesByCurrency($baseLeaves, $currency);
                    $tax = $this->sumLeavesByCurrency($taxLeaves, $currency);
                    $net = $base ?? ((float) $gross - ($tax ?? 0.0));

                    $hashBase = implode('|', [
                        $orderId,
                        strtoupper(trim($region)),
                        $canonicalTransactionId,
                        $sourcePath,
                        $type,
                        number_format((float) $net, 4, '.', ''),
                        $currency,
                        (string) $index,
                    ]);

                    $rows[] = [
                        'line_hash' => sha1($hashBase),
                        'amazon_order_id' => $orderId,
                        'region' => strtoupper(trim($region)),
                        'marketplace_id' => $marketplaceId !== '' ? $marketplaceId : null,
                        'transaction_id' => $transactionId !== '' ? $transactionId : null,
                        'canonical_transaction_id' => $canonicalTransactionId !== '' ? $canonicalTransactionId : null,
                        'transaction_status' => $transactionStatus !== '' ? $transactionStatus : null,
                        'transaction_type' => $transactionType !== '' ? $transactionType : null,
                        'posted_date' => $postedDate,
                        'fee_type' => $type !== '' ? $type : null,
                        'description' => $type !== '' ? $type : 'AmazonFee',
                        'gross_amount' => round((float) $gross, 4),
                        'base_amount' => $base !== null ? round((float) $base, 4) : null,
                        'tax_amount' => $tax !== null ? round((float) $tax, 4) : null,
                        'net_ex_tax_amount' => round((float) $net, 4),
                        'currency' => $currency,
                        'raw_line' => json_encode([
                            'source_path' => $sourcePath,
                            'breakdown' => $feeNode,
                            'transaction' => [
                                'transactionId' => $transactionId,
                                'canonicalTransactionId' => $canonicalTransactionId,
                                'transactionStatus' => $transactionStatus,
                                'transactionType' => $transactionType,
                            ],
                        ]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        return $rows;
    }

    private function canonicalTransactionId(array $txn): string
    {
        $transactionId = trim((string) $this->firstValue($txn, ['transactionId', 'TransactionId']));
        $relatedIdentifiers = $this->firstArray($txn, ['relatedIdentifiers', 'RelatedIdentifiers']);
        foreach ($relatedIdentifiers as $identifier) {
            if (!is_array($identifier)) {
                continue;
            }
            $name = strtoupper(trim((string) $this->firstValue($identifier, ['relatedIdentifierName', 'RelatedIdentifierName'])));
            if ($name !== 'RELEASE_TRANSACTION_ID' && $name !== 'DEFERRED_TRANSACTION_ID') {
                continue;
            }
            $value = trim((string) $this->firstValue($identifier, ['relatedIdentifierValue', 'RelatedIdentifierValue']));
            if ($value !== '') {
                return $value;
            }
        }

        return $transactionId !== '' ? $transactionId : sha1(json_encode($txn));
    }

    private function transactionBreakdownSources(array $txn): array
    {
        $sources = [];
        $top = $this->firstArray($txn, ['breakdowns', 'Breakdowns']);
        if (!empty($top)) {
            $sources[] = ['path' => 'transaction.breakdowns', 'breakdowns' => $top];
        }
        $items = $this->firstArray($txn, ['items', 'Items']);
        foreach ($items as $itemIndex => $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemBreakdowns = $this->firstArray($item, ['breakdowns', 'Breakdowns']);
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
        $type = strtoupper(trim((string) $this->firstValue($node, ['breakdownType', 'BreakdownType'])));
        $children = $this->firstArray($node, ['breakdowns', 'Breakdowns']);

        if ($type === 'AMAZONFEES' && !empty($children)) {
            foreach ($children as $child) {
                if (is_array($child)) {
                    $this->collectCandidateFeeBreakdowns($child, $out);
                }
            }
            return;
        }

        if ($this->isFeeType($type)) {
            $out[] = $node;
            return;
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                $this->collectCandidateFeeBreakdowns($child, $out);
            }
        }
    }

    private function isFeeType(string $type): bool
    {
        if ($type === '') {
            return false;
        }

        if (str_contains($type, 'FEE')) {
            return true;
        }

        return in_array($type, ['COMMISSION', 'VARIABLECLOSINGFEE', 'FIXEDCLOSINGFEE'], true);
    }

    private function collectBreakdownLeaves(array $node, array &$baseLeaves, array &$taxLeaves): void
    {
        $type = strtoupper(trim((string) $this->firstValue($node, ['breakdownType', 'BreakdownType'])));
        $amountNode = $this->firstArray($node, ['breakdownAmount', 'BreakdownAmount']);
        $amount = $this->moneyAmount($amountNode);
        $currency = $this->moneyCurrency($amountNode);
        $children = $this->firstArray($node, ['breakdowns', 'Breakdowns']);
        if (empty($children) && $amount !== null && $currency !== null) {
            if ($type === 'BASE') {
                $baseLeaves[] = ['amount' => (float) $amount, 'currency' => $currency];
            } elseif ($type === 'TAX') {
                $taxLeaves[] = ['amount' => (float) $amount, 'currency' => $currency];
            }
            return;
        }
        foreach ($children as $child) {
            if (is_array($child)) {
                $this->collectBreakdownLeaves($child, $baseLeaves, $taxLeaves);
            }
        }
    }

    private function sumLeavesByCurrency(array $leaves, string $currency): ?float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($leaves as $leaf) {
            if (strtoupper((string) ($leaf['currency'] ?? '')) !== strtoupper($currency)) {
                continue;
            }
            $sum += (float) ($leaf['amount'] ?? 0);
            $count++;
        }

        return $count > 0 ? $sum : null;
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

    private function hostForRegion(string $region): ?string
    {
        return match (strtoupper(trim($region))) {
            'NA' => 'https://sellingpartnerapi-na.amazon.com',
            'EU' => 'https://sellingpartnerapi-eu.amazon.com',
            'FE' => 'https://sellingpartnerapi-fe.amazon.com',
            default => null,
        };
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
                Log::warning('fees-v2 API exception', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $last;
    }

    private function firstArray(array $source, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    private function firstValue(array $source, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                return $source[$key];
            }
        }

        return null;
    }
}
