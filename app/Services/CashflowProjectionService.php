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
    private const OUTSTANDING_LOOKBACK_DAYS = 130;
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
        if (!Schema::hasTable('cashflow_outstanding_transactions')) {
            return [
                'view' => 'outstanding',
                'source' => 'cashflow_outstanding_transactions',
                'lookback_days' => self::OUTSTANDING_LOOKBACK_DAYS,
                'from_utc' => $start?->toIso8601String(),
                'to_utc' => $end?->toIso8601String(),
                'available_maturity_from_utc' => null,
                'available_maturity_to_utc' => null,
                'total_transactions' => 0,
                'totals_by_currency' => [],
                'missing_total_amount_rows' => 0,
                'diagnostics' => null,
                'warning' => 'cashflow_outstanding_transactions table is missing. Run migrations and cashflow snapshot sync.',
                'transactions' => [],
            ];
        }

        $extentQuery = DB::table('cashflow_outstanding_transactions');
        $query = DB::table('cashflow_outstanding_transactions');
        if (!empty($filters['marketplace_id'])) {
            $marketplaceId = strtoupper(trim((string) $filters['marketplace_id']));
            $query->where('marketplace_id', $marketplaceId);
            $extentQuery->where('marketplace_id', $marketplaceId);
        }
        if (!empty($filters['region'])) {
            $region = strtoupper(trim((string) $filters['region']));
            $query->where('region', $region);
            $extentQuery->where('region', $region);
        }
        if (!empty($filters['currency'])) {
            $currency = strtoupper(trim((string) $filters['currency']));
            $query->where('currency', $currency);
            $extentQuery->where('currency', $currency);
        }
        $query->where('transaction_status', 'DEFERRED');
        $extentQuery->where('transaction_status', 'DEFERRED');

        $extentQueryForMin = clone $extentQuery;
        $extentQueryForMax = clone $extentQuery;
        $availableMaturityFrom = $extentQueryForMin->min('maturity_datetime_utc');
        $availableMaturityTo = $extentQueryForMax->max('maturity_datetime_utc');

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
                'deferral_reason' => $row->deferral_reason,
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

        $diagnostics = null;
        if (Schema::hasTable('cashflow_outstanding_snapshot_runs')) {
            $latestRun = DB::table('cashflow_outstanding_snapshot_runs')->orderByDesc('id')->first();
            if ($latestRun) {
                $diagnostics = [
                    'ran_at_utc' => $this->formatUtcDateTime($latestRun->ran_at_utc ?? null),
                    'lookback_days' => (int) ($latestRun->lookback_days ?? self::OUTSTANDING_LOOKBACK_DAYS),
                    'regions_processed' => (int) ($latestRun->regions_processed ?? 0),
                    'marketplaces_processed' => (int) ($latestRun->marketplaces_processed ?? 0),
                    'transactions_seen' => (int) ($latestRun->transactions_seen ?? 0),
                    'rows_written' => (int) ($latestRun->rows_written ?? 0),
                    'excluded_by_status' => (int) ($latestRun->excluded_by_status ?? 0),
                    'excluded_missing_maturity' => (int) ($latestRun->excluded_missing_maturity ?? 0),
                    'rows_missing_total_amount' => (int) ($latestRun->rows_missing_total_amount ?? 0),
                    'outside_lookback_not_scanned' => true,
                ];
            }
        }

        return [
            'view' => 'outstanding',
            'source' => 'cashflow_outstanding_transactions',
            'lookback_days' => self::OUTSTANDING_LOOKBACK_DAYS,
            'from_utc' => $start?->toIso8601String(),
            'to_utc' => $end?->toIso8601String(),
            'available_maturity_from_utc' => $this->formatUtcDateTime($availableMaturityFrom),
            'available_maturity_to_utc' => $this->formatUtcDateTime($availableMaturityTo),
            'total_transactions' => count($transactions),
            'totals_by_currency' => $totalsByCurrency,
            'missing_total_amount_rows' => $missingTotalAmountRows,
            'diagnostics' => $diagnostics,
            'transactions' => $transactions,
        ];
    }

    public function syncOutstandingSnapshot(): array
    {
        if (!Schema::hasTable('cashflow_outstanding_transactions')) {
            return [
                'rows_written' => 0,
                'transactions_seen' => 0,
                'regions' => 0,
                'warning' => 'cashflow_outstanding_transactions table is missing. Run migrations first.',
            ];
        }

        $adapter = $this->financesAdapter ?? new FinancesAdapter(new SpApiClientFactory());
        $regionService = $this->regionConfigService ?? new RegionConfigService();
        $regions = $regionService->spApiRegions();
        $postedAfter = now()->utc()->subDays(self::OUTSTANDING_LOOKBACK_DAYS)->startOfDay();
        $postedBefore = now()->utc()->subMinutes(2);

        $rowsByHash = [];
        $rowMatchMetaByHash = [];
        $releasedDeferredTransactionIds = [];
        $releasedCanonicalKeys = [];
        $transactionsSeen = 0;
        $excludedByStatus = 0;
        $excludedMissingMaturity = 0;
        $excludedByReleased = 0;
        $rowsMissingTotalAmount = 0;
        $marketplacesProcessed = 0;

        foreach ($regions as $region) {
            $config = $regionService->spApiConfig($region);
            $marketplaceIds = array_values(array_filter(array_map(
                static fn ($id) => strtoupper(trim((string) $id)),
                (array) ($config['marketplace_ids'] ?? [])
            )));

            foreach ($marketplaceIds as $marketplaceId) {
                $marketplacesProcessed++;
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
                        if ($status === 'RELEASED') {
                            foreach ($this->extractRelatedTransactionIdsFromTransaction($txn, ['DEFERRED_TRANSACTION_ID']) as $deferredTransactionId) {
                                $releasedDeferredTransactionIds[$deferredTransactionId] = true;
                            }

                            $releasedCanonicalKey = $this->canonicalDeferredReleaseKey($txn);
                            if ($releasedCanonicalKey !== '') {
                                $releasedCanonicalKeys[$releasedCanonicalKey] = true;
                            }

                            continue;
                        }

                        if ($status !== 'DEFERRED') {
                            $excludedByStatus++;
                            continue;
                        }

                        $maturityRaw = $this->extractMaturityDateFromTransaction($txn);
                        $maturityDate = $this->parseAnyDate($maturityRaw);
                        if ($maturityDate === null) {
                            $excludedMissingMaturity++;
                            continue;
                        }

                        $totalAmountNode = is_array($txn['totalAmount'] ?? null) ? $txn['totalAmount'] : [];
                        $value = $this->moneyAmount($totalAmountNode);
                        $currency = $this->moneyCurrency($totalAmountNode);
                        $postedRaw = $txn['postedDate'] ?? null;
                        $postedDate = $this->formatUtcDateTime($postedRaw);
                        $deferralReason = $this->extractDeferralReasonFromTransaction($txn);
                        $marketplaceIdTx = strtoupper(trim((string) data_get($txn, 'sellingPartnerMetadata.marketplaceId', $marketplaceId)));
                        $orderId = $this->extractOrderIdFromTransaction($txn);
                        $transactionId = trim((string) ($txn['transactionId'] ?? ''));
                        $deferredCanonicalKey = $this->canonicalDeferredReleaseKey($txn);

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
                            'deferral_reason' => $deferralReason,
                            'currency' => $currency,
                            'outstanding_value' => $value !== null ? round((float) $value, 4) : null,
                            'missing_total_amount' => $value === null || $currency === null,
                            'raw_transaction' => json_encode($txn),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        // API pages can return overlapping transactions; keep one row per hash.
                        $rowsByHash[$hash] = $row;
                        $rowMatchMetaByHash[$hash] = [
                            'transaction_id' => $transactionId,
                            'canonical_key' => $deferredCanonicalKey,
                        ];

                        if ($row['missing_total_amount']) {
                            $rowsMissingTotalAmount++;
                        }
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

        if (!empty($rowsByHash) && (!empty($releasedDeferredTransactionIds) || !empty($releasedCanonicalKeys))) {
            foreach ($rowsByHash as $hash => $row) {
                $meta = $rowMatchMetaByHash[$hash] ?? null;
                $transactionId = trim((string) ($meta['transaction_id'] ?? ''));
                $canonicalKey = trim((string) ($meta['canonical_key'] ?? ''));

                if ($transactionId !== '' && isset($releasedDeferredTransactionIds[$transactionId])) {
                    unset($rowsByHash[$hash], $rowMatchMetaByHash[$hash]);
                    $excludedByReleased++;
                    continue;
                }

                if ($canonicalKey !== '' && isset($releasedCanonicalKeys[$canonicalKey])) {
                    unset($rowsByHash[$hash], $rowMatchMetaByHash[$hash]);
                    $excludedByReleased++;
                }
            }
        }

        $rows = array_values($rowsByHash);
        $resolvedDeferredTransactionIds = array_values(array_keys($releasedDeferredTransactionIds));

        DB::transaction(function () use ($rows, $resolvedDeferredTransactionIds) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('cashflow_outstanding_transactions')->upsert(
                    $chunk,
                    ['row_hash'],
                    [
                        'region',
                        'marketplace_id',
                        'amazon_order_id',
                        'transaction_id',
                        'transaction_status',
                        'posted_datetime_utc',
                        'maturity_datetime_utc',
                        'days_posted_to_maturity',
                        'deferral_reason',
                        'currency',
                        'outstanding_value',
                        'missing_total_amount',
                        'raw_transaction',
                        'updated_at',
                    ]
                );
            }

            if (!empty($resolvedDeferredTransactionIds)) {
                foreach (array_chunk($resolvedDeferredTransactionIds, 500) as $chunk) {
                    DB::table('cashflow_outstanding_transactions')
                        ->whereIn('transaction_id', $chunk)
                        ->delete();
                }
            }
        });

        if (Schema::hasTable('cashflow_outstanding_snapshot_runs')) {
            DB::table('cashflow_outstanding_snapshot_runs')->insert([
                'ran_at_utc' => now()->utc()->format('Y-m-d H:i:s'),
                'lookback_days' => self::OUTSTANDING_LOOKBACK_DAYS,
                'regions_processed' => count($regions),
                'marketplaces_processed' => $marketplacesProcessed,
                'transactions_seen' => $transactionsSeen,
                'rows_written' => count($rows),
                'excluded_by_status' => $excludedByStatus,
                'excluded_missing_maturity' => $excludedMissingMaturity,
                'rows_missing_total_amount' => $rowsMissingTotalAmount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'rows_written' => count($rows),
            'transactions_seen' => $transactionsSeen,
            'regions' => count($regions),
            'marketplaces_processed' => $marketplacesProcessed,
            'excluded_by_status' => $excludedByStatus,
            'excluded_missing_maturity' => $excludedMissingMaturity,
            'excluded_by_released' => $excludedByReleased,
            'rows_missing_total_amount' => $rowsMissingTotalAmount,
        ];
    }

    private function canonicalDeferredReleaseKey(array $transaction): string
    {
        $transactionId = trim((string) ($transaction['transactionId'] ?? ''));
        $linkedIds = $this->extractRelatedTransactionIdsFromTransaction($transaction, [
            'RELEASE_TRANSACTION_ID',
            'DEFERRED_TRANSACTION_ID',
        ]);

        $linkedId = $linkedIds[0] ?? '';
        if ($transactionId !== '' && $linkedId !== '') {
            $pair = [$transactionId, $linkedId];
            sort($pair, SORT_STRING);

            return implode('|', $pair);
        }

        if ($linkedId !== '') {
            return $linkedId;
        }

        return $transactionId;
    }

    private function extractRelatedTransactionIdsFromTransaction(array $transaction, array $identifierNames): array
    {
        $related = $transaction['relatedIdentifiers'] ?? [];
        if (!is_array($related) || empty($related)) {
            return [];
        }

        $names = array_fill_keys(array_map(static fn (string $name) => strtoupper(trim($name)), $identifierNames), true);
        $ids = [];
        foreach ($related as $identifier) {
            if (!is_array($identifier)) {
                continue;
            }

            $name = strtoupper(trim((string) ($identifier['relatedIdentifierName'] ?? '')));
            if (!isset($names[$name])) {
                continue;
            }

            $value = trim((string) ($identifier['relatedIdentifierValue'] ?? ''));
            if ($value !== '') {
                $ids[$value] = true;
            }
        }

        return array_values(array_keys($ids));
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

    private function extractDeferralReasonFromTransaction(array $transaction): ?string
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
                ?? data_get($context, 'deferralReason');
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
