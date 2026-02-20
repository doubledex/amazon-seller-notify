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

        $itemTotals = DB::table('order_items')
            ->selectRaw("
                amazon_order_id,
                MAX(COALESCE(item_price_currency, '')) as item_currency,
                SUM(COALESCE(quantity_ordered, 0) * COALESCE(item_price_amount, 0)) as item_total,
                SUM(COALESCE(quantity_ordered, 0)) as item_units
            ")
            ->groupBy('amazon_order_id');

        $salesRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->selectRaw("
                DATE(orders.purchase_date) as metric_date,
                marketplaces.country_code as country_code,
                COALESCE(
                    NULLIF(orders.order_total_currency, ''),
                    NULLIF(item_totals.item_currency, ''),
                    'GBP'
                ) as currency,
                SUM(
                    CASE
                        WHEN COALESCE(orders.order_total_amount, 0) > 0 THEN orders.order_total_amount
                        ELSE COALESCE(item_totals.item_total, 0)
                    END
                ) as sales_amount,
                COUNT(*) as order_count
            ")
            ->whereDate('orders.purchase_date', '>=', $from)
            ->whereDate('orders.purchase_date', '<=', $to)
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->groupByRaw("
                DATE(orders.purchase_date),
                marketplaces.country_code,
                COALESCE(NULLIF(orders.order_total_currency, ''), NULLIF(item_totals.item_currency, ''), 'GBP')
            ")
            ->get();

        $pendingSalesRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->selectRaw("
                DATE(orders.purchase_date) as metric_date,
                marketplaces.country_code as country_code,
                COUNT(*) as pending_count
            ")
            ->whereDate('orders.purchase_date', '>=', $from)
            ->whereDate('orders.purchase_date', '<=', $to)
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) IN ('PENDING', 'UNSHIPPED')")
            ->whereRaw("COALESCE(orders.order_total_amount, 0) <= 0")
            ->whereRaw("COALESCE(item_totals.item_total, 0) <= 0")
            ->groupByRaw("DATE(orders.purchase_date), marketplaces.country_code")
            ->get();

        $historyFrom = Carbon::parse($from)->subDays(120)->toDateString();
        $pricedAsinRows = DB::table('order_items')
            ->join('orders', 'orders.amazon_order_id', '=', 'order_items.amazon_order_id')
            ->selectRaw("
                orders.marketplace_id as marketplace_id,
                COALESCE(orders.is_business_order, 0) as is_business_order,
                UPPER(COALESCE(order_items.asin, '')) as asin,
                UPPER(COALESCE(NULLIF(order_items.item_price_currency, ''), NULLIF(orders.order_total_currency, ''), '')) as currency,
                COALESCE(order_items.item_price_amount, 0) as unit_price,
                orders.purchase_date as purchase_date
            ")
            ->whereDate('orders.purchase_date', '>=', $historyFrom)
            ->whereDate('orders.purchase_date', '<=', $to)
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->whereRaw("COALESCE(order_items.item_price_amount, 0) > 0")
            ->whereRaw("TRIM(COALESCE(order_items.asin, '')) <> ''")
            ->orderByDesc('orders.purchase_date')
            ->get();

        $pendingOrderItemsForEstimate = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->join('order_items', 'order_items.amazon_order_id', '=', 'orders.amazon_order_id')
            ->leftJoinSub($itemTotals, 'item_totals', function ($join) {
                $join->on('item_totals.amazon_order_id', '=', 'orders.amazon_order_id');
            })
            ->selectRaw("
                DATE(orders.purchase_date) as metric_date,
                marketplaces.country_code as country_code,
                orders.marketplace_id as marketplace_id,
                COALESCE(orders.is_business_order, 0) as is_business_order,
                UPPER(COALESCE(order_items.asin, '')) as asin,
                UPPER(COALESCE(NULLIF(order_items.item_price_currency, ''), NULLIF(orders.order_total_currency, ''), '')) as currency,
                SUM(COALESCE(order_items.quantity_ordered, 0)) as units
            ")
            ->whereDate('orders.purchase_date', '>=', $from)
            ->whereDate('orders.purchase_date', '<=', $to)
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) IN ('PENDING', 'UNSHIPPED')")
            ->whereRaw("COALESCE(orders.order_total_amount, 0) <= 0")
            ->whereRaw("COALESCE(item_totals.item_total, 0) <= 0")
            ->whereRaw("TRIM(COALESCE(order_items.asin, '')) <> ''")
            ->groupByRaw("
                DATE(orders.purchase_date),
                marketplaces.country_code,
                orders.marketplace_id,
                COALESCE(orders.is_business_order, 0),
                UPPER(COALESCE(order_items.asin, '')),
                UPPER(COALESCE(NULLIF(order_items.item_price_currency, ''), NULLIF(orders.order_total_currency, ''), ''))
            ")
            ->get();

        $unitRows = DB::table('orders')
            ->join('marketplaces', 'marketplaces.id', '=', 'orders.marketplace_id')
            ->leftJoin('order_items', 'order_items.amazon_order_id', '=', 'orders.amazon_order_id')
            ->selectRaw("
                DATE(orders.purchase_date) as metric_date,
                marketplaces.country_code as country_code,
                SUM(COALESCE(order_items.quantity_ordered, 0)) as units
            ")
            ->whereDate('orders.purchase_date', '>=', $from)
            ->whereDate('orders.purchase_date', '<=', $to)
            ->whereRaw("UPPER(COALESCE(orders.order_status, '')) NOT IN ('CANCELED', 'CANCELLED')")
            ->groupByRaw("DATE(orders.purchase_date), marketplaces.country_code")
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
                    'pending_sales_data' => false,
                ];
            }

            $breakdown[$key]['currency'] = $currency;
            $breakdown[$key]['sales_local'] += $amount;
            $breakdown[$key]['sales_gbp'] += $fxRateService->convert($amount, $currency, 'GBP', $date) ?? 0.0;
            $breakdown[$key]['order_count'] += (int) ($row->order_count ?? 0);
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
                    'pending_sales_data' => false,
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
                    'pending_sales_data' => false,
                ];
            }
            $breakdown[$key]['pending_sales_data'] = ((int) ($row->pending_count ?? 0)) > 0;
        }

        $asinPriceLookup = $this->buildAsinMarketplacePriceLookup($pricedAsinRows);
        foreach ($pendingOrderItemsForEstimate as $row) {
            $date = (string) $row->metric_date;
            $country = $this->normalizeMarketplaceCode((string) $row->country_code);
            if ($date === '' || $country === '') {
                continue;
            }

            $units = (int) ($row->units ?? 0);
            if ($units <= 0) {
                continue;
            }

            $marketplaceId = trim((string) ($row->marketplace_id ?? ''));
            $isBusinessOrder = (bool) ($row->is_business_order ?? false);
            $asin = strtoupper(trim((string) ($row->asin ?? '')));
            if ($marketplaceId === '' || $asin === '') {
                continue;
            }

            $price = $this->findAsinMarketplacePrice($asinPriceLookup, $marketplaceId, $isBusinessOrder, $asin);
            if ($price === null) {
                continue;
            }

            $sourceCurrency = strtoupper(trim((string) ($price['currency'] ?? '')));
            $unitPrice = (float) ($price['unit_price'] ?? 0);
            if ($sourceCurrency === '' || $unitPrice <= 0) {
                continue;
            }

            $estimatedAmount = $unitPrice * $units;
            if ($estimatedAmount <= 0) {
                continue;
            }

            $currency = strtoupper(trim((string) ($row->currency ?? '')));
            if ($currency === '') {
                $currency = $sourceCurrency;
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
                    'pending_sales_data' => false,
                ];
            }

            $entryCurrency = strtoupper((string) ($breakdown[$key]['currency'] ?? ''));
            if ($entryCurrency === '') {
                $entryCurrency = $currency;
                $breakdown[$key]['currency'] = $entryCurrency;
            }

            $localAmount = $estimatedAmount;
            if ($sourceCurrency !== $entryCurrency) {
                $convertedLocal = $fxRateService->convert($estimatedAmount, $sourceCurrency, $entryCurrency, $date);
                if ($convertedLocal !== null) {
                    $localAmount = $convertedLocal;
                }
            }

            $breakdown[$key]['sales_local'] += $localAmount;
            $breakdown[$key]['sales_gbp'] += $fxRateService->convert($estimatedAmount, $sourceCurrency, 'GBP', $date) ?? 0.0;
            $breakdown[$key]['pending_sales_data'] = true;
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
                    'pending_sales_data' => false,
                ];
            }

            $breakdown[$key]['currency'] = $currency;
            $breakdown[$key]['ad_local'] += $amount;
            $breakdown[$key]['ad_gbp'] += $fxRateService->convert($amount, $currency, 'GBP', $date) ?? 0.0;
        }

        $dailyRows = [];
        $dateList = array_keys($dates);
        rsort($dateList);

        foreach ($dateList as $date) {
            $items = [];
            $totalSalesGbp = 0.0;
            $totalAdGbp = 0.0;
            $totalOrders = 0;
            $totalUnits = 0;
            $hasPendingSalesData = false;

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
                $totalOrders += (int) ($entry['order_count'] ?? 0);
                $totalUnits += (int) ($entry['units'] ?? 0);
                $hasPendingSalesData = $hasPendingSalesData || (bool) ($entry['pending_sales_data'] ?? false);
            }

            usort($items, fn ($a, $b) => strcmp($a['country'], $b['country']));
            $dailyRows[] = [
                'date' => $date,
                'sales_gbp' => $totalSalesGbp,
                'ad_gbp' => $totalAdGbp,
                'order_count' => $totalOrders,
                'units' => $totalUnits,
                'acos_percent' => $totalSalesGbp > 0 ? ($totalAdGbp / $totalSalesGbp) * 100 : null,
                'pending_sales_data' => $hasPendingSalesData,
                'items' => $items,
            ];
        }

        return view('metrics.index', [
            'rows' => $dailyRows,
            'from' => $from,
            'to' => $to,
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

    private function buildAsinMarketplacePriceLookup($rows): array
    {
        $lookup = [];

        foreach ($rows as $row) {
            $marketplaceId = trim((string) ($row->marketplace_id ?? ''));
            $isBusinessOrder = ((int) ($row->is_business_order ?? 0)) === 1;
            $asin = strtoupper(trim((string) ($row->asin ?? '')));
            $currency = strtoupper(trim((string) ($row->currency ?? '')));
            $unitPrice = (float) ($row->unit_price ?? 0);
            if ($marketplaceId === '' || $asin === '' || $currency === '' || $unitPrice <= 0) {
                continue;
            }

            $bizKey = $marketplaceId . '|' . ($isBusinessOrder ? '1' : '0') . '|' . $asin;
            if (!isset($lookup[$bizKey])) {
                $lookup[$bizKey] = [
                    'currency' => $currency,
                    'unit_price' => $unitPrice,
                ];
            }

            $genericKey = $marketplaceId . '|*|' . $asin;
            if (!isset($lookup[$genericKey])) {
                $lookup[$genericKey] = [
                    'currency' => $currency,
                    'unit_price' => $unitPrice,
                ];
            }
        }

        return $lookup;
    }

    private function findAsinMarketplacePrice(array $lookup, string $marketplaceId, bool $isBusinessOrder, string $asin): ?array
    {
        $bizKey = $marketplaceId . '|' . ($isBusinessOrder ? '1' : '0') . '|' . $asin;
        if (isset($lookup[$bizKey])) {
            return $lookup[$bizKey];
        }

        $genericKey = $marketplaceId . '|*|' . $asin;
        return $lookup[$genericKey] ?? null;
    }
}
