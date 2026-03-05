<?php

namespace App\Services;

use App\Integrations\Amazon\SpApi\FinancesAdapter;
use App\Integrations\Amazon\SpApi\SpApiClientFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CashflowProjectionService
{
    private const OUTSTANDING_LOOKBACK_DAYS = 30;
    private const OUTSTANDING_MAX_PAGES_PER_MARKETPLACE = 10;

    public function __construct(
        private readonly ?FinancesAdapter $financesAdapter = null,
        private readonly ?RegionConfigService $regionConfigService = null
    ) {}

    public function forDay(Carbon $dayUtc, array $filters = []): array
    {
        $start = $dayUtc->copy()->utc()->startOfDay();
        $end = $dayUtc->copy()->utc()->endOfDay();
        $dateColumn = $this->cashflowDateColumn();

        $rows = $this->baseQuery($filters)
            ->whereBetween($dateColumn, [$start, $end])
            ->get([
                'marketplace_id',
                'currency',
                'transaction_status',
                'net_ex_tax_amount',
            ]);

        return [
            'view' => 'day',
            'start_utc' => $start->toIso8601String(),
            'end_utc' => $end->toIso8601String(),
            'buckets' => $this->summarizeBuckets($rows->all()),
        ];
    }

    public function forWeek(Carbon $anchorUtc, array $filters = []): array
    {
        $start = $anchorUtc->copy()->utc()->startOfWeek(Carbon::MONDAY);
        $end = $anchorUtc->copy()->utc()->endOfWeek(Carbon::SUNDAY);
        $dateColumn = $this->cashflowDateColumn();

        $rows = $this->baseQuery($filters)
            ->whereBetween($dateColumn, [$start, $end])
            ->get([
                'marketplace_id',
                'currency',
                'transaction_status',
                'net_ex_tax_amount',
            ]);

        return [
            'view' => 'week',
            'start_utc' => $start->toIso8601String(),
            'end_utc' => $end->toIso8601String(),
            'buckets' => $this->summarizeBuckets($rows->all()),
        ];
    }

    public function todayTimingByMarketplace(Carbon $nowUtc, array $filters = []): array
    {
        $start = $nowUtc->copy()->utc()->startOfDay();
        $end = $nowUtc->copy()->utc()->endOfDay();
        $dateColumn = $this->cashflowDateColumn();

        $rows = $this->baseQuery($filters)
            ->whereBetween($dateColumn, [$start, $end])
            ->get([
                'amazon_order_id',
                'transaction_id',
                'marketplace_id',
                'currency',
                "{$dateColumn} as effective_payment_date",
                'net_ex_tax_amount',
                'transaction_status',
            ]);

        $timeline = [];
        $transactions = [];
        foreach ($rows as $row) {
            $marketplaceId = trim((string) ($row->marketplace_id ?? 'UNKNOWN'));
            $currency = strtoupper(trim((string) ($row->currency ?? '')));
            $amount = (float) ($row->net_ex_tax_amount ?? 0);
            $status = strtoupper(trim((string) ($row->transaction_status ?? 'UNKNOWN')));
            $hour = optional($row->effective_payment_date)->copy()?->utc()->format('H:00');
            if ($hour === null) {
                continue;
            }

            $transactions[] = [
                'effective_payment_time_utc' => $this->formatUtcDateTime($row->effective_payment_date ?? null),
                'marketplace_id' => $marketplaceId,
                'amazon_order_id' => trim((string) ($row->amazon_order_id ?? '')),
                'transaction_id' => trim((string) ($row->transaction_id ?? '')),
                'transaction_status' => $status,
                'currency' => $currency !== '' ? $currency : null,
                'net_ex_tax_amount' => round($amount, 4),
            ];

            $key = $marketplaceId . '|' . $currency . '|' . $hour;
            if (!isset($timeline[$key])) {
                $timeline[$key] = [
                    'marketplace_id' => $marketplaceId,
                    'currency' => $currency !== '' ? $currency : null,
                    'hour_utc' => $hour,
                    'expected_total' => 0.0,
                    'released_total' => 0.0,
                    'deferred_total' => 0.0,
                    'net_projection' => 0.0,
                    'events' => 0,
                ];
            }

            $timeline[$key]['expected_total'] = round($timeline[$key]['expected_total'] + $amount, 4);
            $timeline[$key]['net_projection'] = round($timeline[$key]['net_projection'] + $amount, 4);
            $timeline[$key]['events']++;
            if ($status === 'RELEASED') {
                $timeline[$key]['released_total'] = round($timeline[$key]['released_total'] + $amount, 4);
            }
            if ($status === 'DEFERRED') {
                $timeline[$key]['deferred_total'] = round($timeline[$key]['deferred_total'] + $amount, 4);
            }
        }

        usort($timeline, function (array $a, array $b) {
            return strcmp(($a['marketplace_id'] ?? '') . ($a['hour_utc'] ?? ''), ($b['marketplace_id'] ?? '') . ($b['hour_utc'] ?? ''));
        });
        usort($transactions, function (array $a, array $b) {
            return strcmp((string) ($a['effective_payment_time_utc'] ?? ''), (string) ($b['effective_payment_time_utc'] ?? ''));
        });

        return [
            'view' => 'today_timing',
            'start_utc' => $start->toIso8601String(),
            'end_utc' => $end->toIso8601String(),
            'timeline' => array_values($timeline),
            'transactions' => array_values($transactions),
        ];
    }

    public function outstandingByMaturity(?Carbon $fromUtc, ?Carbon $toUtc, array $filters = []): array
    {
        $start = $fromUtc?->copy()->utc()->startOfDay();
        $end = $toUtc?->copy()->utc()->endOfDay();
        $query = DB::table('cashflow_outstanding_transactions');
        if (!empty($filters['marketplace_id'])) {
            $query->where('marketplace_id', strtoupper(trim((string) $filters['marketplace_id'])));
        }
        if (!empty($filters['region'])) {
            $query->where('region', strtoupper(trim((string) $filters['region'])));
        }
        if (!empty($filters['currency'])) {
            $query->where('currency', strtoupper(trim((string) $filters['currency'])));
        }
        if ($start) {
            $query->where('maturity_datetime_utc', '>=', $start->toDateTimeString());
        }
        if ($end) {
            $query->where('maturity_datetime_utc', '<=', $end->toDateTimeString());
        }

        $rows = $query
            ->orderBy('maturity_datetime_utc')
            ->orderBy('transaction_id')
            ->get();

        $transactions = [];
        $totalsByCurrency = [];
        $missingTotalAmountRows = 0;
        foreach ($rows as $row) {
            $transactions[] = [
                'maturity_datetime_utc' => $this->formatUtcDateTime($row->maturity_datetime_utc ?? null),
                'posted_datetime_utc' => $this->formatUtcDateTime($row->posted_datetime_utc ?? null),
                'days_posted_to_maturity' => $row->days_posted_to_maturity !== null ? (int) $row->days_posted_to_maturity : null,
                'marketplace_id' => $row->marketplace_id,
                'amazon_order_id' => $row->amazon_order_id,
                'transaction_id' => $row->transaction_id,
                'transaction_status' => $row->transaction_status,
                'currency' => $row->currency,
                'outstanding_value' => $row->outstanding_value !== null ? (float) $row->outstanding_value : null,
                'missing_total_amount' => (bool) ($row->missing_total_amount ?? false),
            ];

            if ((bool) ($row->missing_total_amount ?? false) || $row->currency === null || $row->outstanding_value === null) {
                $missingTotalAmountRows++;
                continue;
            }

            $currency = strtoupper(trim((string) $row->currency));
            $totalsByCurrency[$currency] = round(($totalsByCurrency[$currency] ?? 0) + (float) $row->outstanding_value, 4);
        }

        ksort($totalsByCurrency);

        return [
            'view' => 'outstanding',
            'source' => 'cashflow_outstanding_transactions',
            'lookback_days' => self::OUTSTANDING_LOOKBACK_DAYS,
            'from_utc' => $start?->toIso8601String(),
            'to_utc' => $end?->toIso8601String(),
            'total_transactions' => count($transactions),
            'totals_by_currency' => $totalsByCurrency,
            'missing_total_amount_rows' => $missingTotalAmountRows,
            'transactions' => $transactions,
        ];
    }

    public function syncOutstandingSnapshot(): array
    {
        $adapter = $this->financesAdapter ?? new FinancesAdapter(new SpApiClientFactory());
        $regionService = $this->regionConfigService ?? new RegionConfigService();
        $regions = $regionService->spApiRegions();
        $postedAfter = now()->utc()->subDays(self::OUTSTANDING_LOOKBACK_DAYS)->startOfDay();
        $postedBefore = now()->utc()->subMinutes(2);

        $rowsByHash = [];
        $transactionsSeen = 0;

        foreach ($regions as $region) {
            $config = $regionService->spApiConfig($region);
            $marketplaceIds = array_values(array_filter(array_map(
                static fn ($id) => strtoupper(trim((string) $id)),
                (array) ($config['marketplace_ids'] ?? [])
            )));

            foreach ($marketplaceIds as $marketplaceId) {
                $nextToken = null;
                $pages = 0;

                do {
                    $response = $adapter->listTransactions(
                        postedAfter: $postedAfter,
                        postedBefore: $postedBefore,
                        marketplaceId: $marketplaceId,
                        transactionStatus: null,
                        nextToken: $nextToken,
                        region: $region
                    );
                    if ($response->status() >= 400) {
                        Log::warning('cashflow snapshot API call failed', [
                            'region' => $region,
                            'marketplace_id' => $marketplaceId,
                            'status' => $response->status(),
                        ]);
                        break;
                    }

                    $payload = (array) (($response->json('payload') ?? null) ?: []);
                    $txns = (array) ($payload['transactions'] ?? []);
                    $transactionsSeen += count($txns);
                    foreach ($txns as $txn) {
                        if (!is_array($txn)) {
                            continue;
                        }

                        $status = strtoupper(trim((string) ($txn['transactionStatus'] ?? '')));
                        if ($status !== 'DEFERRED') {
                            continue;
                        }

                        $maturityRaw = $this->extractMaturityDateFromTransaction($txn);
                        $maturityDate = $this->parseAnyDate($maturityRaw);
                        if ($maturityDate === null) {
                            continue;
                        }

                        $totalAmountNode = is_array($txn['totalAmount'] ?? null) ? $txn['totalAmount'] : [];
                        $value = $this->moneyAmount($totalAmountNode);
                        $currency = $this->moneyCurrency($totalAmountNode);
                        $postedRaw = $txn['postedDate'] ?? null;
                        $postedDate = $this->formatUtcDateTime($postedRaw);
                        $marketplaceIdTx = strtoupper(trim((string) data_get($txn, 'sellingPartnerMetadata.marketplaceId', $marketplaceId)));
                        $orderId = $this->extractOrderIdFromTransaction($txn);
                        $transactionId = trim((string) ($txn['transactionId'] ?? ''));

                        $hash = sha1(implode('|', [
                            strtoupper(trim((string) $region)),
                            $marketplaceIdTx,
                            $transactionId,
                            $maturityDate->format('Y-m-d H:i:s'),
                        ]));

                        $row = [
                            'row_hash' => $hash,
                            'region' => strtoupper(trim((string) $region)),
                            'marketplace_id' => $marketplaceIdTx !== '' ? $marketplaceIdTx : null,
                            'amazon_order_id' => $orderId !== '' ? $orderId : null,
                            'transaction_id' => $transactionId !== '' ? $transactionId : null,
                            'transaction_status' => $status !== '' ? $status : null,
                            'posted_datetime_utc' => $postedDate,
                            'maturity_datetime_utc' => $maturityDate->format('Y-m-d H:i:s'),
                            'days_posted_to_maturity' => $this->daysBetweenDates($postedRaw, $maturityRaw),
                            'currency' => $currency,
                            'outstanding_value' => $value !== null ? round((float) $value, 4) : null,
                            'missing_total_amount' => $value === null || $currency === null,
                            'raw_transaction' => json_encode($txn),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        // API pages can return overlapping transactions; keep one row per hash.
                        $rowsByHash[$hash] = $row;
                    }

                    $nextToken = trim((string) ($payload['nextToken'] ?? ''));
                    $nextToken = $nextToken !== '' ? $nextToken : null;
                    $pages++;
                    if ($pages >= self::OUTSTANDING_MAX_PAGES_PER_MARKETPLACE) {
                        $nextToken = null;
                    }
                } while ($nextToken !== null);
            }
        }

        $rows = array_values($rowsByHash);

        DB::transaction(function () use ($rows) {
            DB::table('cashflow_outstanding_transactions')->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('cashflow_outstanding_transactions')->insert($chunk);
            }
        });

        return [
            'rows_written' => count($rows),
            'transactions_seen' => $transactionsSeen,
            'regions' => count($regions),
        ];
    }

    private function baseQuery(array $filters)
    {
        return DB::table('amazon_order_fee_lines_v2')
            ->when(!empty($filters['marketplace_id']), fn ($q) => $q->where('marketplace_id', (string) $filters['marketplace_id']))
            ->when(!empty($filters['region']), fn ($q) => $q->where('region', strtoupper((string) $filters['region'])))
            ->when(!empty($filters['currency']), fn ($q) => $q->where('currency', strtoupper((string) $filters['currency'])));
    }

    private function cashflowDateColumn(): string
    {
        if (Schema::hasColumn('amazon_order_fee_lines_v2', 'effective_payment_date')) {
            return 'effective_payment_date';
        }

        return 'posted_date';
    }

    private function formatUtcDateTime(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->copy()->utc()->format('Y-m-d H:i:s');
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function daysBetweenDates(mixed $from, mixed $to): ?int
    {
        $fromDate = $this->parseAnyDate($from);
        $toDate = $this->parseAnyDate($to);
        if ($fromDate === null || $toDate === null) {
            return null;
        }

        return $fromDate->diffInDays($toDate, false);
    }

    private function parseAnyDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->utc();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->utc();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function extractMaturityDateFromTransaction(array $transaction): ?string
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
                ?? data_get($context, 'maturityDate');
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function extractOrderIdFromTransaction(array $transaction): string
    {
        $related = $transaction['relatedIdentifiers'] ?? [];
        if (!is_array($related)) {
            return '';
        }

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

    private function moneyAmount(array $money): ?float
    {
        $amount = $money['CurrencyAmount']
            ?? $money['currencyAmount']
            ?? $money['Amount']
            ?? $money['amount']
            ?? null;
        return is_numeric($amount) ? (float) $amount : null;
    }

    private function moneyCurrency(array $money): ?string
    {
        $currency = trim((string) ($money['CurrencyCode'] ?? $money['currencyCode'] ?? ''));
        return $currency !== '' ? strtoupper($currency) : null;
    }

    private function summarizeBuckets(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $marketplaceId = trim((string) ($row->marketplace_id ?? 'UNKNOWN'));
            $currency = strtoupper(trim((string) ($row->currency ?? '')));
            $amount = (float) ($row->net_ex_tax_amount ?? 0);
            $status = strtoupper(trim((string) ($row->transaction_status ?? 'UNKNOWN')));
            $key = $marketplaceId . '|' . $currency;
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'marketplace_id' => $marketplaceId,
                    'currency' => $currency !== '' ? $currency : null,
                    'expected_total' => 0.0,
                    'released_total' => 0.0,
                    'deferred_total' => 0.0,
                    'net_projection' => 0.0,
                    'events' => 0,
                ];
            }

            $buckets[$key]['expected_total'] = round($buckets[$key]['expected_total'] + $amount, 4);
            $buckets[$key]['net_projection'] = round($buckets[$key]['net_projection'] + $amount, 4);
            $buckets[$key]['events']++;

            if ($status === 'RELEASED') {
                $buckets[$key]['released_total'] = round($buckets[$key]['released_total'] + $amount, 4);
            }
            if ($status === 'DEFERRED') {
                $buckets[$key]['deferred_total'] = round($buckets[$key]['deferred_total'] + $amount, 4);
            }
        }

        return array_values($buckets);
    }
}
