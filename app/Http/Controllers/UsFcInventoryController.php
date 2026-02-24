<?php

namespace App\Http\Controllers;

use App\Models\CityGeo;
use App\Models\UsFcInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsFcInventoryController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $sku = trim((string) $request->query('sku', ''));
        $asin = strtoupper(trim((string) $request->query('asin', '')));
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
        if ($sku !== '') {
            $query->where('us_fc_inventories.seller_sku', 'like', '%' . $sku . '%');
        }
        if ($asin !== '') {
            $query->where('us_fc_inventories.asin', 'like', '%' . $asin . '%');
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
            ->when($sku !== '', fn ($q) => $q->where('us_fc_inventories.seller_sku', 'like', '%' . $sku . '%'))
            ->when($asin !== '', fn ($q) => $q->where('us_fc_inventories.asin', 'like', '%' . $asin . '%'))
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
            ->when($sku !== '', fn ($q) => $q->where('us_fc_inventories.seller_sku', 'like', '%' . $sku . '%'))
            ->when($asin !== '', fn ($q) => $q->where('us_fc_inventories.asin', 'like', '%' . $asin . '%'))
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

        $hashByFc = [];
        foreach ($fcSummary as $row) {
            $city = trim((string) ($row->city ?? ''));
            $region = trim((string) ($row->state ?? ''));
            if ($city === '' || $region === '' || strtoupper($city) === 'UNKNOWN' || strtoupper($region) === 'UNKNOWN') {
                continue;
            }
            $hashByFc[(string) $row->fc] = CityGeo::lookupHash('US', $city, $region);
        }

        $geoByHash = CityGeo::query()
            ->whereIn('lookup_hash', array_values(array_unique(array_values($hashByFc))))
            ->get(['lookup_hash', 'lat', 'lng'])
            ->keyBy('lookup_hash');

        $fcMapPoints = [];
        $stateCentroids = $this->usStateCentroids();
        foreach ($fcSummary as $row) {
            $fc = (string) $row->fc;
            $hash = $hashByFc[$fc] ?? null;
            $lat = null;
            $lng = null;

            if ($hash && isset($geoByHash[$hash])) {
                $geo = $geoByHash[$hash];
                $lat = (float) $geo->lat;
                $lng = (float) $geo->lng;
            } else {
                $state = strtoupper(trim((string) ($row->state ?? '')));
                if (isset($stateCentroids[$state])) {
                    $lat = (float) $stateCentroids[$state][0];
                    $lng = (float) $stateCentroids[$state][1];
                }
            }

            if ($lat === null || $lng === null) {
                continue;
            }
            if ($lat === 0.0 || $lng === 0.0 || abs($lat) > 90 || abs($lng) > 180) {
                continue;
            }

            $fcMapPoints[] = [
                'fc' => $fc,
                'city' => (string) $row->city,
                'state' => (string) $row->state,
                'qty' => (int) $row->qty,
                'rows' => (int) $row->row_count,
                'data_date' => (string) ($row->data_date ?? ''),
                'lat' => $lat,
                'lng' => $lng,
            ];
        }

        return view('inventory.us_fc', [
            'rows' => $rows,
            'summary' => $summary,
            'fcSummary' => $fcSummary,
            'fcMapPoints' => $fcMapPoints,
            'search' => $q,
            'sku' => $sku,
            'asin' => $asin,
            'state' => $state,
            'reportDate' => $reportDate,
            'latestReportDate' => $latestReportDate,
        ]);
    }

    private function usStateCentroids(): array
    {
        return [
            'AL' => [32.806671, -86.791130], 'AK' => [61.370716, -152.404419], 'AZ' => [33.729759, -111.431221],
            'AR' => [34.969704, -92.373123], 'CA' => [36.116203, -119.681564], 'CO' => [39.059811, -105.311104],
            'CT' => [41.597782, -72.755371], 'DE' => [39.318523, -75.507141], 'FL' => [27.766279, -81.686783],
            'GA' => [33.040619, -83.643074], 'HI' => [21.094318, -157.498337], 'ID' => [44.240459, -114.478828],
            'IL' => [40.349457, -88.986137], 'IN' => [39.849426, -86.258278], 'IA' => [42.011539, -93.210526],
            'KS' => [38.526600, -96.726486], 'KY' => [37.668140, -84.670067], 'LA' => [31.169546, -91.867805],
            'ME' => [44.693947, -69.381927], 'MD' => [39.063946, -76.802101], 'MA' => [42.230171, -71.530106],
            'MI' => [43.326618, -84.536095], 'MN' => [45.694454, -93.900192], 'MS' => [32.741646, -89.678696],
            'MO' => [38.456085, -92.288368], 'MT' => [46.921925, -110.454353], 'NE' => [41.125370, -98.268082],
            'NV' => [38.313515, -117.055374], 'NH' => [43.452492, -71.563896], 'NJ' => [40.298904, -74.521011],
            'NM' => [34.840515, -106.248482], 'NY' => [42.165726, -74.948051], 'NC' => [35.630066, -79.806419],
            'ND' => [47.528912, -99.784012], 'OH' => [40.388783, -82.764915], 'OK' => [35.565342, -96.928917],
            'OR' => [44.572021, -122.070938], 'PA' => [40.590752, -77.209755], 'RI' => [41.680893, -71.511780],
            'SC' => [33.856892, -80.945007], 'SD' => [44.299782, -99.438828], 'TN' => [35.747845, -86.692345],
            'TX' => [31.054487, -97.563461], 'UT' => [40.150032, -111.862434], 'VT' => [44.045876, -72.710686],
            'VA' => [37.769337, -78.169968], 'WA' => [47.400902, -121.490494], 'WV' => [38.491226, -80.954453],
            'WI' => [44.268543, -89.616508], 'WY' => [42.755966, -107.302490], 'DC' => [38.9072, -77.0369],
        ];
    }
}
