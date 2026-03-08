<?php

namespace App\Http\Controllers;

use App\Services\FxRateService;
use App\Services\LandedCostResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function index(Request $request, FxRateService $fxRateService, LandedCostResolver $landedCostResolver)
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
                'landed_cost_local',
                'landed_cost_gbp',
                'margin_local',
                'margin_gbp',
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
        $landedCostByDateRegion = [];
        $marginByDateRegion = [];
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

            $landedCostByDateRegion[$key] = [
                'landed_local' => (float) ($row->landed_cost_local ?? 0.0),
                'landed_gbp' => (float) ($row->landed_cost_gbp ?? 0.0),
            ];

            $marginByDateRegion[$key] = [
                'margin_local' => (float) ($row->margin_local ?? 0.0),
                'margin_gbp' => (float) ($row->margin_gbp ?? 0.0),
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

        $adSpendByDateRegionCountry = [];
        if (Schema::hasTable('amazon_ads_report_daily_spends') && Schema::hasTable('amazon_ads_report_requests')) {
            $latestAdsRequests = DB::table('amazon_ads_report_daily_spends as ds_latest')
                ->join('amazon_ads_report_requests as rr_latest', 'rr_latest.id', '=', 'ds_latest.report_request_id')
                ->selectRaw("
                    ds_latest.metric_date as metric_date,
                    ds_latest.profile_id as profile_id,
                    rr_latest.ad_product as ad_product,
                    MAX(rr_latest.id) as latest_request_id
                ")
                ->whereDate('ds_latest.metric_date', '>=', $from)
                ->whereDate('ds_latest.metric_date', '<=', $to)
                ->groupBy('ds_latest.metric_date', 'ds_latest.profile_id', 'rr_latest.ad_product');

            $countryAdRows = DB::table('amazon_ads_report_daily_spends as ds')
                ->join('amazon_ads_report_requests as rr', 'rr.id', '=', 'ds.report_request_id')
                ->joinSub($latestAdsRequests, 'latest_ads', function ($join) {
                    $join->on('latest_ads.metric_date', '=', 'ds.metric_date')
                        ->on('latest_ads.profile_id', '=', 'ds.profile_id')
                        ->on('latest_ads.ad_product', '=', 'rr.ad_product')
                        ->on('latest_ads.latest_request_id', '=', 'rr.id');
                })
                ->selectRaw('ds.metric_date as metric_date, UPPER(COALESCE(rr.country_code, \'\')) as country_code, UPPER(COALESCE(ds.currency, \'\')) as currency, SUM(ds.amount_local) as amount_local')
                ->whereDate('ds.metric_date', '>=', $from)
                ->whereDate('ds.metric_date', '<=', $to)
                ->groupBy('ds.metric_date')
                ->groupByRaw("UPPER(COALESCE(rr.country_code, ''))")
                ->groupByRaw("UPPER(COALESCE(ds.currency, ''))")
                ->get();

            foreach ($countryAdRows as $row) {
                $date = (string) ($row->metric_date ?? '');
                $country = $this->normalizeMarketplaceCode((string) ($row->country_code ?? ''));
                $fromCurrency = strtoupper((string) ($row->currency ?? ''));
                $amountLocal = (float) ($row->amount_local ?? 0.0);
                if ($date === '' || $country === '' || $amountLocal == 0.0) {
                    continue;
                }

                $region = $this->regionForCountry($country);
                $targetCurrency = $this->regionLocalCurrency($region);
                if ($fromCurrency === '') {
                    $fromCurrency = $targetCurrency;
                }

                $localConverted = $fromCurrency === $targetCurrency
                    ? $amountLocal
                    : ($fxRateService->convert($amountLocal, $fromCurrency, $targetCurrency, $date) ?? 0.0);
                $gbpConverted = $fromCurrency === 'GBP'
                    ? $amountLocal
                    : ($fxRateService->convert($amountLocal, $fromCurrency, 'GBP', $date) ?? 0.0);

                $dateRegionKey = $date . '|' . $region;
                if (!isset($adSpendByDateRegionCountry[$dateRegionKey])) {
                    $adSpendByDateRegionCountry[$dateRegionKey] = [];
                }
                if (!isset($adSpendByDateRegionCountry[$dateRegionKey][$country])) {
                    $adSpendByDateRegionCountry[$dateRegionKey][$country] = [
                        'ad_local' => 0.0,
                        'ad_gbp' => 0.0,
                        'currency' => $targetCurrency,
                    ];
                }

                $adSpendByDateRegionCountry[$dateRegionKey][$country]['ad_local'] += (float) $localConverted;
                $adSpendByDateRegionCountry[$dateRegionKey][$country]['ad_gbp'] += (float) $gbpConverted;
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
                        ELSE COALESCE(item_totals.item_total, 0)
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
                    'landed_cost_local' => 0.0,
                    'landed_cost_gbp' => 0.0,
                    'margin_local' => 0.0,
                    'margin_gbp' => 0.0,
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

        $orderFinancialRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->selectRaw("
                orders.amazon_order_id as amazon_order_id,
                {$metricDateExpr} as metric_date,
                UPPER(COALESCE(marketplaces.country_code, '')) as country_code,
                {$salesCurrencyExpr} as sales_currency,
                {$feeCurrencyExpr} as fee_currency,
                CASE
                    WHEN COALESCE(orders.order_net_ex_tax, 0) > 0 THEN orders.order_net_ex_tax
                    ELSE COALESCE(item_totals.item_total, 0)
                END as sales_amount,
                {$feeAmountExpr} as fees_amount
            ")
            ->whereRaw("{$metricDateExpr} >= ?", [$from])
            ->whereRaw("{$metricDateExpr} <= ?", [$to])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->get();

        $orderIdsForLanded = $orderFinancialRows
            ->pluck('amazon_order_id')
            ->filter()
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $landedByOrder = $landedCostResolver->resolveOrderLandedCostsForOrderCurrency($orderIdsForLanded);

        foreach ($orderFinancialRows as $row) {
            $date = (string) ($row->metric_date ?? '');
            $country = $this->normalizeMarketplaceCode((string) ($row->country_code ?? ''));
            $orderId = trim((string) ($row->amazon_order_id ?? ''));
            if ($date === '' || $country === '' || $orderId === '') {
                continue;
            }

            $region = $this->regionForCountry($country);
            $salesCurrency = strtoupper((string) ($row->sales_currency ?? 'GBP'));
            $feeCurrency = strtoupper((string) ($row->fee_currency ?? $salesCurrency));
            if ($salesCurrency === '') {
                $salesCurrency = 'GBP';
            }
            if ($feeCurrency === '') {
                $feeCurrency = $salesCurrency;
            }

            $salesLocal = (float) ($row->sales_amount ?? 0.0);
            $salesGbp = $fxRateService->convert($salesLocal, $salesCurrency, 'GBP', $date) ?? 0.0;
            $feeRaw = abs((float) ($row->fees_amount ?? 0.0));
            $feeInSalesCurrency = (float) ($fxRateService->convert($feeRaw, $feeCurrency, $salesCurrency, $date) ?? 0.0);
            $feeGbp = (float) ($fxRateService->convert($feeRaw, $feeCurrency, 'GBP', $date) ?? 0.0);

            $landed = $landedByOrder[$orderId] ?? null;
            $landedRaw = is_array($landed) ? (float) ($landed['landed_cost_total'] ?? 0.0) : 0.0;
            $landedCurrency = is_array($landed) ? strtoupper(trim((string) ($landed['currency'] ?? ''))) : '';
            if ($landedCurrency === '') {
                $landedCurrency = $salesCurrency;
            }
            $landedInSalesCurrency = $landedCurrency === $salesCurrency
                ? $landedRaw
                : (float) ($fxRateService->convert($landedRaw, $landedCurrency, $salesCurrency, $date) ?? 0.0);
            $landedGbp = $landedCurrency === 'GBP'
                ? $landedRaw
                : (float) ($fxRateService->convert($landedRaw, $landedCurrency, 'GBP', $date) ?? 0.0);

            $marginLocal = $salesLocal - $feeInSalesCurrency - $landedInSalesCurrency;
            $marginGbp = $fxRateService->convert($marginLocal, $salesCurrency, 'GBP', $date) ?? ($salesGbp - $feeGbp - $landedGbp);

            $key = $date . '|' . $region . '|' . $country . '|' . $salesCurrency;
            if (!isset($countryByDateRegion[$key])) {
                $countryByDateRegion[$key] = [
                    'date' => $date,
                    'region' => $region,
                    'country' => $country,
                    'currency' => $salesCurrency,
                    'sales_local' => 0.0,
                    'sales_gbp' => 0.0,
                    'fees_local' => 0.0,
                    'fees_gbp' => 0.0,
                    'landed_cost_local' => 0.0,
                    'landed_cost_gbp' => 0.0,
                    'margin_local' => 0.0,
                    'margin_gbp' => 0.0,
                    'order_count' => 0,
                    'units' => 0,
                    'estimated_fee_data' => false,
                ];
            }

            $countryByDateRegion[$key]['landed_cost_local'] += $landedInSalesCurrency;
            $countryByDateRegion[$key]['landed_cost_gbp'] += $landedGbp;
            $countryByDateRegion[$key]['margin_local'] += $marginLocal;
            $countryByDateRegion[$key]['margin_gbp'] += $marginGbp;
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
                $regionLandedLocal = (float) ($landedCostByDateRegion[$adKey]['landed_local'] ?? 0.0);
                $regionLandedGbp = (float) ($landedCostByDateRegion[$adKey]['landed_gbp'] ?? 0.0);
                $regionMarginLocal = (float) ($marginByDateRegion[$adKey]['margin_local'] ?? 0.0);
                $regionMarginGbp = (float) ($marginByDateRegion[$adKey]['margin_gbp'] ?? 0.0);
                $regionCountryRowsRaw = array_values(array_filter(
                    $countryByDateRegion,
                    fn (array $r): bool => $r['date'] === $date && $r['region'] === $region
                ));
                $countryRowsByCode = [];
                foreach ($regionCountryRowsRaw as $regionCountryRow) {
                    $code = strtoupper(trim((string) ($regionCountryRow['country'] ?? '')));
                    if ($code === '') {
                        continue;
                    }
                    $countryRowsByCode[$code] = $regionCountryRow;
                }

                $countryAdByCode = $adSpendByDateRegionCountry[$adKey] ?? [];
                foreach ($countryAdByCode as $countryCode => $countryAd) {
                    if (!isset($countryRowsByCode[$countryCode])) {
                        $currency = strtoupper((string) ($countryAd['currency'] ?? $this->regionLocalCurrency($region)));
                        $countryRowsByCode[$countryCode] = [
                            'date' => $date,
                            'region' => $region,
                            'country' => $countryCode,
                            'currency' => $currency,
                            'sales_local' => 0.0,
                            'sales_gbp' => 0.0,
                            'fees_local' => 0.0,
                            'fees_gbp' => 0.0,
                            'landed_cost_local' => 0.0,
                            'landed_cost_gbp' => 0.0,
                            'margin_local' => 0.0,
                            'margin_gbp' => 0.0,
                            'order_count' => 0,
                            'units' => 0,
                            'estimated_fee_data' => false,
                        ];
                    }
                }
                $regionCountryRows = array_values($countryRowsByCode);

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
                        'landed_cost_local' => $regionLandedLocal,
                        'landed_cost_gbp' => $regionLandedGbp,
                        'margin_local' => $regionMarginLocal,
                        'margin_gbp' => $regionMarginGbp,
                        'estimated_fee_data' => false,
                        'pending_sales_data' => false,
                        'estimated_sales_data' => false,
                        'acos_percent' => null,
                    ];
                    continue;
                }

                $adLocalAllocations = [];
                $adGbpAllocations = [];
                if (!empty($countryAdByCode)) {
                    foreach ($regionCountryRows as $index => $regionCountryRow) {
                        $countryCode = strtoupper(trim((string) ($regionCountryRow['country'] ?? '')));
                        $countryAd = $countryAdByCode[$countryCode] ?? null;
                        $adLocalAllocations[$index] = (float) ($countryAd['ad_local'] ?? 0.0);
                        $adGbpAllocations[$index] = (float) ($countryAd['ad_gbp'] ?? 0.0);
                    }
                } else {
                    $countryCount = count($regionCountryRows);
                    $ratios = [];
                    foreach ($regionCountryRows as $regionCountryRow) {
                        if ($regionCountrySalesGbp > 0) {
                            $ratio = ((float) $regionCountryRow['sales_gbp'] / $regionCountrySalesGbp);
                        } elseif ($regionCountryOrders > 0) {
                            $ratio = ((int) ($regionCountryRow['order_count'] ?? 0)) / $regionCountryOrders;
                        } else {
                            $ratio = $countryCount > 0 ? (1 / $countryCount) : 0.0;
                        }
                        $ratios[] = $ratio;
                    }
                    $adLocalAllocations = $this->allocateByWeights($regionAdLocal, $ratios);
                    $adGbpAllocations = $this->allocateByWeights($regionAdGbp, $ratios);
                }

                foreach ($regionCountryRows as $index => $regionCountryRow) {
                    $adLocal = (float) ($adLocalAllocations[$index] ?? 0.0);
                    $adGbp = (float) ($adGbpAllocations[$index] ?? 0.0);
                    $landedLocal = (float) ($regionCountryRow['landed_cost_local'] ?? 0.0);
                    $landedGbp = (float) ($regionCountryRow['landed_cost_gbp'] ?? 0.0);
                    $marginLocal = (float) ($regionCountryRow['margin_local'] ?? 0.0);
                    $marginGbp = (float) ($regionCountryRow['margin_gbp'] ?? 0.0);
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
                        'landed_cost_local' => $landedLocal,
                        'landed_cost_gbp' => $landedGbp,
                        'margin_local' => $marginLocal,
                        'margin_gbp' => $marginGbp,
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
            $totalLandedGbp = (float) array_sum(array_map(fn (array $item): float => (float) ($item['landed_cost_gbp'] ?? 0.0), $items));
            $totalMarginGbp = (float) array_sum(array_map(fn (array $item): float => (float) ($item['margin_gbp'] ?? 0.0), $items));
            $totalOrders = (int) array_sum(array_map(fn (array $item): int => (int) ($item['order_count'] ?? 0), $items));
            $totalUnits = (int) array_sum(array_map(fn (array $item): int => (int) ($item['units'] ?? 0), $items));
            $estimatedFeeData = in_array(true, array_map(fn (array $item): bool => (bool) ($item['estimated_fee_data'] ?? false), $items), true);

            $dailyRows[] = [
                'date' => $date,
                'sales_gbp' => $totalSalesGbp,
                'ad_gbp' => $totalAdGbp,
                'fees_gbp' => $totalFeesGbp,
                'landed_cost_gbp' => $totalLandedGbp,
                'margin_gbp' => $totalMarginGbp,
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
                    'landed_cost_gbp' => 0.0,
                    'margin_gbp' => 0.0,
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
            $weekly[$weekStart]['landed_cost_gbp'] += (float) ($day['landed_cost_gbp'] ?? 0.0);
            $weekly[$weekStart]['margin_gbp'] += (float) ($day['margin_gbp'] ?? 0.0);
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

    /**
     * Split a total across weights and preserve exact 2dp reconciliation.
     *
     * @param  array<int, float|int>  $weights
     * @return array<int, float>
     */
    private function allocateByWeights(float $total, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }

        $allocations = array_fill(0, $count, 0.0);
        $targetCents = (int) round($total * 100);
        if ($targetCents === 0) {
            return $allocations;
        }

        $normalized = array_map(static fn ($w): float => max(0.0, (float) $w), $weights);
        $weightSum = array_sum($normalized);
        if ($weightSum <= 0.0) {
            $normalized = array_fill(0, $count, 1.0);
            $weightSum = (float) $count;
        }

        $sign = $targetCents < 0 ? -1 : 1;
        $remaining = abs($targetCents);
        $bases = [];
        $remainders = [];

        foreach ($normalized as $index => $weight) {
            $raw = $remaining * ($weight / $weightSum);
            $base = (int) floor($raw);
            $bases[$index] = $base;
            $remainders[$index] = $raw - $base;
        }

        $baseTotal = array_sum($bases);
        $toDistribute = $remaining - $baseTotal;
        if ($toDistribute > 0) {
            arsort($remainders);
            foreach (array_keys($remainders) as $index) {
                if ($toDistribute <= 0) {
                    break;
                }
                $bases[$index]++;
                $toDistribute--;
            }
        }

        foreach ($bases as $index => $cents) {
            $allocations[$index] = ($cents * $sign) / 100;
        }

        return $allocations;
    }
}
