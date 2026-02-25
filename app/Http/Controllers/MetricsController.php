<?php

namespace App\Http\Controllers;

use App\Services\FxRateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function index(Request $request, FxRateService $fxRateService)
    {
        $fromInput = $request->input('from');
        $toInput = $request->input('to');

        $from = $fromInput ? Carbon::parse($fromInput)->toDateString() : now()->subDays(30)->toDateString();
        $to = $toInput ? Carbon::parse($toInput)->toDateString() : now()->toDateString();
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $metricRows = DB::table('daily_region_metrics')
            ->select([
                'metric_date',
                'region',
                'local_currency',
                'sales_local',
                'sales_gbp',
                'ad_spend_local',
                'ad_spend_gbp',
                'acos_percent',
                'order_count',
            ])
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->orderByDesc('metric_date')
            ->orderBy('region')
            ->get();

        $byDate = [];
        foreach ($metricRows as $row) {
            $date = (string) $row->metric_date;
            if ($date === '') {
                continue;
            }

            if (!isset($byDate[$date])) {
                $byDate[$date] = [];
            }
            $byDate[$date][] = $row;
        }

        $metricDateExpr = "COALESCE(orders.purchase_date_local_date, DATE(orders.purchase_date))";
        $countrySalesRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->selectRaw("
                {$metricDateExpr} as metric_date,
                UPPER(COALESCE(marketplaces.country_code, '')) as country_code,
                COALESCE(
                    NULLIF(orders.order_net_ex_tax_currency, ''),
                    NULLIF(orders.order_total_currency, ''),
                    NULLIF(marketplaces.default_currency, ''),
                    'GBP'
                ) as currency,
                SUM(
                    CASE
                        WHEN COALESCE(orders.order_net_ex_tax, 0) > 0 THEN orders.order_net_ex_tax
                        ELSE COALESCE(orders.order_total_amount, 0)
                    END
                ) as sales_amount,
                COUNT(*) as order_count
            ")
            ->whereRaw("{$metricDateExpr} >= ?", [$from])
            ->whereRaw("{$metricDateExpr} <= ?", [$to])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->groupByRaw("
                {$metricDateExpr},
                UPPER(COALESCE(marketplaces.country_code, '')),
                COALESCE(NULLIF(orders.order_net_ex_tax_currency, ''), NULLIF(orders.order_total_currency, ''), NULLIF(marketplaces.default_currency, ''), 'GBP')
            ")
            ->get();

        $countryByDateRegion = [];
        foreach ($countrySalesRows as $row) {
            $date = (string) ($row->metric_date ?? '');
            $country = $this->normalizeMarketplaceCode((string) ($row->country_code ?? ''));
            if ($date === '' || $country === '') {
                continue;
            }

            $region = $this->regionForCountry($country);
            $currency = strtoupper((string) ($row->currency ?? 'GBP'));
            $salesLocal = (float) ($row->sales_amount ?? 0);
            $salesGbp = $fxRateService->convert($salesLocal, $currency, 'GBP', $date) ?? 0.0;
            $key = $date . '|' . $region . '|' . $country;

            if (!isset($countryByDateRegion[$key])) {
                $countryByDateRegion[$key] = [
                    'date' => $date,
                    'region' => $region,
                    'country' => $country,
                    'currency' => $currency,
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'order_count' => 0,
                ];
            }

            $countryByDateRegion[$key]['sales_local'] += $salesLocal;
            $countryByDateRegion[$key]['sales_gbp'] += $salesGbp;
            $countryByDateRegion[$key]['order_count'] += (int) ($row->order_count ?? 0);
        }

        $dailyRows = [];
        foreach ($byDate as $date => $rows) {
            $items = [];
            $totalSalesGbp = 0.0;
            $totalAdGbp = 0.0;
            $totalOrders = 0;
            $regionRows = [];

            foreach ($rows as $row) {
                $salesGbp = (float) ($row->sales_gbp ?? 0);
                $adGbp = (float) ($row->ad_spend_gbp ?? 0);
                $totalSalesGbp += $salesGbp;
                $totalAdGbp += $adGbp;
                $totalOrders += (int) ($row->order_count ?? 0);
                $regionRows[] = $row;
            }

            foreach ($regionRows as $regionRow) {
                $region = strtoupper((string) ($regionRow->region ?? ''));
                $regionAdLocal = (float) ($regionRow->ad_spend_local ?? 0);
                $regionAdGbp = (float) ($regionRow->ad_spend_gbp ?? 0);
                $regionCountryRows = array_values(array_filter(
                    $countryByDateRegion,
                    fn (array $r): bool => $r['date'] === $date && $r['region'] === $region
                ));
                $regionCountrySalesGbp = 0.0;
                foreach ($regionCountryRows as $regionCountryRow) {
                    $regionCountrySalesGbp += (float) ($regionCountryRow['sales_gbp'] ?? 0);
                }

                foreach ($regionCountryRows as $regionCountryRow) {
                    $ratio = $regionCountrySalesGbp > 0 ? ((float) $regionCountryRow['sales_gbp'] / $regionCountrySalesGbp) : 0.0;
                    $adLocal = $regionAdLocal * $ratio;
                    $adGbp = $regionAdGbp * $ratio;
                    $currency = strtoupper((string) ($regionCountryRow['currency'] ?? 'GBP'));
                    $salesGbp = (float) ($regionCountryRow['sales_gbp'] ?? 0);

                    $items[] = [
                        'date' => $date,
                        'country' => (string) ($regionCountryRow['country'] ?? $region),
                        'currency' => $currency,
                        'currency_symbol' => $this->currencySymbol($currency),
                        'sales_local' => (float) ($regionCountryRow['sales_local'] ?? 0),
                        'sales_gbp' => $salesGbp,
                        'order_count' => (int) ($regionCountryRow['order_count'] ?? 0),
                        'units' => 0,
                        'ad_local' => $adLocal,
                        'ad_gbp' => $adGbp,
                        'fees_local' => 0.0,
                        'fees_gbp' => 0.0,
                        'estimated_fee_data' => false,
                        'pending_sales_data' => false,
                        'estimated_sales_data' => false,
                        'acos_percent' => $salesGbp > 0 ? ($adGbp / $salesGbp) * 100 : null,
                    ];
                }
            }

            if (empty($items)) {
                foreach ($regionRows as $row) {
                    $currency = strtoupper((string) ($row->local_currency ?? 'GBP'));
                    $salesGbp = (float) ($row->sales_gbp ?? 0);
                    $adGbp = (float) ($row->ad_spend_gbp ?? 0);
                    $items[] = [
                        'date' => $date,
                        'country' => strtoupper((string) ($row->region ?? '')),
                        'currency' => $currency,
                        'currency_symbol' => $this->currencySymbol($currency),
                        'sales_local' => (float) ($row->sales_local ?? 0),
                        'sales_gbp' => $salesGbp,
                        'order_count' => (int) ($row->order_count ?? 0),
                        'units' => 0,
                        'ad_local' => (float) ($row->ad_spend_local ?? 0),
                        'ad_gbp' => $adGbp,
                        'fees_local' => 0.0,
                        'fees_gbp' => 0.0,
                        'estimated_fee_data' => false,
                        'pending_sales_data' => false,
                        'estimated_sales_data' => false,
                        'acos_percent' => $salesGbp > 0 ? ($adGbp / $salesGbp) * 100 : null,
                    ];
                }
            }

            usort($items, fn ($a, $b) => strcmp((string) $a['country'], (string) $b['country']));
            $dailyRows[] = [
                'date' => $date,
                'sales_gbp' => $totalSalesGbp,
                'ad_gbp' => $totalAdGbp,
                'fees_gbp' => 0.0,
                'order_count' => $totalOrders,
                'units' => 0,
                'acos_percent' => $totalSalesGbp > 0 ? ($totalAdGbp / $totalSalesGbp) * 100 : null,
                'pending_sales_data' => false,
                'estimated_sales_data' => false,
                'estimated_fee_data' => false,
                'items' => $items,
            ];
        }

        $weekly = [];
        foreach ($dailyRows as $day) {
            $weekStart = Carbon::parse((string) $day['date'])->startOfWeek(Carbon::MONDAY)->toDateString();
            $weekEnd = Carbon::parse($weekStart)->endOfWeek(Carbon::SUNDAY)->toDateString();
            if (!isset($weekly[$weekStart])) {
                $weekly[$weekStart] = [
                    'week_start' => $weekStart,
                    'week_end' => $weekEnd,
                    'sales_gbp' => 0.0,
                    'ad_gbp' => 0.0,
                    'fees_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'pending_sales_data' => false,
                    'estimated_sales_data' => false,
                    'estimated_fee_data' => false,
                    'days' => [],
                ];
            }

            $weekly[$weekStart]['sales_gbp'] += (float) ($day['sales_gbp'] ?? 0.0);
            $weekly[$weekStart]['ad_gbp'] += (float) ($day['ad_gbp'] ?? 0.0);
            $weekly[$weekStart]['fees_gbp'] += (float) ($day['fees_gbp'] ?? 0.0);
            $weekly[$weekStart]['order_count'] += (int) ($day['order_count'] ?? 0);
            $weekly[$weekStart]['units'] += (int) ($day['units'] ?? 0);
            $weekly[$weekStart]['pending_sales_data'] = $weekly[$weekStart]['pending_sales_data'] || (bool) ($day['pending_sales_data'] ?? false);
            $weekly[$weekStart]['estimated_sales_data'] = $weekly[$weekStart]['estimated_sales_data'] || (bool) ($day['estimated_sales_data'] ?? false);
            $weekly[$weekStart]['estimated_fee_data'] = $weekly[$weekStart]['estimated_fee_data'] || (bool) ($day['estimated_fee_data'] ?? false);
            $weekly[$weekStart]['days'][] = $day;
        }

        krsort($weekly);
        $weeklyRows = array_values($weekly);
        foreach ($weeklyRows as &$week) {
            usort($week['days'], fn ($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
            $sales = (float) ($week['sales_gbp'] ?? 0.0);
            $ad = (float) ($week['ad_gbp'] ?? 0.0);
            $week['acos_percent'] = $sales > 0 ? ($ad / $sales) * 100 : null;
        }
        unset($week);

        $ordersSyncLast = $this->latestById('order_sync_runs', 'finished_at', "UPPER(COALESCE(status, '')) = 'SUCCESS'");
        $pendingEstimateLast = $this->latestById('order_items', 'estimated_line_estimated_at', 'estimated_line_estimated_at IS NOT NULL');
        $adsRequestedLast = $this->latestById('amazon_ads_report_requests', 'requested_at', 'requested_at IS NOT NULL');
        $adsProcessedLast = $this->latestById('amazon_ads_report_requests', 'processed_at', 'processed_at IS NOT NULL');
        $metricsRefreshLast = DB::table('daily_region_metrics')->orderByDesc('id')->value('updated_at');

        $pipelineStatus = [
            [
                'name' => 'Orders Sync',
                'schedule' => 'every 5 minutes',
                'last_refresh' => $this->formatRefreshTime($ordersSyncLast),
            ],
            [
                'name' => 'Pending Estimate Refresh',
                'schedule' => 'every 30 minutes',
                'last_refresh' => $this->formatRefreshTime($pendingEstimateLast),
            ],
            [
                'name' => 'Ads Queue (baseline)',
                'schedule' => 'daily 04:40',
                'last_refresh' => $this->formatRefreshTime($adsRequestedLast),
            ],
            [
                'name' => 'Ads Queue (today)',
                'schedule' => 'every 5 minutes (from=today to=today)',
                'last_refresh' => $this->formatRefreshTime($adsRequestedLast),
            ],
            [
                'name' => 'Ads Queue (yesterday)',
                'schedule' => 'hourly (from=yesterday to=yesterday)',
                'last_refresh' => $this->formatRefreshTime($adsRequestedLast),
            ],
            [
                'name' => 'Ads Queue (2-7 days)',
                'schedule' => 'every 8 hours (from=now-7d to=now-2d)',
                'last_refresh' => $this->formatRefreshTime($adsRequestedLast),
            ],
            [
                'name' => 'Ads Queue (7-30 days)',
                'schedule' => 'daily (from=now-30d to=now-7d)',
                'last_refresh' => $this->formatRefreshTime($adsRequestedLast),
            ],
            [
                'name' => 'Ads Report Poll + Ingest',
                'schedule' => 'every 5 minutes',
                'last_refresh' => $this->formatRefreshTime($adsProcessedLast),
            ],
            [
                'name' => 'Metrics Refresh',
                'schedule' => 'daily 05:00 (+ poll-triggered refresh)',
                'last_refresh' => $this->formatRefreshTime($metricsRefreshLast),
            ],
        ];

        return view('metrics.index', [
            'rows' => $dailyRows,
            'weeklyRows' => $weeklyRows,
            'from' => $from,
            'to' => $to,
            'pipelineStatus' => $pipelineStatus,
        ]);
    }

    private function currencySymbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            'SEK' => 'kr',
            'PLN' => 'zł',
            default => strtoupper($currency) . ' ',
        };
    }

    private function latestById(string $table, string $column, ?string $rawWhere = null): mixed
    {
        $query = DB::table($table);
        if ($rawWhere) {
            $query->whereRaw($rawWhere);
        }

        return $query->orderByDesc('id')->value($column);
    }

    private function normalizeMarketplaceCode(string $countryCode): string
    {
        $code = strtoupper(trim($countryCode));
        if ($code === 'UK') {
            return 'GB';
        }

        return $code;
    }

    private function regionForCountry(string $countryCode): string
    {
        $code = strtoupper(trim($countryCode));
        if (in_array($code, ['GB', 'UK'], true)) {
            return 'UK';
        }
        if (in_array($code, ['US', 'CA', 'MX', 'BR'], true)) {
            return 'NA';
        }

        return 'EU';
    }

    private function formatRefreshTime($value): string
    {
        if (empty($value)) {
            return 'Never';
        }

        try {
            $time = Carbon::parse((string) $value);
            return $time->format('Y-m-d H:i:s') . ' (' . $time->diffForHumans() . ')';
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
