<?php

namespace App\Services;

use App\Integrations\Amazon\SpApi\FinancesAdapter;
use App\Integrations\Amazon\SpApi\SpApiClientFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OrderFinancialEventsSummaryService
{
    private const CACHE_MINUTES = 10;
    private const MAX_PAGES = 6;
    private const MAX_RECENT_ROWS = 10;

    public function __construct(
        private readonly ?FinancesAdapter $financesAdapter = null
    ) {}

    public function summarizeOrder(string $orderId, ?string $marketplaceId = null): array
    {
        $normalizedOrderId = trim($orderId);
        if ($normalizedOrderId === '') {
            return $this->emptySummary('Order ID is required.');
        }

        $cacheKey = 'orders:financial-events-summary:' . sha1($normalizedOrderId . '|' . strtoupper((string) $marketplaceId));

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_MINUTES), function () use ($normalizedOrderId, $marketplaceId) {
            return $this->fetchAndSummarize($normalizedOrderId, $marketplaceId);
        });
    }

    private function fetchAndSummarize(string $orderId, ?string $marketplaceId): array
    {
        $adapter = $this->financesAdapter ?? new FinancesAdapter(new SpApiClientFactory());

        try {
            $transactions = [];
            $pagesFetched = 0;
            $nextToken = null;
            $truncated = false;

            do {
                $response = $adapter->listTransactionsByOrderId($orderId, $marketplaceId, $nextToken);
                if ($response->status() >= 400) {
                    return $this->emptySummary(
                        'Could not load financial events from Amazon SP-API.',
                        [
                            'http_status' => $response->status(),
                            'marketplace_id' => $marketplaceId,
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
            $currency = strtoupper(trim((string) data_get($transaction, 'totalAmount.currencyCode')));

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
                'posted_date' => $postedDate?->toIso8601String(),
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
}
