<?php

namespace App\Http\Controllers;

use App\Models\CityGeo;
use App\Models\UsFcInventory;
use App\Models\UsFcLocation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class UsFcInventoryController extends Controller
{
    private const MAX_PER_PAGE_ALL = 1000;

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $skuSelection = array_values(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            (array) $request->query('sku', [])
        ), static fn ($v) => $v !== ''));
        $asin = strtoupper(trim((string) $request->query('asin', '')));
        $regionFilter = strtoupper(trim((string) $request->query('region', '')));
        if (!in_array($regionFilter, ['UK', 'EU', 'NA'], true)) {
            $regionFilter = '';
        }
        $stateFilter = strtoupper(trim((string) $request->query('state', '')));
        $perPageInput = strtolower(trim((string) $request->query('per_page', '100')));
        $latestReportDate = UsFcInventory::query()->max('report_date');
        $requestedReportDate = trim((string) $request->query('report_date', ''));

        $defaultReportDate = UsFcInventory::query()
            ->leftJoin('us_fc_locations as loc', 'loc.fulfillment_center_id', '=', 'us_fc_inventories.fulfillment_center_id')
            ->leftJoin('marketplaces as mp', 'mp.id', '=', 'us_fc_inventories.marketplace_id')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('us_fc_inventories.seller_sku', 'like', '%' . $q . '%')
                        ->orWhere('us_fc_inventories.asin', 'like', '%' . $q . '%')
                        ->orWhere('us_fc_inventories.fnsku', 'like', '%' . $q . '%')
                        ->orWhere('us_fc_inventories.fulfillment_center_id', 'like', '%' . $q . '%')
                        ->orWhere('loc.city', 'like', '%' . $q . '%')
                        ->orWhere('loc.state', 'like', '%' . $q . '%')
                        ->orWhere('loc.country_code', 'like', '%' . $q . '%');
                });
            })
            ->when(!empty($skuSelection), fn ($query) => $query->whereIn('us_fc_inventories.seller_sku', $skuSelection))
            ->when($asin !== '', fn ($query) => $query->where('us_fc_inventories.asin', 'like', '%' . $asin . '%'))
            ->when($regionFilter !== '', fn ($query) => $this->applyRegionFilter($query, $regionFilter))
            ->when($stateFilter !== '', fn ($query) => $query->whereRaw('upper(coalesce(loc.state, "")) = ?', [$stateFilter]))
            ->max('us_fc_inventories.report_date');

        $reportDate = $requestedReportDate !== ''
            ? $requestedReportDate
            : ($defaultReportDate ?? $latestReportDate ?? '');

        $query = UsFcInventory::query()
            ->leftJoin('us_fc_locations as loc', 'loc.fulfillment_center_id', '=', 'us_fc_inventories.fulfillment_center_id')
            ->leftJoin('marketplaces as mp', 'mp.id', '=', 'us_fc_inventories.marketplace_id')
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
                DB::raw('coalesce(loc.country_code, upper(mp.country_code), "Unknown") as fc_country_code'),
                'loc.label as fc_label'
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
                    ->orWhere('loc.state', 'like', '%' . $q . '%')
                    ->orWhere('loc.country_code', 'like', '%' . $q . '%');
            });
        }
        if (!empty($skuSelection)) {
            $query->whereIn('us_fc_inventories.seller_sku', $skuSelection);
        }
        if ($asin !== '') {
            $query->where('us_fc_inventories.asin', 'like', '%' . $asin . '%');
        }
        if ($regionFilter !== '') {
            $this->applyRegionFilter($query, $regionFilter);
        }
        if ($stateFilter !== '') {
            $query->whereRaw('upper(coalesce(loc.state, "")) = ?', [$stateFilter]);
        }

        $countQuery = clone $query;
        $totalRows = (int) $countQuery->count();
        $perPageCapped = false;
        $perPage = match ($perPageInput) {
            'all' => max(1, min(self::MAX_PER_PAGE_ALL, $totalRows)),
            default => max(25, min(1000, (int) $perPageInput ?: 100)),
        };
        if ($perPageInput === 'all' && $totalRows > self::MAX_PER_PAGE_ALL) {
            $perPageCapped = true;
        }

        $rows = $query
            ->orderByRaw('coalesce(loc.country_code, upper(mp.country_code), "ZZ") asc')
            ->orderByRaw('coalesce(loc.state, "ZZ") asc')
            ->orderByRaw('coalesce(loc.city, "ZZZZZZ") asc')
            ->orderBy('us_fc_inventories.fulfillment_center_id')
            ->orderByDesc('us_fc_inventories.quantity_available')
            ->paginate($perPage)
            ->appends($request->query());

        $hierarchy = [];
        foreach ($rows->items() as $row) {
            $country = strtoupper(trim((string) ($row->fc_country_code ?? '')));
            $country = $country !== '' ? $country : 'Unknown';
            $state = strtoupper(trim((string) ($row->fc_state ?? '')));
            $state = $state !== '' ? $state : 'Unknown';
            $nodeKey = $country . '|' . $state;

            $fc = trim((string) ($row->fulfillment_center_id ?? ''));
            $fc = $fc !== '' ? $fc : 'Unknown';
            $city = trim((string) ($row->fc_city ?? ''));
            $city = $city !== '' ? $city : 'Unknown';
            $qty = (int) ($row->quantity_available ?? 0);
            $dataDate = (string) ($row->report_date ?? '');

            if (!isset($hierarchy[$nodeKey])) {
                $hierarchy[$nodeKey] = [
                    'country' => $country,
                    'state' => $state,
                    'group_label' => $country . ' / ' . $state,
                    'qty' => 0,
                    'data_date' => $dataDate,
                    'fcs' => [],
                ];
            }
            $hierarchy[$nodeKey]['qty'] += $qty;
            if ($dataDate !== '' && ($hierarchy[$nodeKey]['data_date'] === '' || strcmp($dataDate, $hierarchy[$nodeKey]['data_date']) > 0)) {
                $hierarchy[$nodeKey]['data_date'] = $dataDate;
            }

            if (!isset($hierarchy[$nodeKey]['fcs'][$fc])) {
                $hierarchy[$nodeKey]['fcs'][$fc] = [
                    'fc' => $fc,
                    'city' => $city,
                    'state' => $state,
                    'country' => $country,
                    'qty' => 0,
                    'row_count' => 0,
                    'data_date' => $dataDate,
                    'details' => [],
                ];
            }
            $hierarchy[$nodeKey]['fcs'][$fc]['qty'] += $qty;
            $hierarchy[$nodeKey]['fcs'][$fc]['row_count']++;
            if ($dataDate !== '' && ($hierarchy[$nodeKey]['fcs'][$fc]['data_date'] === '' || strcmp($dataDate, $hierarchy[$nodeKey]['fcs'][$fc]['data_date']) > 0)) {
                $hierarchy[$nodeKey]['fcs'][$fc]['data_date'] = $dataDate;
            }
            $hierarchy[$nodeKey]['fcs'][$fc]['details'][] = $row;
        }

        $hierarchy = array_values(array_map(static function (array $group): array {
            $group['fcs'] = array_values($group['fcs']);
            return $group;
        }, $hierarchy));

        $summary = UsFcInventory::query()
            ->leftJoin('us_fc_locations as loc', 'loc.fulfillment_center_id', '=', 'us_fc_inventories.fulfillment_center_id')
            ->leftJoin('marketplaces as mp', 'mp.id', '=', 'us_fc_inventories.marketplace_id')
            ->selectRaw('coalesce(loc.country_code, upper(mp.country_code), "Unknown") as country_code')
            ->selectRaw('coalesce(loc.state, "Unknown") as state')
            ->selectRaw('sum(us_fc_inventories.quantity_available) as qty')
            ->selectRaw('max(us_fc_inventories.report_date) as data_date')
            ->when($reportDate !== '', fn ($q) => $q->whereDate('us_fc_inventories.report_date', '=', $reportDate))
            ->when(!empty($skuSelection), fn ($q) => $q->whereIn('us_fc_inventories.seller_sku', $skuSelection))
            ->when($asin !== '', fn ($q) => $q->where('us_fc_inventories.asin', 'like', '%' . $asin . '%'))
            ->when($regionFilter !== '', fn ($q) => $this->applyRegionFilter($q, $regionFilter))
            ->groupBy(DB::raw('coalesce(loc.country_code, upper(mp.country_code), "Unknown")'), DB::raw('coalesce(loc.state, "Unknown")'))
            ->orderBy('country_code')
            ->orderBy('state')
            ->get();

        $fcSummary = UsFcInventory::query()
            ->leftJoin('us_fc_locations as loc', 'loc.fulfillment_center_id', '=', 'us_fc_inventories.fulfillment_center_id')
            ->leftJoin('marketplaces as mp', 'mp.id', '=', 'us_fc_inventories.marketplace_id')
            ->selectRaw('us_fc_inventories.fulfillment_center_id as fc')
            ->selectRaw('coalesce(loc.city, "Unknown") as city')
            ->selectRaw('coalesce(loc.state, "Unknown") as state')
            ->selectRaw('coalesce(loc.country_code, upper(mp.country_code), "Unknown") as country_code')
            ->selectRaw('max(loc.lat) as lat')
            ->selectRaw('max(loc.lng) as lng')
            ->selectRaw('sum(us_fc_inventories.quantity_available) as qty')
            ->selectRaw('count(*) as row_count')
            ->selectRaw('max(us_fc_inventories.report_date) as data_date')
            ->when($reportDate !== '', fn ($q) => $q->whereDate('us_fc_inventories.report_date', '=', $reportDate))
            ->when(!empty($skuSelection), fn ($q) => $q->whereIn('us_fc_inventories.seller_sku', $skuSelection))
            ->when($asin !== '', fn ($q) => $q->where('us_fc_inventories.asin', 'like', '%' . $asin . '%'))
            ->when($regionFilter !== '', fn ($q) => $this->applyRegionFilter($q, $regionFilter))
            ->when($stateFilter !== '', fn ($q) => $q->whereRaw('upper(coalesce(loc.state, "")) = ?', [$stateFilter]))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('us_fc_inventories.seller_sku', 'like', '%' . $q . '%')
                        ->orWhere('us_fc_inventories.asin', 'like', '%' . $q . '%')
                        ->orWhere('us_fc_inventories.fnsku', 'like', '%' . $q . '%')
                        ->orWhere('us_fc_inventories.fulfillment_center_id', 'like', '%' . $q . '%')
                        ->orWhere('loc.city', 'like', '%' . $q . '%')
                        ->orWhere('loc.state', 'like', '%' . $q . '%')
                        ->orWhere('loc.country_code', 'like', '%' . $q . '%');
                });
            })
            ->groupBy(
                'us_fc_inventories.fulfillment_center_id',
                DB::raw('coalesce(loc.city, "Unknown")'),
                DB::raw('coalesce(loc.state, "Unknown")'),
                DB::raw('coalesce(loc.country_code, upper(mp.country_code), "Unknown")')
            )
            ->orderByRaw('coalesce(max(loc.country_code), max(upper(mp.country_code)), "ZZ") asc')
            ->orderByRaw('coalesce(max(loc.state), "ZZ") asc')
            ->orderByRaw('coalesce(max(loc.city), "ZZZZZZ") asc')
            ->orderBy('us_fc_inventories.fulfillment_center_id')
            ->get();

        $skuOptionsQuery = UsFcInventory::query()
            ->leftJoin('us_fc_locations as loc', 'loc.fulfillment_center_id', '=', 'us_fc_inventories.fulfillment_center_id')
            ->leftJoin('marketplaces as mp', 'mp.id', '=', 'us_fc_inventories.marketplace_id')
            ->select('us_fc_inventories.seller_sku')
            ->whereNotNull('us_fc_inventories.seller_sku')
            ->whereRaw('trim(us_fc_inventories.seller_sku) <> ""');

        if ($reportDate !== '') {
            $skuOptionsQuery->whereDate('us_fc_inventories.report_date', '=', $reportDate);
        }
        if ($asin !== '') {
            $skuOptionsQuery->where('us_fc_inventories.asin', 'like', '%' . $asin . '%');
        }
        if ($regionFilter !== '') {
            $this->applyRegionFilter($skuOptionsQuery, $regionFilter);
        }
        if ($stateFilter !== '') {
            $skuOptionsQuery->whereRaw('upper(coalesce(loc.state, "")) = ?', [$stateFilter]);
        }

        $skuOptions = $skuOptionsQuery
            ->distinct()
            ->orderBy('us_fc_inventories.seller_sku')
            ->pluck('us_fc_inventories.seller_sku')
            ->values()
            ->all();

        $hashByFcKey = [];
        foreach ($fcSummary as $row) {
            $city = trim((string) ($row->city ?? ''));
            $region = trim((string) ($row->state ?? ''));
            $countryCode = strtoupper(trim((string) ($row->country_code ?? '')));
            if ($city === '' || strtoupper($city) === 'UNKNOWN') {
                continue;
            }
            if ($countryCode === '' || $countryCode === 'UNKNOWN') {
                continue;
            }
            $hashByFcKey[$this->fcHashKey((string) $row->fc, $countryCode)] = CityGeo::lookupHash($countryCode, $city, $region);
        }

        $geoByHash = CityGeo::query()
            ->whereIn('lookup_hash', array_values(array_unique(array_values($hashByFcKey))))
            ->get(['lookup_hash', 'lat', 'lng'])
            ->keyBy('lookup_hash');

        $fcMapPoints = [];
        $stateCentroids = $this->usStateCentroids();
        $countryCentroids = $this->countryCentroids();
        $knownFcCoordinates = $this->knownFcCoordinates();

        foreach ($fcSummary as $row) {
            $fc = (string) $row->fc;
            $fcCode = strtoupper(trim($fc));
            $countryCode = strtoupper(trim((string) ($row->country_code ?? '')));
            $countryCode = $countryCode !== '' ? $countryCode : 'US';
            $stateCode = strtoupper(trim((string) ($row->state ?? '')));
            $hash = $hashByFcKey[$this->fcHashKey($fc, $countryCode)] ?? null;

            $lat = null;
            $lng = null;
            $isApproximate = false;

            $rowLat = is_numeric($row->lat) ? (float) $row->lat : null;
            $rowLng = is_numeric($row->lng) ? (float) $row->lng : null;
            if ($rowLat !== null && $rowLng !== null) {
                $lat = $rowLat;
                $lng = $rowLng;
            } elseif (isset($knownFcCoordinates[$fcCode])) {
                [$lat, $lng] = $knownFcCoordinates[$fcCode];
            } elseif ($hash && isset($geoByHash[$hash])) {
                $geo = $geoByHash[$hash];
                $lat = (float) $geo->lat;
                $lng = (float) $geo->lng;
            } else {
                [$lat, $lng] = $this->fallbackMapCoords($countryCode, $stateCode, $fc, $stateCentroids, $countryCentroids);
                $isApproximate = true;
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
                'country_code' => $countryCode,
                'qty' => (int) $row->qty,
                'rows' => (int) $row->row_count,
                'data_date' => (string) ($row->data_date ?? ''),
                'lat' => $lat,
                'lng' => $lng,
                'approximate' => $isApproximate,
            ];
        }

        return view('inventory.us_fc', [
            'rows' => $rows,
            'hierarchy' => $hierarchy,
            'summary' => $summary,
            'fcSummary' => $fcSummary,
            'fcMapPoints' => $fcMapPoints,
            'search' => $q,
            'skuSelection' => $skuSelection,
            'skuOptions' => $skuOptions,
            'asin' => $asin,
            'region' => $regionFilter,
            'state' => $stateFilter,
            'perPage' => $perPageInput === 'all' ? 'all' : (string) $perPage,
            'perPageCapped' => $perPageCapped,
            'perPageCap' => self::MAX_PER_PAGE_ALL,
            'reportDate' => $reportDate,
            'latestReportDate' => $latestReportDate,
        ]);
    }

    public function downloadLocationsCsv()
    {
        $inventoryFcSubquery = UsFcInventory::query()
            ->select('fulfillment_center_id')
            ->whereNotNull('fulfillment_center_id')
            ->whereRaw('trim(fulfillment_center_id) <> ""')
            ->distinct();

        $rows = DB::query()
            ->fromSub($inventoryFcSubquery, 'inv_fc')
            ->leftJoin('us_fc_locations as loc', 'loc.fulfillment_center_id', '=', 'inv_fc.fulfillment_center_id')
            ->leftJoin('us_fc_inventories as inv', 'inv.fulfillment_center_id', '=', 'inv_fc.fulfillment_center_id')
            ->leftJoin('marketplaces as mp', 'mp.id', '=', 'inv.marketplace_id')
            ->selectRaw('inv_fc.fulfillment_center_id as fulfillment_center_id')
            ->selectRaw('coalesce(max(loc.city), "") as city')
            ->selectRaw('coalesce(max(loc.state), "") as state')
            ->selectRaw('coalesce(max(loc.country_code), max(upper(mp.country_code)), "") as country_code')
            ->selectRaw('max(loc.lat) as lat')
            ->selectRaw('max(loc.lng) as lng')
            ->selectRaw('coalesce(max(loc.label), "") as label')
            ->selectRaw('coalesce(max(loc.location_source), "") as location_source')
            ->selectRaw('max(loc.updated_at) as updated_at')
            ->groupBy('inv_fc.fulfillment_center_id')
            ->orderByRaw('coalesce(max(loc.country_code), max(upper(mp.country_code)), "ZZ") asc')
            ->orderByRaw('coalesce(max(loc.state), "ZZ") asc')
            ->orderByRaw('coalesce(max(loc.city), "ZZZZZZ") asc')
            ->orderBy('inv_fc.fulfillment_center_id')
            ->get();

        $filename = 'fc-locations-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, ['fc_code', 'city', 'state_or_region', 'country_code', 'lat', 'lng', 'label', 'location_source', 'updated_at']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    (string) $row->fulfillment_center_id,
                    (string) ($row->city ?? ''),
                    (string) ($row->state ?? ''),
                    (string) ($row->country_code ?? ''),
                    $row->lat !== null ? (string) $row->lat : '',
                    $row->lng !== null ? (string) $row->lng : '',
                    (string) ($row->label ?? ''),
                    (string) ($row->location_source ?? ''),
                    (string) optional($row->updated_at)->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function uploadLocationsCsv(Request $request)
    {
        $validated = $request->validate([
            'locations_csv' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['locations_csv'];
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return redirect()->route('inventory.fc')->with('inventory_fc_error', 'Unable to read uploaded CSV file.');
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            return redirect()->route('inventory.fc')->with('inventory_fc_error', 'CSV appears to be empty.');
        }

        $headerMap = [];
        foreach ($header as $index => $column) {
            $normalized = strtolower(trim((string) $column));
            if ($normalized !== '') {
                $headerMap[$normalized] = (int) $index;
            }
        }

        $fcIndex = $headerMap['fc_code'] ?? $headerMap['fulfillment_center_id'] ?? null;
        if (!is_int($fcIndex)) {
            fclose($handle);
            return redirect()->route('inventory.fc')->with('inventory_fc_error', 'CSV must include an fc_code column.');
        }

        $updates = 0;
        while (($data = fgetcsv($handle)) !== false) {
            $fc = strtoupper(trim((string) ($data[$fcIndex] ?? '')));
            if ($fc === '') {
                continue;
            }

            $location = UsFcLocation::query()->firstOrNew(['fulfillment_center_id' => $fc]);
            $changed = false;

            $city = $this->csvField($data, $headerMap, ['city']);
            if ($city !== null) {
                $location->city = $city;
                $changed = true;
            }

            $state = $this->csvField($data, $headerMap, ['state_or_region', 'state', 'region']);
            if ($state !== null) {
                $location->state = $state;
                $changed = true;
            }

            $country = $this->csvField($data, $headerMap, ['country_code', 'country']);
            if ($country !== null) {
                $country = strtoupper($country);
                if ($country === 'UK') {
                    $country = 'GB';
                }
                $location->country_code = $country;
                $changed = true;
            }

            $lat = $this->csvCoordinate($data, $headerMap, ['lat', 'latitude'], -90.0, 90.0);
            if ($lat !== null) {
                $location->lat = $lat;
                $changed = true;
            }

            $lng = $this->csvCoordinate($data, $headerMap, ['lng', 'lon', 'longitude', 'long'], -180.0, 180.0);
            if ($lng !== null) {
                $location->lng = $lng;
                $changed = true;
            }

            $label = $this->csvField($data, $headerMap, ['label']);
            if ($label !== null) {
                $location->label = $label;
                $changed = true;
            }

            if ($changed) {
                if ($location->label === null || trim((string) $location->label) === '') {
                    $location->label = $this->locationLabel(
                        $fc,
                        (string) ($location->city ?? ''),
                        (string) ($location->state ?? ''),
                        (string) ($location->country_code ?? '')
                    );
                }
                if ($location->country_code === null || trim((string) $location->country_code) === '') {
                    $location->country_code = 'US';
                }
                $location->location_source = 'csv_upload';
                $location->save();
                $updates++;
            }
        }

        fclose($handle);

        return redirect()->route('inventory.fc')->with('inventory_fc_status', "Location CSV imported. Updated {$updates} FC records.");
    }

    private function csvField(array $row, array $headerMap, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!isset($headerMap[$key])) {
                continue;
            }
            $value = trim((string) ($row[$headerMap[$key]] ?? ''));
            if ($value === '') {
                return null;
            }

            return $value;
        }

        return null;
    }

    private function applyRegionFilter(object $query, string $region): void
    {
        $countrySql = <<<SQL
coalesce(
    nullif(
        case
            when upper(trim(coalesce(loc.country_code, ''))) in ('', 'UNKNOWN') then ''
            when upper(trim(loc.country_code)) = 'UK' then 'GB'
            else upper(trim(loc.country_code))
        end,
        ''
    ),
    nullif(
        case
            when upper(trim(coalesce(mp.country_code, ''))) in ('', 'UNKNOWN') then ''
            when upper(trim(mp.country_code)) = 'UK' then 'GB'
            else upper(trim(mp.country_code))
        end,
        ''
    ),
    ''
)
SQL;
        $countryColumn = DB::raw($countrySql);
        $marketplaceColumn = 'us_fc_inventories.marketplace_id';

        $ukMarketplaceIds = [
            'A1F83G8C2ARO7P', // UK
        ];
        $euMarketplaceIds = [
            'A1PA6795UKMFR9', // DE
            'A13V1IB3VIYZZH', // FR
            'APJ6JRA9NG5V4',  // IT
            'A1RKKUPIHCS9HS', // ES
            'A1805IZSGTT6HS', // NL
            'A2NODRKZP88ZB9', // SE
            'A1C3SOZRARQ6R3', // PL
            'AMEN7PMS3EDWL',  // BE
        ];
        $naMarketplaceIds = [
            'ATVPDKIKX0DER',  // US
            'A2EUQ1WTGCTBG2', // CA
            'A1AM78C64UM0Y8', // MX
            'A2Q3Y263D00KWC', // BR
        ];

        if ($region === 'UK') {
            $query->where(function ($w) use ($countryColumn, $countrySql, $marketplaceColumn, $ukMarketplaceIds) {
                $w->whereIn($countryColumn, ['GB', 'UK'])
                    ->orWhere(function ($fallback) use ($countrySql, $marketplaceColumn, $ukMarketplaceIds) {
                        $fallback->whereRaw("{$countrySql} = ''")
                            ->whereIn($marketplaceColumn, $ukMarketplaceIds);
                    });
            });
            return;
        }

        if ($region === 'EU') {
            $query->where(function ($w) use ($countryColumn, $countrySql, $marketplaceColumn, $euMarketplaceIds) {
                $w->whereIn($countryColumn, ['AT', 'BE', 'CH', 'CZ', 'DE', 'DK', 'ES', 'FI', 'FR', 'IE', 'IT', 'LU', 'NL', 'NO', 'PL', 'SE'])
                    ->orWhere(function ($fallback) use ($countrySql, $marketplaceColumn, $euMarketplaceIds) {
                        $fallback->whereRaw("{$countrySql} = ''")
                            ->whereIn($marketplaceColumn, $euMarketplaceIds);
                    });
            });
            return;
        }

        if ($region === 'NA') {
            $query->where(function ($w) use ($countryColumn, $countrySql, $marketplaceColumn, $naMarketplaceIds) {
                $w->whereIn($countryColumn, ['US', 'CA', 'MX', 'BR'])
                    ->orWhere(function ($fallback) use ($countrySql, $marketplaceColumn, $naMarketplaceIds) {
                        $fallback->whereRaw("{$countrySql} = ''")
                            ->whereIn($marketplaceColumn, $naMarketplaceIds);
                    });
            });
        }
    }

    private function csvCoordinate(array $row, array $headerMap, array $keys, float $min, float $max): ?float
    {
        foreach ($keys as $key) {
            if (!isset($headerMap[$key])) {
                continue;
            }
            $value = trim((string) ($row[$headerMap[$key]] ?? ''));
            if ($value === '' || !is_numeric($value)) {
                continue;
            }
            $number = (float) $value;
            if ($number < $min || $number > $max) {
                continue;
            }

            return $number;
        }

        return null;
    }

    private function fcHashKey(string $fc, string $countryCode): string
    {
        return strtoupper(trim($countryCode)) . '|' . strtoupper(trim($fc));
    }

    private function locationLabel(string $fc, string $city, string $state, string $country): string
    {
        $parts = [];
        if (trim($city) !== '') {
            $parts[] = trim($city);
        }
        if (trim($state) !== '') {
            $parts[] = strtoupper(trim($state));
        }
        if (empty($parts) && trim($country) !== '') {
            $parts[] = strtoupper(trim($country));
        }

        return empty($parts) ? $fc : ($fc . ' - ' . implode(', ', $parts));
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

    private function countryCentroids(): array
    {
        return [
            'US' => [39.8283, -98.5795],
            'GB' => [54.0, -2.0],
            'DE' => [51.1657, 10.4515],
            'FR' => [46.2276, 2.2137],
            'IT' => [41.8719, 12.5674],
            'ES' => [40.4637, -3.7492],
            'NL' => [52.1326, 5.2913],
            'SE' => [60.1282, 18.6435],
            'PL' => [51.9194, 19.1451],
            'BE' => [50.5039, 4.4699],
            'IE' => [53.1424, -7.6921],
            'AT' => [47.5162, 14.5501],
            'CZ' => [49.8175, 15.4730],
            'TR' => [38.9637, 35.2433],
            'AE' => [23.4241, 53.8478],
            'SA' => [23.8859, 45.0792],
            'JP' => [36.2048, 138.2529],
            'AU' => [-25.2744, 133.7751],
            'CA' => [56.1304, -106.3468],
            'MX' => [23.6345, -102.5528],
        ];
    }

    private function fallbackMapCoords(string $countryCode, string $state, string $fc, array $stateCentroids, array $countryCentroids): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $state = strtoupper(trim($state));

        if ($countryCode === 'US' && isset($stateCentroids[$state])) {
            [$baseLat, $baseLng] = $stateCentroids[$state];
        } else {
            [$baseLat, $baseLng] = $countryCentroids[$countryCode] ?? [20.0, 0.0];
        }

        $seedSource = strtoupper(trim($fc)) !== '' ? strtoupper(trim($fc)) : ($countryCode !== '' ? $countryCode : 'GLOBAL');
        $seed = (int) sprintf('%u', crc32($seedSource));

        $latOffset = ((($seed % 1000) / 999) - 0.5) * 0.6;
        $lngOffset = ((((int) floor($seed / 1000) % 1000) / 999) - 0.5) * 1.0;

        $lat = max(-90.0, min(90.0, (float) $baseLat + $latOffset));
        $lng = max(-180.0, min(180.0, (float) $baseLng + $lngOffset));

        return [$lat, $lng];
    }

    private function knownFcCoordinates(): array
    {
        return [
            'BFI4' => [47.4141, -122.2613],
            'BHM1' => [33.3758, -87.0100],
            'DAB2' => [41.7061, -93.4687],
            'DEN4' => [38.7739, -104.7181],
            'FWA4' => [40.9948, -85.2178],
            'JAN1' => [32.5539, -90.0264],
            'JAX2' => [30.4654, -81.6825],
            'LAS2' => [36.2300, -115.1051],
            'LBE1' => [40.2283, -79.6284],
            'OMA2' => [41.1390, -96.0465],
            'RMN3' => [38.3923, -77.4662],
            'SBD1' => [34.0537, -117.3879],
        ];
    }
}
