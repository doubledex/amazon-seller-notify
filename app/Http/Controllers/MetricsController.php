<?php

namespace App\Http\Controllers;

use App\Services\FxRateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $adSpendByDateRegion = [];
        foreach ($metricRows as $row) {
            $date = (string) $row->metric_date;
            if ($date === '') {
                continue;
            }

            if (!isset($byDate[$date])) {
                $byDate[$date] = [];
            }
            $byDate[$date][] = $row;

            $region = strtoupper((string) ($row->region ?? ''));
            if ($region === '') {
                continue;
            }

            $key = $date . '|' . $region;
            $adSpendByDateRegion[$key] = [
                'ad_local' => (float) ($row->ad_spend_local ?? 0.0),
                'ad_gbp' => (float) ($row->ad_spend_gbp ?? 0.0),
                'local_currency' => strtoupper((string) ($row->local_currency ?? $this->regionLocalCurrency($region))),
            ];
        }

        $adFallbackRows = DB::table('daily_region_ad_spends')
            ->selectRaw('metric_date, region, currency, SUM(amount_local) as amount')
            ->whereDate('metric_date', '>=', $from)
            ->whereDate('metric_date', '<=', $to)
            ->groupByRaw('metric_date, region, currency')
            ->get();

        foreach ($adFallbackRows as $row) {
            $date = (string) ($row->metric_date ?? '');
            $region = strtoupper((string) ($row->region ?? ''));
            $fromCurrency = strtoupper((string) ($row->currency ?? ''));
            $amount = (float) ($row->amount ?? 0.0);
            if ($date === '' || $region === '' || $fromCurrency === '' || $amount == 0.0) {
                continue;
            }

            $localCurrency = $this->regionLocalCurrency($region);
            $localConverted = $fxRateService->convert($amount, $fromCurrency, $localCurrency, $date);
            $gbpConverted = $fxRateService->convert($amount, $fromCurrency, 'GBP', $date);
            if ($localConverted === null || $gbpConverted === null) {
                continue;
            }

            $key = $date . '|' . $region;
            if (!isset($adSpendByDateRegion[$key])) {
                $adSpendByDateRegion[$key] = [
                    'ad_local' => 0.0,
                    'ad_gbp' => 0.0,
                    'local_currency' => $localCurrency,
                ];
            }

            if (($adSpendByDateRegion[$key]['ad_local'] ?? 0.0) == 0.0 && ($adSpendByDateRegion[$key]['ad_gbp'] ?? 0.0) == 0.0) {
                $adSpendByDateRegion[$key]['ad_local'] += $localConverted;
                $adSpendByDateRegion[$key]['ad_gbp'] += $gbpConverted;
            }
        }

        $metricDateExpr = "COALESCE(orders.purchase_date_local_date, DATE(orders.purchase_date))";
        $hasQuantityShipped = Schema::hasColumn('order_items', 'quantity_shipped');
        $hasEstimatedLineCurrency = Schema::hasColumn('order_items', 'estimated_line_currency');
        $hasEstimatedLineNet = Schema::hasColumn('order_items', 'estimated_line_net_ex_tax');
        $hasFeeTotalV2 = Schema::hasColumn('orders', 'amazon_fee_total_v2');
        $hasFeeCurrencyV2 = Schema::hasColumn('orders', 'amazon_fee_currency_v2');
        $hasFeeTotal = Schema::hasColumn('orders', 'amazon_fee_total');
        $hasFeeCurrency = Schema::hasColumn('orders', 'amazon_fee_currency');
        $hasFeeEstimatedTotal = Schema::hasColumn('orders', 'amazon_fee_estimated_total');
        $hasFeeEstimatedCurrency = Schema::hasColumn('orders', 'amazon_fee_estimated_currency');

        $itemCurrencyFallbackExpr = $hasEstimatedLineCurrency
            ? "COALESCE(line_net_currency, estimated_line_currency, item_price_currency, '')"
            : "COALESCE(line_net_currency, item_price_currency, '')";
        $itemNetFallbackExpr = $hasEstimatedLineNet
            ? "COALESCE(line_net_ex_tax, estimated_line_net_ex_tax, 0)"
            : "COALESCE(line_net_ex_tax, 0)";
        $unitsExpr = $hasQuantityShipped
            ? "CASE WHEN COALESCE(quantity_shipped, 0) > 0 THEN COALESCE(quantity_shipped, 0) ELSE COALESCE(quantity_ordered, 0) END"
            : "COALESCE(quantity_ordered, 0)";
        $itemTotals = DB::table('order_items')
            ->selectRaw("
                amazon_order_id,
                MAX({$itemCurrencyFallbackExpr}) as item_currency,
                SUM({$itemNetFallbackExpr}) as item_total,
                SUM({$unitsExpr}) as units_total
            ")
            ->groupBy('amazon_order_id');

        $salesCurrencyExpr = "COALESCE(NULLIF(orders.order_net_ex_tax_currency, ''), NULLIF(item_totals.item_currency, ''), NULLIF(orders.order_total_currency, ''), NULLIF(marketplaces.default_currency, ''), 'GBP')";
        $feeCurrencyParts = [];
        if ($hasFeeCurrencyV2) {
            $feeCurrencyParts[] = "NULLIF(orders.amazon_fee_currency_v2, '')";
        }
        if ($hasFeeCurrency) {
            $feeCurrencyParts[] = "NULLIF(orders.amazon_fee_currency, '')";
        }
        if ($hasFeeEstimatedCurrency) {
            $feeCurrencyParts[] = "NULLIF(orders.amazon_fee_estimated_currency, '')";
        }
        $feeCurrencyParts[] = $salesCurrencyExpr;
        $feeCurrencyExpr = 'COALESCE(' . implode(', ', $feeCurrencyParts) . ')';

        $feeAmountExpr = '0';
        if ($hasFeeTotalV2 && $hasFeeTotal && $hasFeeEstimatedTotal) {
            $feeAmountExpr = "CASE WHEN orders.amazon_fee_total_v2 IS NOT NULL THEN orders.amazon_fee_total_v2 WHEN orders.amazon_fee_total IS NOT NULL THEN orders.amazon_fee_total ELSE COALESCE(orders.amazon_fee_estimated_total, 0) END";
        } elseif ($hasFeeTotalV2 && $hasFeeTotal) {
            $feeAmountExpr = "CASE WHEN orders.amazon_fee_total_v2 IS NOT NULL THEN orders.amazon_fee_total_v2 ELSE COALESCE(orders.amazon_fee_total, 0) END";
        } elseif ($hasFeeTotalV2 && $hasFeeEstimatedTotal) {
            $feeAmountExpr = "CASE WHEN orders.amazon_fee_total_v2 IS NOT NULL THEN orders.amazon_fee_total_v2 ELSE COALESCE(orders.amazon_fee_estimated_total, 0) END";
        } elseif ($hasFeeTotal && $hasFeeEstimatedTotal) {
            $feeAmountExpr = "CASE WHEN orders.amazon_fee_total IS NOT NULL THEN orders.amazon_fee_total ELSE COALESCE(orders.amazon_fee_estimated_total, 0) END";
        } elseif ($hasFeeTotalV2) {
            $feeAmountExpr = 'COALESCE(orders.amazon_fee_total_v2, 0)';
        } elseif ($hasFeeTotal) {
            $feeAmountExpr = 'COALESCE(orders.amazon_fee_total, 0)';
        } elseif ($hasFeeEstimatedTotal) {
            $feeAmountExpr = 'COALESCE(orders.amazon_fee_estimated_total, 0)';
        }

        $estimatedFeeCountExpr = '0';
        if ($hasFeeEstimatedTotal && $hasFeeTotalV2 && $hasFeeTotal) {
            $estimatedFeeCountExpr = 'SUM(CASE WHEN orders.amazon_fee_total_v2 IS NULL AND orders.amazon_fee_total IS NULL AND orders.amazon_fee_estimated_total IS NOT NULL THEN 1 ELSE 0 END)';
        } elseif ($hasFeeEstimatedTotal && $hasFeeTotal) {
            $estimatedFeeCountExpr = 'SUM(CASE WHEN orders.amazon_fee_total IS NULL AND orders.amazon_fee_estimated_total IS NOT NULL THEN 1 ELSE 0 END)';
        } elseif ($hasFeeEstimatedTotal && $hasFeeTotalV2) {
            $estimatedFeeCountExpr = 'SUM(CASE WHEN orders.amazon_fee_total_v2 IS NULL AND orders.amazon_fee_estimated_total IS NOT NULL THEN 1 ELSE 0 END)';
        } elseif ($hasFeeEstimatedTotal) {
            $estimatedFeeCountExpr = 'SUM(CASE WHEN orders.amazon_fee_estimated_total IS NOT NULL THEN 1 ELSE 0 END)';
        }
        $countrySalesRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->selectRaw("
                {$metricDateExpr} as metric_date,
                UPPER(COALESCE(marketplaces.country_code, '')) as country_code,
                {$salesCurrencyExpr} as currency,
                {$feeCurrencyExpr} as fee_currency,
                SUM(
                    CASE
                        WHEN COALESCE(orders.order_net_ex_tax, 0) > 0 THEN orders.order_net_ex_tax
                        ELSE COALESCE(orders.order_total_amount, 0)
                    END
                ) as sales_amount,
                SUM({$feeAmountExpr}) as fees_amount,
                COUNT(*) as order_count
            ")
            ->selectRaw('SUM(COALESCE(item_totals.units_total, 0)) as unit_count')
            ->selectRaw("{$estimatedFeeCountExpr} as estimated_fee_count")
            ->whereRaw("{$metricDateExpr} >= ?", [$from])
            ->whereRaw("{$metricDateExpr} <= ?", [$to])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->groupByRaw("
                {$metricDateExpr},
                UPPER(COALESCE(marketplaces.country_code, '')),
                {$salesCurrencyExpr},
                {$feeCurrencyExpr}
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
            $feeCurrency = strtoupper((string) ($row->fee_currency ?? $currency));
            $salesLocal = (float) ($row->sales_amount ?? 0);
            $salesGbp = $fxRateService->convert($salesLocal, $currency, 'GBP', $date) ?? 0.0;
            $feesLocalRaw = (float) ($row->fees_amount ?? 0);
            $feesLocal = $fxRateService->convert($feesLocalRaw, $feeCurrency, $currency, $date) ?? 0.0;
            $feesGbp = $fxRateService->convert($feesLocalRaw, $feeCurrency, 'GBP', $date) ?? 0.0;
            $key = $date . '|' . $region . '|' . $country . '|' . $currency;

            if (!isset($countryByDateRegion[$key])) {
                $countryByDateRegion[$key] = [
                    'date' => $date,
                    'region' => $region,
                    'country' => $country,
                    'currency' => $currency,
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'fees_local' => 0.0,
                    'fees_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'estimated_fee_data' => false,
                ];
            }

            $countryByDateRegion[$key]['sales_local'] += $salesLocal;
            $countryByDateRegion[$key]['sales_gbp'] += $salesGbp;
            $countryByDateRegion[$key]['fees_local'] += $feesLocal;
            $countryByDateRegion[$key]['fees_gbp'] += $feesGbp;
            $countryByDateRegion[$key]['order_count'] += (int) ($row->order_count ?? 0);
            $countryByDateRegion[$key]['units'] += (int) ($row->unit_count ?? 0);
            $countryByDateRegion[$key]['estimated_fee_data'] = $countryByDateRegion[$key]['estimated_fee_data'] || ((int) ($row->estimated_fee_count ?? 0) > 0);
        }

        $dailyRows = [];
        $allDates = array_values(array_unique(array_merge(
            array_keys($byDate),
            array_values(array_unique(array_map(fn (array $row): string => (string) ($row['date'] ?? ''), array_values($countryByDateRegion)))),
            array_values(array_unique(array_map(fn (string $key): string => explode('|', $key)[0] ?? '', array_keys($adSpendByDateRegion))))
        )));
        rsort($allDates);

        foreach ($allDates as $date) {
            if ($date === '') {
                continue;
            }

            $rows = $byDate[$date] ?? [];
            $items = [];
            $regionSet = [];
            foreach ($rows as $row) {
                $region = strtoupper((string) ($row->region ?? ''));
                if ($region !== '') {
                    $regionSet[$region] = true;
                }
            }
            foreach ($countryByDateRegion as $countryRow) {
                if (($countryRow['date'] ?? '') === $date) {
                    $region = strtoupper((string) ($countryRow['region'] ?? ''));
                    if ($region !== '') {
                        $regionSet[$region] = true;
                    }
                }
            }
            foreach (array_keys($adSpendByDateRegion) as $adKey) {
                [$adDate, $adRegion] = explode('|', $adKey) + [null, null];
                if ($adDate === $date && is_string($adRegion) && $adRegion !== '') {
                    $regionSet[strtoupper($adRegion)] = true;
                }
            }
            $regions = array_keys($regionSet);
            sort($regions);

            foreach ($regions as $region) {
                $adKey = $date . '|' . $region;
                $regionAdLocal = (float) ($adSpendByDateRegion[$adKey]['ad_local'] ?? 0.0);
                $regionAdGbp = (float) ($adSpendByDateRegion[$adKey]['ad_gbp'] ?? 0.0);
                $regionCountryRows = array_values(array_filter(
                    $countryByDateRegion,
                    fn (array $r): bool => $r['date'] === $date && $r['region'] === $region
                ));
                $regionCountrySalesGbp = 0.0;
                $regionCountryOrders = 0;
                foreach ($regionCountryRows as $regionCountryRow) {
                    $regionCountrySalesGbp += (float) ($regionCountryRow['sales_gbp'] ?? 0);
                    $regionCountryOrders += (int) ($regionCountryRow['order_count'] ?? 0);
                }

                if (empty($regionCountryRows)) {
                    $currency = $this->regionLocalCurrency($region);
                    $items[] = [
                        'date' => $date,
                        'country' => $region,
                        'currency' => $currency,
                        'currency_symbol' => $this->currencySymbol($currency),
                        'sales_local' => 0.0,
                        'sales_gbp' => 0.0,
                        'order_count' => 0,
                        'units' => 0,
                        'ad_local' => $regionAdLocal,
                        'ad_gbp' => $regionAdGbp,
                        'fees_local' => 0.0,
                        'fees_gbp' => 0.0,
                        'estimated_fee_data' => false,
                        'pending_sales_data' => false,
                        'estimated_sales_data' => false,
                        'acos_percent' => null,
                    ];
                    continue;
                }

                $countryCount = count($regionCountryRows);
                foreach ($regionCountryRows as $regionCountryRow) {
                    if ($regionCountrySalesGbp > 0) {
                        $ratio = ((float) $regionCountryRow['sales_gbp'] / $regionCountrySalesGbp);
                    } elseif ($regionCountryOrders > 0) {
                        $ratio = ((int) ($regionCountryRow['order_count'] ?? 0)) / $regionCountryOrders;
                    } else {
                        $ratio = $countryCount > 0 ? (1 / $countryCount) : 0.0;
                    }
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
                        'units' => (int) ($regionCountryRow['units'] ?? 0),
                        'ad_local' => $adLocal,
                        'ad_gbp' => $adGbp,
                        'fees_local' => (float) ($regionCountryRow['fees_local'] ?? 0.0),
                        'fees_gbp' => (float) ($regionCountryRow['fees_gbp'] ?? 0.0),
                        'estimated_fee_data' => (bool) ($regionCountryRow['estimated_fee_data'] ?? false),
                        'pending_sales_data' => false,
                        'estimated_sales_data' => false,
                        'acos_percent' => $salesGbp > 0 ? ($adGbp / $salesGbp) * 100 : null,
                    ];
                }
            }

            usort($items, fn ($a, $b) => strcmp((string) $a['country'], (string) $b['country']));
            $totalSalesGbp = (float) array_sum(array_map(fn (array $item): float => (float) ($item['sales_gbp'] ?? 0.0), $items));
            $totalAdGbp = (float) array_sum(array_map(fn (array $item): float => (float) ($item['ad_gbp'] ?? 0.0), $items));
            $totalFeesGbp = (float) array_sum(array_map(fn (array $item): float => (float) ($item['fees_gbp'] ?? 0.0), $items));
            $totalOrders = (int) array_sum(array_map(fn (array $item): int => (int) ($item['order_count'] ?? 0), $items));
            $totalUnits = (int) array_sum(array_map(fn (array $item): int => (int) ($item['units'] ?? 0), $items));
            $estimatedFeeData = in_array(true, array_map(fn (array $item): bool => (bool) ($item['estimated_fee_data'] ?? false), $items), true);

            $dailyRows[] = [
                'date' => $date,
                'sales_gbp' => $totalSalesGbp,
                'ad_gbp' => $totalAdGbp,
                'fees_gbp' => $totalFeesGbp,
                'order_count' => $totalOrders,
                'units' => $totalUnits,
                'acos_percent' => $totalSalesGbp > 0 ? ($totalAdGbp / $totalSalesGbp) * 100 : null,
                'pending_sales_data' => false,
                'estimated_sales_data' => false,
                'estimated_fee_data' => $estimatedFeeData,
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

    private function regionLocalCurrency(string $region): string
    {
        return match (strtoupper(trim($region))) {
            'UK' => 'GBP',
            'NA' => 'USD',
            default => 'EUR',
        };
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
