<?php

namespace App\Services;

use App\Integrations\Amazon\SpApi\FinancesAdapter;
use App\Integrations\Amazon\SpApi\LegacySpApiClientFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderFinancialEventsSummaryService
{
    private const CACHE_KEY_VERSION = 'v5';
    private const CACHE_MINUTES = 10;
    private const MAX_PAGES = 6;
    private const MAX_RECENT_ROWS = 10;
    private const MAX_BREAKDOWN_ROWS = 8;

    public function __construct(
        private readonly ?FinancesAdapter $financesAdapter = null
    ) {}

    public function summarizeOrder(string $orderId, ?string $marketplaceId = null): array
    {
        $normalizedOrderId = trim($orderId);
        if ($normalizedOrderId === '') {
            return $this->emptySummary('Order ID is required.');
        }

        $cacheKey = 'orders:financial-events-summary:' . self::CACHE_KEY_VERSION . ':' . sha1($normalizedOrderId . '|' . strtoupper((string) $marketplaceId));

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_MINUTES), function () use ($normalizedOrderId, $marketplaceId) {
            return $this->fetchAndSummarize($normalizedOrderId, $marketplaceId);
        });
    }

    private function fetchAndSummarize(string $orderId, ?string $marketplaceId): array
    {
        $adapter = $this->financesAdapter ?? new FinancesAdapter(new LegacySpApiClientFactory());
        $region = $this->resolveRegionForMarketplace($marketplaceId);

        try {
            $transactions = [];
            $pagesFetched = 0;
            $nextToken = null;
            $truncated = false;

            do {
                $response = $adapter->listTransactionsByOrderId(
                    $orderId,
                    $marketplaceId,
                    $nextToken,
                    $region
                );
                if ($response->status() >= 400) {
                    return $this->emptySummary(
                        'Could not load financial events from Amazon SP-API.',
                        [
                            'http_status' => $response->status(),
                            'marketplace_id' => $marketplaceId,
                            'region' => $region,
                        ]
                    );
                }

                $payload = (array) (($response->json('payload') ?? null) ?: []);
                $pageTransactions = (array) ($payload['transactions'] ?? []);
                foreach ($pageTransactions as $transaction) {
                    if (is_array($transaction)) {
                        $transactions[] = $transaction;
                    }
                }

                $nextToken = trim((string) ($payload['nextToken'] ?? ''));
                $nextToken = $nextToken !== '' ? $nextToken : null;
                $pagesFetched++;

                if ($nextToken !== null && $pagesFetched >= self::MAX_PAGES) {
                    $truncated = true;
                    $nextToken = null;
                }
            } while ($nextToken !== null);

            return $this->buildSummary($orderId, $marketplaceId, $transactions, $pagesFetched, $truncated);
        } catch (\Throwable $e) {
            Log::warning('order financial summary fetch failed', [
                'order_id' => $orderId,
                'marketplace_id' => $marketplaceId,
                'region' => $region,
                'error' => $e->getMessage(),
            ]);

            return $this->emptySummary('Could not load financial events from Amazon SP-API.');
        }
    }

    private function buildSummary(
        string $orderId,
        ?string $marketplaceId,
        array $transactions,
        int $pagesFetched,
        bool $truncated
    ): array {
        $statusCounts = [];
        $typeCounts = [];
        $currencyTotals = [];
        $recentRows = [];
        $postedFrom = null;
        $postedTo = null;

        foreach ($transactions as $transaction) {
            $status = strtoupper(trim((string) ($transaction['transactionStatus'] ?? 'UNKNOWN')));
            $type = trim((string) ($transaction['transactionType'] ?? 'Unknown'));
            $description = trim((string) ($transaction['description'] ?? ''));
            $postedDate = $this->parseDate($transaction['postedDate'] ?? null);
            $amount = $this->toFloat(data_get($transaction, 'totalAmount.amount'));
            if ($amount === null) {
                $amount = $this->toFloat(data_get($transaction, 'totalAmount.currencyAmount'));
            }
            $currency = strtoupper(trim((string) data_get($transaction, 'totalAmount.currencyCode')));
            $maturityDate = $this->extractMaturityDate($transaction);
            $deferralReason = $this->extractDeferralReason($transaction);
            $currencyAmountBreakdown = $this->extractCurrencyAmountBreakdown($transaction, $currency);

            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;

            if ($currency !== '' && $amount !== null) {
                $currencyTotals[$currency] = round(($currencyTotals[$currency] ?? 0.0) + $amount, 4);
            }

            if ($postedDate) {
                if ($postedFrom === null || $postedDate->lt($postedFrom)) {
                    $postedFrom = $postedDate->copy();
                }
                if ($postedTo === null || $postedDate->gt($postedTo)) {
                    $postedTo = $postedDate->copy();
                }
            }

            $recentRows[] = [
                'transaction_id' => (string) ($transaction['transactionId'] ?? ''),
                'status' => $status,
                'type' => $type,
                'description' => $description !== '' ? $description : null,
                'amount' => $amount,
                'currency' => $currency !== '' ? $currency : null,
                'value' => ($amount !== null && $currency !== '')
                    ? number_format((float) $amount, 2, '.', '') . ' ' . $currency
                    : null,
                'posted_date' => $postedDate?->toIso8601String(),
                'deferral_reason' => $deferralReason,
                'maturity_date' => $maturityDate,
                'currency_amount_breakdown' => $currencyAmountBreakdown,
                'raw' => $transaction,
            ];
        }

        uasort($statusCounts, fn (int $a, int $b) => $b <=> $a);
        uasort($typeCounts, fn (int $a, int $b) => $b <=> $a);
        usort($recentRows, function (array $a, array $b) {
            $aTs = isset($a['posted_date']) ? strtotime((string) $a['posted_date']) : 0;
            $bTs = isset($b['posted_date']) ? strtotime((string) $b['posted_date']) : 0;
            return $bTs <=> $aTs;
        });
        $recentRows = array_slice($recentRows, 0, self::MAX_RECENT_ROWS);

        return [
            'source' => 'sp_api.finances.v2024_06_19',
            'order_id' => $orderId,
            'marketplace_id' => $marketplaceId,
            'fetched_at' => now()->toIso8601String(),
            'error' => null,
            'total_transactions' => count($transactions),
            'pages_fetched' => $pagesFetched,
            'truncated' => $truncated,
            'posted_from' => $postedFrom?->toIso8601String(),
            'posted_to' => $postedTo?->toIso8601String(),
            'status_counts' => $statusCounts,
            'type_counts' => $typeCounts,
            'currency_totals' => $currencyTotals,
            'recent_transactions' => $recentRows,
        ];
    }

    private function emptySummary(string $error, array $context = []): array
    {
        return [
            'source' => 'sp_api.finances.v2024_06_19',
            'fetched_at' => now()->toIso8601String(),
            'error' => $error,
            'total_transactions' => 0,
            'pages_fetched' => 0,
            'truncated' => false,
            'posted_from' => null,
            'posted_to' => null,
            'status_counts' => [],
            'type_counts' => [],
            'currency_totals' => [],
            'recent_transactions' => [],
            'context' => $context,
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function toFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function extractMaturityDate(array $transaction): ?string
    {
        $contexts = [];
        if (is_array($transaction['contexts'] ?? null)) {
            $contexts = array_merge($contexts, (array) $transaction['contexts']);
        }

        $items = $transaction['items'] ?? null;
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (is_array($item['contexts'] ?? null)) {
                    $contexts = array_merge($contexts, (array) $item['contexts']);
                }
            }
        }

        foreach ($contexts as $context) {
            if (!is_array($context)) {
                continue;
            }

            $candidate = data_get($context, 'deferredContext.maturityDate')
                ?? data_get($context, 'deferred.maturityDate')
                ?? data_get($context, 'paymentsContext.maturityDate')
                ?? data_get($context, 'paymentContext.maturityDate')
                ?? data_get($context, 'maturityDate');

            if (is_string($candidate) && trim($candidate) !== '') {
                $date = $this->parseDate($candidate);
                return $date?->toIso8601String() ?? trim($candidate);
            }
        }

        return $this->findMaturityDateRecursive($transaction);
    }

    private function findMaturityDateRecursive(mixed $node): ?string
    {
        if (!is_array($node)) {
            return null;
        }

        $direct = $node['maturityDate'] ?? null;
        if (is_string($direct) && trim($direct) !== '') {
            $date = $this->parseDate($direct);
            return $date?->toIso8601String() ?? trim($direct);
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $found = $this->findMaturityDateRecursive($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function extractDeferralReason(array $transaction): ?string
    {
        $contexts = [];
        if (is_array($transaction['contexts'] ?? null)) {
            $contexts = array_merge($contexts, (array) $transaction['contexts']);
        }

        $items = $transaction['items'] ?? null;
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (is_array($item['contexts'] ?? null)) {
                    $contexts = array_merge($contexts, (array) $item['contexts']);
                }
            }
        }

        foreach ($contexts as $context) {
            if (!is_array($context)) {
                continue;
            }

            $candidate = data_get($context, 'deferredContext.deferralReason')
                ?? data_get($context, 'deferred.deferralReason')
                ?? data_get($context, 'paymentsContext.deferralReason')
                ?? data_get($context, 'paymentContext.deferralReason')
                ?? data_get($context, 'deferralReason');

            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return $this->findDeferralReasonRecursive($transaction);
    }

    private function findDeferralReasonRecursive(mixed $node): ?string
    {
        if (!is_array($node)) {
            return null;
        }

        $direct = $node['deferralReason'] ?? null;
        if (is_string($direct) && trim($direct) !== '') {
            return trim($direct);
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $found = $this->findDeferralReasonRecursive($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function extractCurrencyAmountBreakdown(array $transaction, ?string $transactionCurrency): array
    {
        $rows = [];
        $topBreakdowns = $transaction['breakdowns'] ?? null;
        if (is_array($topBreakdowns)) {
            foreach ($topBreakdowns as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $amount = $this->extractCurrencyAmount($node['breakdownAmount'] ?? null);
                if ($amount === null) {
                    continue;
                }

                $currency = strtoupper(trim((string) data_get($node, 'breakdownAmount.currencyCode')));
                if ($currency === '') {
                    $currency = strtoupper(trim((string) ($transactionCurrency ?? '')));
                }

                $rows[] = [
                    'label' => trim((string) ($node['breakdownType'] ?? 'Breakdown')),
                    'amount' => round($amount, 4),
                    'currency' => $currency !== '' ? $currency : null,
                ];
            }
        }

        if (empty($rows)) {
            return [];
        }

        $rows = array_slice($rows, 0, self::MAX_BREAKDOWN_ROWS);

        $transactionTotal = $this->extractCurrencyAmount($transaction['totalAmount'] ?? null);
        if ($transactionTotal !== null) {
            $rows[] = [
                'label' => 'Transaction Total',
                'amount' => round($transactionTotal, 4),
                'currency' => strtoupper(trim((string) ($transactionCurrency ?? ''))) ?: null,
            ];
        }

        return $rows;
    }

    private function extractCurrencyAmount(mixed $moneyNode): ?float
    {
        if (!is_array($moneyNode)) {
            return null;
        }

        $amount = $this->toFloat($moneyNode['amount'] ?? null);
        if ($amount !== null) {
            return $amount;
        }

        return $this->toFloat($moneyNode['currencyAmount'] ?? null);
    }

    private function resolveRegionForMarketplace(?string $marketplaceId): ?string
    {
        $marketplaceId = trim((string) $marketplaceId);
        if ($marketplaceId === '') {
            return null;
        }

        $countryCode = DB::table('marketplaces')
            ->where('id', $marketplaceId)
            ->value('country_code');

        if (!is_string($countryCode) || trim($countryCode) === '') {
            $countryCode = (string) data_get(config('marketplaces'), $marketplaceId . '.country', '');
        }

        $countryCode = strtoupper(trim((string) $countryCode));
        if ($countryCode === '') {
            return null;
        }

        if (in_array($countryCode, ['US', 'CA', 'MX', 'BR'], true)) {
            return 'NA';
        }

        if (in_array($countryCode, ['JP', 'AU', 'SG', 'IN', 'AE', 'SA', 'TR', 'EG', 'ZA'], true)) {
            return 'FE';
        }

        return 'EU';
    }
}
