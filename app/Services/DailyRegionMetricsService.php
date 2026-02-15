<?php

namespace App\Services;

use App\Models\DailyRegionAdSpend;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DailyRegionMetricsService
{
    private const REGIONS = ['UK', 'EU'];

    private const LOCAL_CURRENCY = [
        'UK' => 'GBP',
        'EU' => 'EUR',
    ];

    public function __construct(private readonly FxRateService $fxRateService)
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
                'acos_percent' => $acos,
                'order_count' => $orderCount,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function salesByCurrency(string $date, string $region): Collection
    {
        $itemTotals = DB::table('order_items')
            ->selectRaw("
                amazon_order_id,
                MAX(COALESCE(item_price_currency, '')) as item_currency,
                SUM(COALESCE(quantity_ordered, 0) * COALESCE(item_price_amount, 0)) as item_total
            ")
            ->groupBy('amazon_order_id');

        $query = Order::query()
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->select(
                DB::raw("COALESCE(NULLIF(orders.order_total_currency, ''), NULLIF(item_totals.item_currency, ''), 'GBP') as currency"),
                DB::raw("
                    SUM(
                        CASE
                            WHEN COALESCE(orders.order_total_amount, 0) > 0 THEN orders.order_total_amount
                            ELSE COALESCE(item_totals.item_total, 0)
                        END
                    ) as amount
                "),
                DB::raw('COUNT(*) as order_count')
            )
            ->whereDate('orders.purchase_date', $date);

        $query->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')");

        if ($region === 'UK') {
            $query->where('marketplaces.country_code', 'GB');
        } else {
            $query->where('marketplaces.country_code', '!=', 'GB');
        }

        return $query
            ->groupBy(DB::raw("COALESCE(NULLIF(orders.order_total_currency, ''), NULLIF(item_totals.item_currency, ''), 'GBP')"))
            ->get();
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
