<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InboundDiscrepancyMetricsController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->input('from')
            ? Carbon::parse((string) $request->input('from'))->toDateString()
            : now()->subDays(30)->toDateString();
        $to = $request->input('to')
            ? Carbon::parse((string) $request->input('to'))->toDateString()
            : now()->toDateString();

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $daily = DB::table('daily_inbound_discrepancy_metrics')
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->orderByDesc('metric_date')
            ->get();

        $splitRows = DB::table('daily_inbound_split_carton_metrics')
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->orderByDesc('metric_date')
            ->orderByDesc('split_carton_anomaly_rate_percent')
            ->limit(200)
            ->get();

        return view('metrics.inbound_discrepancies', [
            'from' => $from,
            'to' => $to,
            'daily' => $daily,
            'splitRows' => $splitRows,
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $from = $request->input('from')
            ? Carbon::parse((string) $request->input('from'))->toDateString()
            : now()->subDays(30)->toDateString();
        $to = $request->input('to')
            ? Carbon::parse((string) $request->input('to'))->toDateString()
            : now()->toDateString();

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $rows = DB::table('daily_inbound_discrepancy_metrics')
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->orderBy('metric_date')
            ->get();

        $filename = sprintf('inbound-discrepancy-kpis-%s-to-%s.csv', $from, $to);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, [
                'metric_date',
                'shipped_units',
                'discrepancy_count',
                'discrepancy_rate_per_1000',
                'claims_submitted_before_deadline_percent',
                'claim_win_rate_percent',
                'avg_reimbursement_cycle_days',
                'recovered_value',
                'disputed_value',
                'recovered_vs_disputed_percent',
                'aged_open_missed',
                'aged_open_due_0_7_days',
                'aged_open_due_8_14_days',
                'aged_open_due_15_plus_days',
                'aged_open_no_deadline',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->metric_date,
                    $row->shipped_units,
                    $row->discrepancy_count,
                    $row->discrepancy_rate_per_1000,
                    $row->claims_submitted_before_deadline_percent,
                    $row->claim_win_rate_percent,
                    $row->avg_reimbursement_cycle_days,
                    $row->recovered_value,
                    $row->disputed_value,
                    $row->recovered_vs_disputed_percent,
                    $row->aged_open_missed,
                    $row->aged_open_due_0_7_days,
                    $row->aged_open_due_8_14_days,
                    $row->aged_open_due_15_plus_days,
                    $row->aged_open_no_deadline,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
