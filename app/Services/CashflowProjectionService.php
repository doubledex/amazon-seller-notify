<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CashflowProjectionService
{
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
        $maturityColumn = $this->maturityDateColumn();
        $hasTxnTotalAmount = Schema::hasColumn('amazon_order_fee_lines_v2', 'transaction_total_amount');
        $hasTxnTotalCurrency = Schema::hasColumn('amazon_order_fee_lines_v2', 'transaction_total_currency');
        $txnAmountExpr = $hasTxnTotalAmount ? 'MAX(amazon_order_fee_lines_v2.transaction_total_amount)' : 'NULL';
        $txnCurrencyExpr = $hasTxnTotalCurrency
            ? "NULLIF(MAX(COALESCE(amazon_order_fee_lines_v2.transaction_total_currency, '')), '')"
            : 'NULL';

        $query = $this->baseQuery($filters)
            ->whereNotNull($maturityColumn)
            ->whereIn('transaction_status', ['DEFERRED', 'DEFERRED_RELEASED'])
            ->leftJoin('orders', 'orders.amazon_order_id', '=', 'amazon_order_fee_lines_v2.amazon_order_id')
            ->selectRaw('
                amazon_order_fee_lines_v2.marketplace_id,
                amazon_order_fee_lines_v2.amazon_order_id,
                amazon_order_fee_lines_v2.transaction_id,
                amazon_order_fee_lines_v2.transaction_status,
                ' . $maturityColumn . ' as maturity_date,
                amazon_order_fee_lines_v2.posted_date,
                ' . $txnAmountExpr . ' as txn_total_amount_candidate,
                ' . $txnCurrencyExpr . ' as txn_total_currency_candidate,
                MAX(orders.order_total_amount) as order_total_amount_candidate,
                NULLIF(MAX(COALESCE(orders.order_total_currency, \'\')), \'\') as order_total_currency_candidate,
                SUM(COALESCE(amazon_order_fee_lines_v2.net_ex_tax_amount, 0)) as fee_sum_amount_candidate,
                NULLIF(MAX(COALESCE(amazon_order_fee_lines_v2.currency, \'\')), \'\') as fee_sum_currency_candidate
            ')
            ->groupBy([
                'amazon_order_fee_lines_v2.marketplace_id',
                'amazon_order_fee_lines_v2.amazon_order_id',
                'amazon_order_fee_lines_v2.transaction_id',
                'amazon_order_fee_lines_v2.transaction_status',
                $maturityColumn,
                'amazon_order_fee_lines_v2.posted_date',
            ])
            ->orderBy($maturityColumn, 'asc')
            ->orderBy('amazon_order_fee_lines_v2.transaction_id', 'asc');

        if ($start && $end) {
            $query->whereBetween($maturityColumn, [$start, $end]);
        } elseif ($start) {
            $query->where($maturityColumn, '>=', $start);
        } elseif ($end) {
            $query->where($maturityColumn, '<=', $end);
        }

        $rows = $query->get();

        $transactions = [];
        $totalsByCurrency = [];
        $valueSourceCounts = [];
        foreach ($rows as $row) {
            $txnTotalAmount = $row->txn_total_amount_candidate !== null ? (float) $row->txn_total_amount_candidate : null;
            $orderTotalAmount = $row->order_total_amount_candidate !== null ? (float) $row->order_total_amount_candidate : null;
            $feeSumAmount = (float) ($row->fee_sum_amount_candidate ?? 0.0);

            $txnCurrency = strtoupper(trim((string) ($row->txn_total_currency_candidate ?? '')));
            $orderCurrency = strtoupper(trim((string) ($row->order_total_currency_candidate ?? '')));
            $feeCurrency = strtoupper(trim((string) ($row->fee_sum_currency_candidate ?? '')));

            if ($txnTotalAmount !== null) {
                $value = round($txnTotalAmount, 4);
                $currency = $txnCurrency !== '' ? $txnCurrency : ($orderCurrency !== '' ? $orderCurrency : $feeCurrency);
                $valueSource = 'transaction_total';
            } elseif ($orderTotalAmount !== null) {
                $value = round($orderTotalAmount, 4);
                $currency = $orderCurrency !== '' ? $orderCurrency : $feeCurrency;
                $valueSource = 'order_total_fallback';
            } else {
                $value = round($feeSumAmount, 4);
                $currency = $feeCurrency;
                $valueSource = 'fee_sum_fallback';
            }

            $currency = strtoupper(trim((string) $currency));
            if ($currency !== '') {
                $totalsByCurrency[$currency] = round(($totalsByCurrency[$currency] ?? 0) + $value, 4);
            }
            $valueSourceCounts[$valueSource] = ($valueSourceCounts[$valueSource] ?? 0) + 1;

            $transactions[] = [
                'maturity_datetime_utc' => $this->formatUtcDateTime($row->maturity_date ?? null),
                'posted_datetime_utc' => $this->formatUtcDateTime($row->posted_date ?? null),
                'marketplace_id' => $row->marketplace_id,
                'amazon_order_id' => $row->amazon_order_id,
                'transaction_id' => $row->transaction_id,
                'transaction_status' => $row->transaction_status,
                'currency' => $currency !== '' ? $currency : null,
                'outstanding_value' => $value,
                'value_source' => $valueSource,
            ];
        }

        ksort($totalsByCurrency);
        ksort($valueSourceCounts);

        return [
            'view' => 'outstanding',
            'from_utc' => $start?->toIso8601String(),
            'to_utc' => $end?->toIso8601String(),
            'total_transactions' => count($transactions),
            'totals_by_currency' => $totalsByCurrency,
            'value_source_counts' => $valueSourceCounts,
            'transactions' => $transactions,
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

    private function maturityDateColumn(): string
    {
        if (Schema::hasColumn('amazon_order_fee_lines_v2', 'maturity_date')) {
            return 'maturity_date';
        }

        return $this->cashflowDateColumn();
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
