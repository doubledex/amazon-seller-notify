<?php

namespace App\Http\Controllers;

use App\Models\UsFcInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsFcInventoryController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $state = strtoupper(trim((string) $request->query('state', '')));
        $latestReportDate = UsFcInventory::query()->max('report_date');
        $reportDate = trim((string) $request->query('report_date', $latestReportDate ?? ''));

        $query = UsFcInventory::query()
            ->leftJoin('us_fc_locations as loc', 'loc.fulfillment_center_id', '=', 'us_fc_inventories.fulfillment_center_id')
            ->select(
                'us_fc_inventories.id',
                'us_fc_inventories.marketplace_id',
                'us_fc_inventories.fulfillment_center_id',
                'us_fc_inventories.seller_sku',
                'us_fc_inventories.asin',
                'us_fc_inventories.fnsku',
                'us_fc_inventories.item_condition',
                'us_fc_inventories.quantity_available',
                'us_fc_inventories.report_date',
                'us_fc_inventories.updated_at',
                'loc.city as fc_city',
                'loc.state as fc_state',
                'loc.label as fc_label',
            );

        if ($reportDate !== '') {
            $query->whereDate('us_fc_inventories.report_date', '=', $reportDate);
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('us_fc_inventories.seller_sku', 'like', '%' . $q . '%')
                    ->orWhere('us_fc_inventories.asin', 'like', '%' . $q . '%')
                    ->orWhere('us_fc_inventories.fnsku', 'like', '%' . $q . '%')
                    ->orWhere('us_fc_inventories.fulfillment_center_id', 'like', '%' . $q . '%')
                    ->orWhere('loc.city', 'like', '%' . $q . '%')
                    ->orWhere('loc.state', 'like', '%' . $q . '%');
            });
        }

        if ($state !== '') {
            $query->whereRaw('upper(coalesce(loc.state, "")) = ?', [$state]);
        }

        $rows = $query
            ->orderByRaw('coalesce(loc.state, "ZZ") asc')
            ->orderByRaw('coalesce(loc.city, "ZZZZZZ") asc')
            ->orderBy('us_fc_inventories.fulfillment_center_id')
            ->orderByDesc('us_fc_inventories.quantity_available')
            ->paginate(100)
            ->appends($request->query());

        $summary = UsFcInventory::query()
            ->leftJoin('us_fc_locations as loc', 'loc.fulfillment_center_id', '=', 'us_fc_inventories.fulfillment_center_id')
            ->selectRaw('coalesce(loc.state, "Unknown") as state')
            ->selectRaw('sum(us_fc_inventories.quantity_available) as qty')
            ->selectRaw('max(us_fc_inventories.report_date) as data_date')
            ->when($reportDate !== '', fn ($q) => $q->whereDate('us_fc_inventories.report_date', '=', $reportDate))
            ->groupBy(DB::raw('coalesce(loc.state, "Unknown")'))
            ->orderBy('state')
            ->get();

        $fcSummary = UsFcInventory::query()
            ->leftJoin('us_fc_locations as loc', 'loc.fulfillment_center_id', '=', 'us_fc_inventories.fulfillment_center_id')
            ->selectRaw('us_fc_inventories.fulfillment_center_id as fc')
            ->selectRaw('coalesce(loc.city, "Unknown") as city')
            ->selectRaw('coalesce(loc.state, "Unknown") as state')
            ->selectRaw('sum(us_fc_inventories.quantity_available) as qty')
            ->selectRaw('count(*) as row_count')
            ->selectRaw('max(us_fc_inventories.report_date) as data_date')
            ->when($reportDate !== '', fn ($q) => $q->whereDate('us_fc_inventories.report_date', '=', $reportDate))
            ->when($state !== '', fn ($q) => $q->whereRaw('upper(coalesce(loc.state, "")) = ?', [$state]))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('us_fc_inventories.seller_sku', 'like', '%' . $q . '%')
                        ->orWhere('us_fc_inventories.asin', 'like', '%' . $q . '%')
                        ->orWhere('us_fc_inventories.fnsku', 'like', '%' . $q . '%')
                        ->orWhere('us_fc_inventories.fulfillment_center_id', 'like', '%' . $q . '%')
                        ->orWhere('loc.city', 'like', '%' . $q . '%')
                        ->orWhere('loc.state', 'like', '%' . $q . '%');
                });
            })
            ->groupBy('us_fc_inventories.fulfillment_center_id', DB::raw('coalesce(loc.city, "Unknown")'), DB::raw('coalesce(loc.state, "Unknown")'))
            ->orderByRaw('coalesce(loc.state, "ZZ") asc')
            ->orderByRaw('coalesce(loc.city, "ZZZZZZ") asc')
            ->orderBy('us_fc_inventories.fulfillment_center_id')
            ->get();

        return view('inventory.us_fc', [
            'rows' => $rows,
            'summary' => $summary,
            'fcSummary' => $fcSummary,
            'search' => $q,
            'state' => $state,
            'reportDate' => $reportDate,
            'latestReportDate' => $latestReportDate,
        ]);
    }
}
