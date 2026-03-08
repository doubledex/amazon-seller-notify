<?php

namespace App\Services\Inbound;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyInboundDiscrepancyRollupService
{
    /**
     * @return array{days:int,rows:int,split_rows:int}
     */
    public function refreshRange(Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $days = 0;
        $rows = 0;
        $splitRows = 0;
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $days++;
            $result = $this->refreshDate($date->copy());
            $rows += $result['metrics_rows'];
            $splitRows += $result['split_rows'];
        }

        return [
            'days' => $days,
            'rows' => $rows,
            'split_rows' => $splitRows,
        ];
    }

    /**
     * @return array{metrics_rows:int,split_rows:int}
     */
    public function refreshDate(Carbon $date): array
    {
        $day = $date->toDateString();
        $dayEnd = $date->copy()->endOfDay();

        $discrepancyBase = DB::table('inbound_discrepancies as d')
            ->leftJoin('inbound_shipments as s', 's.shipment_id', '=', 'd.shipment_id')
            ->whereDate(DB::raw('COALESCE(d.discrepancy_detected_at, d.created_at)'), '=', $day);

        $shippedUnits = (int) (clone $discrepancyBase)->sum('d.expected_units');
        $discrepancyCount = (int) (clone $discrepancyBase)->count();
        $disputedValue = (float) (clone $discrepancyBase)->sum(DB::raw('ABS(COALESCE(d.value_impact, 0))'));
        $discrepancyRate = $shippedUnits > 0 ? ($discrepancyCount / $shippedUnits) * 1000 : 0.0;

        $claimsBase = DB::table('inbound_claim_cases as c')
            ->join('inbound_discrepancies as d', 'd.id', '=', 'c.discrepancy_id')
            ->whereDate(DB::raw('COALESCE(d.discrepancy_detected_at, d.created_at)'), '=', $day);

        $claimsSubmitted = (int) (clone $claimsBase)
            ->whereNotNull('c.submitted_at')
            ->count();

        $claimsBeforeDeadline = (int) (clone $claimsBase)
            ->whereNotNull('c.submitted_at')
            ->whereRaw('c.submitted_at <= COALESCE(c.challenge_deadline_at, d.challenge_deadline_at)')
            ->count();

        $claimsBeforeDeadlinePct = $claimsSubmitted > 0
            ? round(($claimsBeforeDeadline / $claimsSubmitted) * 100, 2)
            : null;

        $wonOutcomes = ['won', 'approved', 'reimbursed', 'success'];
        $claimsClosed = (int) (clone $claimsBase)
            ->whereNotNull('c.outcome')
            ->whereNotIn(DB::raw('LOWER(c.outcome)'), ['open', 'pending', 'submitted'])
            ->count();
        $claimsWon = (int) (clone $claimsBase)
            ->whereIn(DB::raw('LOWER(c.outcome)'), $wonOutcomes)
            ->count();

        $claimWinRate = $claimsClosed > 0
            ? round(($claimsWon / $claimsClosed) * 100, 2)
            : null;

        $reimbursementRows = (clone $claimsBase)
            ->whereNotNull('c.submitted_at')
            ->where(function ($query) use ($wonOutcomes) {
                $query->whereIn(DB::raw('LOWER(c.outcome)'), $wonOutcomes)
                    ->orWhere('c.reimbursed_amount', '>', 0)
                    ->orWhere('c.reimbursed_units', '>', 0);
            })
            ->get(['c.submitted_at', 'c.updated_at']);

        $avgReimbursementCycleDays = $reimbursementRows->isNotEmpty()
            ? $reimbursementRows->avg(function ($row) {
                if ($row->submitted_at === null || $row->updated_at === null) {
                    return null;
                }

                return Carbon::parse($row->submitted_at)->floatDiffInDays(Carbon::parse($row->updated_at));
            })
            : null;

        $recoveredValue = (float) (clone $claimsBase)->sum('c.reimbursed_amount');
        $recoveredVsDisputed = $disputedValue > 0
            ? round(($recoveredValue / $disputedValue) * 100, 2)
            : null;

        $openAging = DB::table('inbound_discrepancies as d')
            ->where('d.status', 'open')
            ->whereDate(DB::raw('COALESCE(d.discrepancy_detected_at, d.created_at)'), '<=', $day)
            ->get(['d.challenge_deadline_at']);

        $agedBuckets = $this->bucketAgedOpen($openAging, $dayEnd);

        DB::table('daily_inbound_discrepancy_metrics')->updateOrInsert(
            ['metric_date' => $day],
            [
                'shipped_units' => $shippedUnits,
                'discrepancy_count' => $discrepancyCount,
                'discrepancy_rate_per_1000' => round($discrepancyRate, 4),
                'claims_submitted_count' => $claimsSubmitted,
                'claims_before_deadline_count' => $claimsBeforeDeadline,
                'claims_submitted_before_deadline_percent' => $claimsBeforeDeadlinePct,
                'claims_closed_count' => $claimsClosed,
                'claims_won_count' => $claimsWon,
                'claim_win_rate_percent' => $claimWinRate,
                'avg_reimbursement_cycle_days' => $avgReimbursementCycleDays !== null ? round((float) $avgReimbursementCycleDays, 2) : null,
                'recovered_value' => round($recoveredValue, 2),
                'disputed_value' => round($disputedValue, 2),
                'recovered_vs_disputed_percent' => $recoveredVsDisputed,
                'aged_open_missed' => $agedBuckets['missed'],
                'aged_open_due_0_7_days' => $agedBuckets['due_0_7'],
                'aged_open_due_8_14_days' => $agedBuckets['due_8_14'],
                'aged_open_due_15_plus_days' => $agedBuckets['due_15_plus'],
                'aged_open_no_deadline' => $agedBuckets['no_deadline'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $splitRows = $this->refreshSplitCartonRows($day);

        return [
            'metrics_rows' => 1,
            'split_rows' => $splitRows,
        ];
    }

    private function refreshSplitCartonRows(string $day): int
    {
        $fcLookup = DB::table('us_fc_inventories')
            ->selectRaw('marketplace_id, COALESCE(seller_sku, "") as sku, COALESCE(fnsku, "") as fnsku')
            ->selectRaw('MAX(COALESCE(fulfillment_center_id, "UNKNOWN")) as fulfillment_center_id')
            ->groupBy('marketplace_id', 'sku', 'fnsku');

        $rows = DB::table('inbound_discrepancies as d')
            ->join('inbound_shipments as s', 's.shipment_id', '=', 'd.shipment_id')
            ->leftJoinSub($fcLookup, 'fc', function ($join) {
                $join->on('fc.marketplace_id', '=', 's.marketplace_id')
                    ->on('fc.sku', '=', 'd.sku')
                    ->on('fc.fnsku', '=', 'd.fnsku');
            })
            ->whereDate(DB::raw('COALESCE(d.discrepancy_detected_at, d.created_at)'), '=', $day)
            ->groupByRaw('COALESCE(fc.fulfillment_center_id, "UNKNOWN"), COALESCE(d.sku, ""), COALESCE(s.carrier_name, "UNKNOWN")')
            ->selectRaw('COALESCE(fc.fulfillment_center_id, "UNKNOWN") as fulfillment_center_id')
            ->selectRaw('COALESCE(d.sku, "") as sku')
            ->selectRaw('COALESCE(s.carrier_name, "UNKNOWN") as carrier_name')
            ->selectRaw('COUNT(*) as discrepancy_count')
            ->selectRaw('SUM(CASE WHEN d.split_carton = 1 THEN 1 ELSE 0 END) as split_carton_count')
            ->get();

        DB::table('daily_inbound_split_carton_metrics')
            ->whereDate('metric_date', '=', $day)
            ->delete();

        $payload = $rows
            ->map(function ($row) use ($day) {
                $discrepancies = (int) ($row->discrepancy_count ?? 0);
                $split = (int) ($row->split_carton_count ?? 0);

                return [
                    'metric_date' => $day,
                    'fulfillment_center_id' => (string) ($row->fulfillment_center_id ?? 'UNKNOWN'),
                    'sku' => (string) ($row->sku ?? ''),
                    'carrier_name' => (string) ($row->carrier_name ?? 'UNKNOWN'),
                    'discrepancy_count' => $discrepancies,
                    'split_carton_count' => $split,
                    'split_carton_anomaly_rate_percent' => $discrepancies > 0 ? round(($split / $discrepancies) * 100, 2) : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })
            ->values()
            ->all();

        if (!empty($payload)) {
            DB::table('daily_inbound_split_carton_metrics')->insert($payload);
        }

        return count($payload);
    }

    private function bucketAgedOpen(Collection $rows, Carbon $dayEnd): array
    {
        $buckets = [
            'missed' => 0,
            'due_0_7' => 0,
            'due_8_14' => 0,
            'due_15_plus' => 0,
            'no_deadline' => 0,
        ];

        foreach ($rows as $row) {
            if (empty($row->challenge_deadline_at)) {
                $buckets['no_deadline']++;
                continue;
            }

            $daysRemaining = $dayEnd->diffInDays(Carbon::parse($row->challenge_deadline_at), false);
            if ($daysRemaining < 0) {
                $buckets['missed']++;
            } elseif ($daysRemaining <= 7) {
                $buckets['due_0_7']++;
            } elseif ($daysRemaining <= 14) {
                $buckets['due_8_14']++;
            } else {
                $buckets['due_15_plus']++;
            }
        }

        return $buckets;
    }
}
