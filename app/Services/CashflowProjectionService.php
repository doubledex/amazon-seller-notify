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
                'marketplace_id',
                'currency',
                "{$dateColumn} as effective_payment_date",
                'net_ex_tax_amount',
                'transaction_status',
            ]);

        $timeline = [];
        foreach ($rows as $row) {
            $marketplaceId = trim((string) ($row->marketplace_id ?? 'UNKNOWN'));
            $currency = strtoupper(trim((string) ($row->currency ?? '')));
            $amount = (float) ($row->net_ex_tax_amount ?? 0);
            $status = strtoupper(trim((string) ($row->transaction_status ?? 'UNKNOWN')));
            $hour = optional($row->effective_payment_date)->copy()?->utc()->format('H:00');
            if ($hour === null) {
                continue;
            }

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

        return [
            'view' => 'today_timing',
            'start_utc' => $start->toIso8601String(),
            'end_utc' => $end->toIso8601String(),
            'timeline' => array_values($timeline),
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
