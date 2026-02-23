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
        $metricDateExpr = "COALESCE(orders.purchase_date_local_date, DATE(orders.purchase_date))";

        $itemTotals = DB::table('order_items')
            ->selectRaw("
                amazon_order_id,
                MAX(COALESCE(line_net_currency, estimated_line_currency, item_price_currency, '')) as item_currency,
                SUM(COALESCE(line_net_ex_tax, estimated_line_net_ex_tax, 0)) as item_total,
                SUM(CASE WHEN COALESCE(line_net_ex_tax, 0) <= 0 AND COALESCE(estimated_line_net_ex_tax, 0) > 0 THEN 1 ELSE 0 END) as estimated_item_count,
                SUM(COALESCE(quantity_ordered, 0)) as item_units
            ")
            ->groupBy('amazon_order_id');

        $salesRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->selectRaw("
                {$metricDateExpr} as metric_date,
                marketplaces.country_code as country_code,
                COALESCE(
                    NULLIF(orders.order_net_ex_tax_currency, ''),
                    NULLIF(item_totals.item_currency, ''),
                    NULLIF(orders.order_total_currency, ''),
                    NULLIF(marketplaces.default_currency, ''),
                    'GBP'
                ) as currency,
                SUM(
                    CASE
                        WHEN COALESCE(orders.order_net_ex_tax, 0) > 0 THEN orders.order_net_ex_tax
                        ELSE COALESCE(item_totals.item_total, 0)
                    END
                ) as sales_amount,
                SUM(
                    CASE
                        WHEN COALESCE(orders.order_net_ex_tax, 0) <= 0 AND COALESCE(item_totals.estimated_item_count, 0) > 0 THEN 1
                        ELSE 0
                    END
                ) as estimated_order_count,
                COUNT(*) as order_count
            ")
            ->whereRaw("{$metricDateExpr} >= ?", [$from])
            ->whereRaw("{$metricDateExpr} <= ?", [$to])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->groupByRaw("
                {$metricDateExpr},
                marketplaces.country_code,
                COALESCE(NULLIF(orders.order_net_ex_tax_currency, ''), NULLIF(item_totals.item_currency, ''), NULLIF(orders.order_total_currency, ''), NULLIF(marketplaces.default_currency, ''), 'GBP')
            ")
            ->get();

        $pendingSalesRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->selectRaw("
                {$metricDateExpr} as metric_date,
                marketplaces.country_code as country_code,
                COUNT(*) as pending_count
            ")
            ->whereRaw("{$metricDateExpr} >= ?", [$from])
            ->whereRaw("{$metricDateExpr} <= ?", [$to])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) IN ('PENDING', 'UNSHIPPED')")
            ->whereRaw("COALESCE(orders.order_net_ex_tax, 0) <= 0")
            ->whereRaw("COALESCE(item_totals.item_total, 0) <= 0")
            ->groupByRaw("{$metricDateExpr}, marketplaces.country_code")
            ->get();

        $unitRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoin('order_items', 'order_items.amazon_order_id', '=', 'orders.amazon_order_id')
            ->selectRaw("
                {$metricDateExpr} as metric_date,
                marketplaces.country_code as country_code,
                SUM(COALESCE(order_items.quantity_ordered, 0)) as units
            ")
            ->whereRaw("{$metricDateExpr} >= ?", [$from])
            ->whereRaw("{$metricDateExpr} <= ?", [$to])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->groupByRaw("{$metricDateExpr}, marketplaces.country_code")
            ->get();

        $feeCurrencyExpr = "COALESCE(
                NULLIF(orders.amazon_fee_currency_v2, ''),
                NULLIF(orders.amazon_fee_currency, ''),
                NULLIF(orders.amazon_fee_estimated_currency, ''),
                NULLIF(orders.order_net_ex_tax_currency, ''),
                NULLIF(orders.order_total_currency, ''),
                'GBP'
            )";
        $feeAmountExpr = "CASE
                WHEN orders.amazon_fee_total_v2 IS NOT NULL THEN orders.amazon_fee_total_v2
                WHEN orders.amazon_fee_total IS NOT NULL THEN orders.amazon_fee_total
                ELSE COALESCE(orders.amazon_fee_estimated_total, 0)
            END";
        $estimatedFeeOrderExpr = "CASE
                WHEN orders.amazon_fee_total_v2 IS NULL
                    AND orders.amazon_fee_total IS NULL
                    AND COALESCE(orders.amazon_fee_estimated_total, 0) <> 0 THEN 1
                ELSE 0
            END";

        $feeRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->selectRaw("
                {$metricDateExpr} as metric_date,
                marketplaces.country_code as country_code,
                {$feeCurrencyExpr} as currency,
                SUM({$feeAmountExpr}) as fee_amount,
                SUM({$estimatedFeeOrderExpr}) as estimated_fee_order_count
            ")
            ->whereRaw("{$metricDateExpr} >= ?", [$from])
            ->whereRaw("{$metricDateExpr} <= ?", [$to])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->groupByRaw("
                {$metricDateExpr},
                marketplaces.country_code,
                {$feeCurrencyExpr}
            ")
            ->get();

        $latestAdsRequests = DB::table('amazon_ads_report_daily_spends as ds_latest')
            ->join('amazon_ads_report_requests as rr_latest', 'rr_latest.id', '=', 'ds_latest.report_request_id')
            ->selectRaw("
                ds_latest.metric_date as metric_date,
                ds_latest.profile_id as profile_id,
                rr_latest.ad_product as ad_product,
                MAX(rr_latest.id) as latest_request_id
            ")
            ->groupBy('ds_latest.metric_date', 'ds_latest.profile_id', 'rr_latest.ad_product');

        $adRows = DB::table('amazon_ads_report_daily_spends as ds')
            ->join('amazon_ads_report_requests as rr', 'rr.id', '=', 'ds.report_request_id')
            ->joinSub($latestAdsRequests, 'latest_ads', function ($join) {
                $join->on('latest_ads.metric_date', '=', 'ds.metric_date')
                    ->on('latest_ads.profile_id', '=', 'ds.profile_id')
                    ->on('latest_ads.ad_product', '=', 'rr.ad_product')
                    ->on('latest_ads.latest_request_id', '=', 'rr.id');
            })
            ->selectRaw("
                ds.metric_date as metric_date,
                rr.country_code as country_code,
                COALESCE(ds.source_currency, rr.currency_code, ds.currency, 'GBP') as currency,
                SUM(COALESCE(ds.source_amount, ds.amount_local)) as ad_amount
            ")
            ->whereDate('ds.metric_date', '>=', $from)
            ->whereDate('ds.metric_date', '<=', $to)
            ->groupBy('ds.metric_date', 'rr.country_code', 'ds.source_currency', 'rr.currency_code', 'ds.currency')
            ->get();

        $dates = [];
        $breakdown = [];

        foreach ($salesRows as $row) {
            $date = (string) $row->metric_date;
            $country = $this->normalizeMarketplaceCode((string) $row->country_code);
            $currency = strtoupper((string) $row->currency);
            $amount = (float) $row->sales_amount;
            if ($date === '' || $country === '') {
                continue;
            }

            $dates[$date] = true;
            $key = $date . '|' . $country;
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'date' => $date,
                    'country' => $country,
                    'currency' => $currency,
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'ad_local' => 0.0,
                    'ad_gbp' => 0.0,
                    'fees_local' => 0.0,
                    'fees_gbp' => 0.0,
                    'estimated_fee_data' => false,
                    'pending_sales_data' => false,
                    'estimated_sales_data' => false,
                ];
            }

            $breakdown[$key]['currency'] = $currency;
            $breakdown[$key]['sales_local'] += $amount;
            $breakdown[$key]['sales_gbp'] += $fxRateService->convert($amount, $currency, 'GBP', $date) ?? 0.0;
            $breakdown[$key]['order_count'] += (int) ($row->order_count ?? 0);
            $breakdown[$key]['estimated_sales_data'] = $breakdown[$key]['estimated_sales_data']
                || ((int) ($row->estimated_order_count ?? 0) > 0);
        }

        foreach ($unitRows as $row) {
            $date = (string) $row->metric_date;
            $country = $this->normalizeMarketplaceCode((string) $row->country_code);
            if ($date === '' || $country === '') {
                continue;
            }

            $dates[$date] = true;
            $key = $date . '|' . $country;
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'date' => $date,
                    'country' => $country,
                    'currency' => 'GBP',
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'ad_local' => 0.0,
                    'ad_gbp' => 0.0,
                    'fees_local' => 0.0,
                    'fees_gbp' => 0.0,
                    'estimated_fee_data' => false,
                    'pending_sales_data' => false,
                    'estimated_sales_data' => false,
                ];
            }
            $breakdown[$key]['units'] += (int) ($row->units ?? 0);
        }

        foreach ($pendingSalesRows as $row) {
            $date = (string) $row->metric_date;
            $country = $this->normalizeMarketplaceCode((string) $row->country_code);
            if ($date === '' || $country === '') {
                continue;
            }

            $dates[$date] = true;
            $key = $date . '|' . $country;
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'date' => $date,
                    'country' => $country,
                    'currency' => 'GBP',
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'ad_local' => 0.0,
                    'ad_gbp' => 0.0,
                    'fees_local' => 0.0,
                    'fees_gbp' => 0.0,
                    'estimated_fee_data' => false,
                    'pending_sales_data' => false,
                    'estimated_sales_data' => false,
                ];
            }
            $breakdown[$key]['pending_sales_data'] = ((int) ($row->pending_count ?? 0)) > 0;
        }

        foreach ($adRows as $row) {
            $date = (string) $row->metric_date;
            $country = $this->normalizeMarketplaceCode((string) $row->country_code);
            $currency = strtoupper((string) $row->currency);
            $amount = (float) $row->ad_amount;
            if ($date === '' || $country === '') {
                continue;
            }

            $dates[$date] = true;
            $key = $date . '|' . $country;
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'date' => $date,
                    'country' => $country,
                    'currency' => $currency,
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'ad_local' => 0.0,
                    'ad_gbp' => 0.0,
                    'fees_local' => 0.0,
                    'fees_gbp' => 0.0,
                    'estimated_fee_data' => false,
                    'pending_sales_data' => false,
                    'estimated_sales_data' => false,
                ];
            }

            $breakdown[$key]['currency'] = $currency;
            $breakdown[$key]['ad_local'] += $amount;
            $breakdown[$key]['ad_gbp'] += $fxRateService->convert($amount, $currency, 'GBP', $date) ?? 0.0;
        }

        foreach ($feeRows as $row) {
            $date = (string) $row->metric_date;
            $country = $this->normalizeMarketplaceCode((string) $row->country_code);
            $currency = strtoupper((string) $row->currency);
            $amount = (float) $row->fee_amount;
            if ($date === '' || $country === '') {
                continue;
            }

            $dates[$date] = true;
            $key = $date . '|' . $country;
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'date' => $date,
                    'country' => $country,
                    'currency' => $currency,
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'ad_local' => 0.0,
                    'ad_gbp' => 0.0,
                    'fees_local' => 0.0,
                    'fees_gbp' => 0.0,
                    'estimated_fee_data' => false,
                    'pending_sales_data' => false,
                    'estimated_sales_data' => false,
                ];
            }

            $breakdown[$key]['fees_local'] += $amount;
            $breakdown[$key]['fees_gbp'] += $fxRateService->convert($amount, $currency, 'GBP', $date) ?? 0.0;
            $breakdown[$key]['estimated_fee_data'] = $breakdown[$key]['estimated_fee_data']
                || ((int) ($row->estimated_fee_order_count ?? 0) > 0);
        }

        $dailyRows = [];
        $dateList = array_keys($dates);
        rsort($dateList);

        foreach ($dateList as $date) {
            $items = [];
            $totalSalesGbp = 0.0;
            $totalAdGbp = 0.0;
            $totalFeesGbp = 0.0;
            $totalOrders = 0;
            $totalUnits = 0;
            $hasPendingSalesData = false;
            $hasEstimatedSalesData = false;
            $hasEstimatedFeeData = false;

            foreach ($breakdown as $entry) {
                if ($entry['date'] !== $date) {
                    continue;
                }

                $acos = $entry['sales_gbp'] > 0 ? ($entry['ad_gbp'] / $entry['sales_gbp']) * 100 : null;
                $entry['acos_percent'] = $acos;
                $entry['currency_symbol'] = $this->currencySymbol($entry['currency']);
                $items[] = $entry;
                $totalSalesGbp += $entry['sales_gbp'];
                $totalAdGbp += $entry['ad_gbp'];
                $totalFeesGbp += $entry['fees_gbp'];
                $totalOrders += (int) ($entry['order_count'] ?? 0);
                $totalUnits += (int) ($entry['units'] ?? 0);
                $hasPendingSalesData = $hasPendingSalesData || (bool) ($entry['pending_sales_data'] ?? false);
                $hasEstimatedSalesData = $hasEstimatedSalesData || (bool) ($entry['estimated_sales_data'] ?? false);
                $hasEstimatedFeeData = $hasEstimatedFeeData || (bool) ($entry['estimated_fee_data'] ?? false);
            }

            usort($items, fn ($a, $b) => strcmp($a['country'], $b['country']));
            $dailyRows[] = [
                'date' => $date,
                'sales_gbp' => $totalSalesGbp,
                'ad_gbp' => $totalAdGbp,
                'fees_gbp' => $totalFeesGbp,
                'order_count' => $totalOrders,
                'units' => $totalUnits,
                'acos_percent' => $totalSalesGbp > 0 ? ($totalAdGbp / $totalSalesGbp) * 100 : null,
                'pending_sales_data' => $hasPendingSalesData,
                'estimated_sales_data' => $hasEstimatedSalesData,
                'estimated_fee_data' => $hasEstimatedFeeData,
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

        $pipelineStatus = [
            [
                'name' => 'Orders Sync',
                'schedule' => 'every 5 minutes',
                'last_refresh' => $this->formatRefreshTime(
                    DB::table('order_sync_runs')
                        ->whereRaw("UPPER(COALESCE(status, '')) = 'SUCCESS'")
                        ->max('finished_at')
                ),
            ],
            [
                'name' => 'Pending Estimate Refresh',
                'schedule' => 'every 30 minutes',
                'last_refresh' => $this->formatRefreshTime(
                    DB::table('order_items')->max('estimated_line_estimated_at')
                ),
            ],
            [
                'name' => 'Ads Queue (baseline)',
                'schedule' => 'daily 04:40',
                'last_refresh' => $this->formatRefreshTime(
                    DB::table('amazon_ads_report_requests')->max('requested_at')
                ),
            ],
            [
                'name' => 'Ads Queue (today)',
                'schedule' => 'every 5 minutes (from=today to=today)',
                'last_refresh' => $this->formatRefreshTime(
                    DB::table('amazon_ads_report_requests')->max('requested_at')
                ),
            ],
            [
                'name' => 'Ads Queue (yesterday)',
                'schedule' => 'hourly (from=yesterday to=yesterday)',
                'last_refresh' => $this->formatRefreshTime(
                    DB::table('amazon_ads_report_requests')->max('requested_at')
                ),
            ],
            [
                'name' => 'Ads Queue (2-7 days)',
                'schedule' => 'every 8 hours (from=now-7d to=now-2d)',
                'last_refresh' => $this->formatRefreshTime(
                    DB::table('amazon_ads_report_requests')->max('requested_at')
                ),
            ],
            [
                'name' => 'Ads Queue (7-30 days)',
                'schedule' => 'daily (from=now-30d to=now-7d)',
                'last_refresh' => $this->formatRefreshTime(
                    DB::table('amazon_ads_report_requests')->max('requested_at')
                ),
            ],
            [
                'name' => 'Ads Report Poll + Ingest',
                'schedule' => 'every 5 minutes',
                'last_refresh' => $this->formatRefreshTime(
                    DB::table('amazon_ads_report_requests')->max('processed_at')
                ),
            ],
            [
                'name' => 'Metrics Refresh',
                'schedule' => 'daily 05:00 (+ poll-triggered refresh)',
                'last_refresh' => $this->formatRefreshTime(
                    DB::table('daily_region_metrics')->max('updated_at')
                ),
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

    private function normalizeMarketplaceCode(string $countryCode): string
    {
        $code = strtoupper(trim($countryCode));
        if ($code === 'UK') {
            return 'GB';
        }

        return $code;
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
