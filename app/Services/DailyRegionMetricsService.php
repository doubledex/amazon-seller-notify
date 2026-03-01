<?php

namespace App\Services;

use App\Models\DailyRegionAdSpend;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DailyRegionMetricsService
{
    private const REGIONS = ['UK', 'EU', 'NA'];

    private const UK_COUNTRY_CODES = ['GB', 'UK'];

    private const EU_COUNTRY_CODES = [
        'AT', 'BE', 'DE', 'DK', 'ES', 'FI', 'FR', 'IE', 'IT', 'LU', 'NL', 'NO', 'PL', 'SE', 'CH', 'TR',
    ];

    private const NA_COUNTRY_CODES = ['US', 'CA', 'MX', 'BR'];

    private const LOCAL_CURRENCY = [
        'UK' => 'GBP',
        'EU' => 'EUR',
        'NA' => 'USD',
    ];

    public function __construct(
        private readonly FxRateService $fxRateService,
        private readonly LandedCostResolver $landedCostResolver,
    )
    {
    }

    public function refreshRange(Carbon $from, Carbon $to): array
    {
        $days = 0;
        $date = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($date->lte($end)) {
            $dateString = $date->toDateString();
            foreach (self::REGIONS as $region) {
                $this->refreshDayRegion($dateString, $region);
            }
            $days++;
            $date->addDay();
        }

        return ['days' => $days, 'regions' => count(self::REGIONS)];
    }

    public function refreshDates(array $dates): array
    {
        $normalized = [];
        foreach ($dates as $date) {
            $date = trim((string) $date);
            if ($date === '') {
                continue;
            }
            try {
                $normalized[Carbon::parse($date)->toDateString()] = true;
            } catch (\Throwable) {
                // Ignore invalid date values.
            }
        }

        $dateList = array_keys($normalized);
        sort($dateList);

        foreach ($dateList as $date) {
            foreach (self::REGIONS as $region) {
                $this->refreshDayRegion($date, $region);
            }
        }

        return ['days' => count($dateList), 'regions' => count(self::REGIONS)];
    }

    public function importAdSpendCsv(string $path, string $source = 'csv'): int
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("CSV file not readable: {$path}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV: {$path}");
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            throw new \RuntimeException('CSV header row is required.');
        }

        $normalized = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $index = array_flip($normalized);
        $required = ['date', 'region', 'amount', 'currency'];
        foreach ($required as $col) {
            if (!array_key_exists($col, $index)) {
                fclose($handle);
                throw new \RuntimeException("CSV must include columns: date, region, amount, currency");
            }
        }

        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (!is_array($row) || count($row) === 0) {
                continue;
            }

            $date = trim((string) ($row[$index['date']] ?? ''));
            $region = strtoupper(trim((string) ($row[$index['region']] ?? '')));
            $currency = strtoupper(trim((string) ($row[$index['currency']] ?? '')));
            $amount = (float) trim((string) ($row[$index['amount']] ?? '0'));

            if ($date === '' || !in_array($region, self::REGIONS, true) || $currency === '') {
                continue;
            }

            DailyRegionAdSpend::updateOrCreate(
                [
                    'metric_date' => $date,
                    'region' => $region,
                    'currency' => $currency,
                    'source' => $source,
                ],
                [
                    'amount_local' => $amount,
                ]
            );
            $count++;
        }

        fclose($handle);
        return $count;
    }

    private function refreshDayRegion(string $date, string $region): void
    {
        $localCurrency = self::LOCAL_CURRENCY[$region];

        $salesRows = $this->salesByCurrency($date, $region);
        $salesLocal = $this->convertRows($salesRows, $localCurrency, $date);
        $salesGbp = $this->convertRows($salesRows, 'GBP', $date);
        $orderCount = (int) $salesRows->sum('order_count');

        $financialRows = $this->orderFinancialRows($date, $region);
        $orderIds = $financialRows->pluck('amazon_order_id')->filter()->values()->all();
        $landedByOrder = $this->landedCostResolver->resolveOrderLandedCosts($orderIds);
        $landedRows = [];
        $marginRows = [];
        foreach ($financialRows as $row) {
            $orderId = (string) ($row->amazon_order_id ?? '');
            if ($orderId === '') {
                continue;
            }
            $salesCurrency = strtoupper((string) ($row->sales_currency ?? ''));
            $feeCurrency = strtoupper((string) ($row->fee_currency ?? $salesCurrency));
            if ($salesCurrency === '') {
                continue;
            }
            $netAmount = (float) ($row->net_amount ?? 0.0);
            $feeAmount = abs((float) ($row->fee_amount ?? 0.0));

            $landed = $landedByOrder[$orderId] ?? null;
            $landedAmount = 0.0;
            if (is_array($landed)) {
                $landedCurrency = strtoupper((string) ($landed['currency'] ?? ''));
                if ($landedCurrency === $salesCurrency) {
                    $landedAmount = (float) ($landed['landed_cost_total'] ?? 0.0);
                }
            }

            $feeInSalesCurrency = $feeAmount;
            if ($feeCurrency !== '' && $feeCurrency !== $salesCurrency) {
                $convertedFee = $this->fxRateService->convert($feeAmount, $feeCurrency, $salesCurrency, $date);
                $feeInSalesCurrency = abs((float) ($convertedFee ?? 0.0));
            }

            $landedRows[] = (object) ['currency' => $salesCurrency, 'amount' => $landedAmount];
            $marginRows[] = (object) ['currency' => $salesCurrency, 'amount' => $netAmount - $feeInSalesCurrency - $landedAmount];
        }

        $landedCostLocal = $this->convertRows(collect($landedRows), $localCurrency, $date);
        $landedCostGbp = $this->convertRows(collect($landedRows), 'GBP', $date);
        $marginLocal = $this->convertRows(collect($marginRows), $localCurrency, $date);
        $marginGbp = $this->convertRows(collect($marginRows), 'GBP', $date);

        $spendRows = DailyRegionAdSpend::query()
            ->select('currency', DB::raw('SUM(amount_local) as amount'))
            ->whereDate('metric_date', $date)
            ->where('region', $region)
            ->groupBy('currency')
            ->get();

        $adSpendLocal = $this->convertRows($spendRows, $localCurrency, $date);
        $adSpendGbp = $this->convertRows($spendRows, 'GBP', $date);

        $acos = $salesGbp > 0 ? round(($adSpendGbp / $salesGbp) * 100, 2) : null;

        DB::table('daily_region_metrics')->updateOrInsert(
            [
                'metric_date' => $date,
                'region' => $region,
            ],
            [
                'local_currency' => $localCurrency,
                'sales_local' => round($salesLocal, 2),
                'sales_gbp' => round($salesGbp, 2),
                'ad_spend_local' => round($adSpendLocal, 2),
                'ad_spend_gbp' => round($adSpendGbp, 2),
                'landed_cost_local' => round($landedCostLocal, 2),
                'landed_cost_gbp' => round($landedCostGbp, 2),
                'margin_local' => round($marginLocal, 2),
                'margin_gbp' => round($marginGbp, 2),
                'acos_percent' => $acos,
                'order_count' => $orderCount,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function orderFinancialRows(string $date, string $region): Collection
    {
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
            ? 'COALESCE(line_net_ex_tax, estimated_line_net_ex_tax, 0)'
            : 'COALESCE(line_net_ex_tax, 0)';

        $itemTotals = DB::table('order_items')
            ->selectRaw("amazon_order_id, MAX({$itemCurrencyFallbackExpr}) as item_currency, SUM({$itemNetFallbackExpr}) as item_total")
            ->groupBy('amazon_order_id');

        $salesCurrencyExpr = "COALESCE(NULLIF(orders.order_net_ex_tax_currency, ''), NULLIF(item_totals.item_currency, ''), NULLIF(orders.order_total_currency, ''), NULLIF(marketplaces.default_currency, ''), 'GBP')";
        $netExpr = 'CASE WHEN COALESCE(orders.order_net_ex_tax, 0) > 0 THEN orders.order_net_ex_tax ELSE COALESCE(item_totals.item_total, 0) END';

        $feeAmountExpr = '0';
        if ($hasFeeTotalV2 && $hasFeeTotal && $hasFeeEstimatedTotal) {
            $feeAmountExpr = 'COALESCE(orders.amazon_fee_total_v2, orders.amazon_fee_total, orders.amazon_fee_estimated_total, 0)';
        } elseif ($hasFeeTotalV2 && $hasFeeTotal) {
            $feeAmountExpr = 'COALESCE(orders.amazon_fee_total_v2, orders.amazon_fee_total, 0)';
        } elseif ($hasFeeTotalV2 && $hasFeeEstimatedTotal) {
            $feeAmountExpr = 'COALESCE(orders.amazon_fee_total_v2, orders.amazon_fee_estimated_total, 0)';
        } elseif ($hasFeeTotal && $hasFeeEstimatedTotal) {
            $feeAmountExpr = 'COALESCE(orders.amazon_fee_total, orders.amazon_fee_estimated_total, 0)';
        } elseif ($hasFeeTotalV2) {
            $feeAmountExpr = 'COALESCE(orders.amazon_fee_total_v2, 0)';
        } elseif ($hasFeeTotal) {
            $feeAmountExpr = 'COALESCE(orders.amazon_fee_total, 0)';
        } elseif ($hasFeeEstimatedTotal) {
            $feeAmountExpr = 'COALESCE(orders.amazon_fee_estimated_total, 0)';
        }

        $feeCurrencyExpr = "'GBP'";
        if ($hasFeeCurrencyV2 && $hasFeeCurrency && $hasFeeEstimatedCurrency) {
            $feeCurrencyExpr = "COALESCE(NULLIF(orders.amazon_fee_currency_v2, ''), NULLIF(orders.amazon_fee_currency, ''), NULLIF(orders.amazon_fee_estimated_currency, ''), {$salesCurrencyExpr})";
        } elseif ($hasFeeCurrencyV2 && $hasFeeCurrency) {
            $feeCurrencyExpr = "COALESCE(NULLIF(orders.amazon_fee_currency_v2, ''), NULLIF(orders.amazon_fee_currency, ''), {$salesCurrencyExpr})";
        } elseif ($hasFeeCurrencyV2 && $hasFeeEstimatedCurrency) {
            $feeCurrencyExpr = "COALESCE(NULLIF(orders.amazon_fee_currency_v2, ''), NULLIF(orders.amazon_fee_estimated_currency, ''), {$salesCurrencyExpr})";
        } elseif ($hasFeeCurrency && $hasFeeEstimatedCurrency) {
            $feeCurrencyExpr = "COALESCE(NULLIF(orders.amazon_fee_currency, ''), NULLIF(orders.amazon_fee_estimated_currency, ''), {$salesCurrencyExpr})";
        } elseif ($hasFeeCurrencyV2) {
            $feeCurrencyExpr = "COALESCE(NULLIF(orders.amazon_fee_currency_v2, ''), {$salesCurrencyExpr})";
        } elseif ($hasFeeCurrency) {
            $feeCurrencyExpr = "COALESCE(NULLIF(orders.amazon_fee_currency, ''), {$salesCurrencyExpr})";
        } elseif ($hasFeeEstimatedCurrency) {
            $feeCurrencyExpr = "COALESCE(NULLIF(orders.amazon_fee_estimated_currency, ''), {$salesCurrencyExpr})";
        }

        $query = Order::query()
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->selectRaw("orders.amazon_order_id, {$salesCurrencyExpr} as sales_currency, {$feeCurrencyExpr} as fee_currency, {$netExpr} as net_amount, {$feeAmountExpr} as fee_amount")
            ->whereRaw("COALESCE(orders.purchase_date_local_date, DATE(orders.purchase_date)) = ?", [$date])
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')");

        $this->applyRegionCountryFilter($query, $region);

        return $query->get();
    }

    private function salesByCurrency(string $date, string $region): Collection
    {
        $itemTotals = DB::table('order_items')
            ->selectRaw("
                amazon_order_id,
                MAX(COALESCE(line_net_currency, estimated_line_currency, item_price_currency, '')) as item_currency,
                SUM(COALESCE(line_net_ex_tax, estimated_line_net_ex_tax, 0)) as item_total
            ")
            ->groupBy('amazon_order_id');

        $query = Order::query()
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->select(
                DB::raw("COALESCE(NULLIF(orders.order_net_ex_tax_currency, ''), NULLIF(item_totals.item_currency, ''), NULLIF(orders.order_total_currency, ''), NULLIF(marketplaces.default_currency, ''), 'GBP') as currency"),
                DB::raw("
                    SUM(
                        CASE
                            WHEN COALESCE(orders.order_net_ex_tax, 0) > 0 THEN orders.order_net_ex_tax
                            ELSE COALESCE(item_totals.item_total, 0)
                        END
                    ) as amount
                "),
                DB::raw('COUNT(*) as order_count')
            )
            ->whereRaw("COALESCE(orders.purchase_date_local_date, DATE(orders.purchase_date)) = ?", [$date]);

        $query->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')");

        $this->applyRegionCountryFilter($query, $region);

        return $query
            ->groupBy(DB::raw("COALESCE(NULLIF(orders.order_net_ex_tax_currency, ''), NULLIF(item_totals.item_currency, ''), NULLIF(orders.order_total_currency, ''), NULLIF(marketplaces.default_currency, ''), 'GBP')"))
            ->get();
    }

    private function applyRegionCountryFilter($query, string $region): void
    {
        $countryColumn = DB::raw('UPPER(marketplaces.country_code)');

        if ($region === 'UK') {
            $query->whereIn($countryColumn, self::UK_COUNTRY_CODES);
            return;
        }

        if ($region === 'EU') {
            $query->whereIn($countryColumn, self::EU_COUNTRY_CODES);
            return;
        }

        if ($region === 'NA') {
            $query->whereIn($countryColumn, self::NA_COUNTRY_CODES);
        }
    }

    private function convertRows(Collection $rows, string $toCurrency, string $date): float
    {
        $sum = 0.0;

        foreach ($rows as $row) {
            $fromCurrency = strtoupper((string) ($row->currency ?? ''));
            $amount = (float) ($row->amount ?? 0);
            if ($fromCurrency === '' || $amount == 0.0) {
                continue;
            }

            $converted = $this->fxRateService->convert($amount, $fromCurrency, $toCurrency, $date);
            if ($converted === null) {
                Log::warning('Missing FX conversion', [
                    'date' => $date,
                    'from' => $fromCurrency,
                    'to' => $toCurrency,
                    'amount' => $amount,
                ]);
                continue;
            }
            $sum += $converted;
        }

        return $sum;
    }
}
