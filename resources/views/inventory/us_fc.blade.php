<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('US FC Inventory') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                <form method="GET" action="{{ route('inventory.us_fc') }}" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="q" class="block text-sm font-medium mb-1">Search</label>
                        <input id="q" name="q" value="{{ $search }}" class="border rounded px-2 py-1" placeholder="SKU / ASIN / FNSKU / FC / city / state">
                    </div>
                    <div>
                        <label for="sku" class="block text-sm font-medium mb-1">SKU</label>
                        <select id="sku" name="sku[]" multiple size="6" class="border rounded px-2 py-1 min-w-[280px]">
                            @foreach(($skuOptions ?? []) as $optSku)
                                <option value="{{ $optSku }}" {{ in_array($optSku, ($skuSelection ?? []), true) ? 'selected' : '' }}>
                                    {{ $optSku }}
                                </option>
                            @endforeach
                        </select>
                        <div class="text-xs text-gray-600 mt-1">
                            Default (none selected) = all SKUs. Hold Ctrl/Cmd to select multiple.
                        </div>
                    </div>
                    <div>
                        <label for="asin" class="block text-sm font-medium mb-1">ASIN</label>
                        <input id="asin" name="asin" value="{{ $asin ?? '' }}" class="border rounded px-2 py-1" placeholder="B0...">
                    </div>
                    <div>
                        <label for="state" class="block text-sm font-medium mb-1">State</label>
                        <input id="state" name="state" value="{{ $state }}" class="border rounded px-2 py-1" placeholder="TX">
                    </div>
                    <div>
                        <label for="report_date" class="block text-sm font-medium mb-1">Report Date</label>
                        <input id="report_date" name="report_date" value="{{ $reportDate }}" class="border rounded px-2 py-1" placeholder="YYYY-MM-DD">
                    </div>
                    <div>
                        <label for="per_page" class="block text-sm font-medium mb-1">Rows</label>
                        <select id="per_page" name="per_page" class="border rounded px-2 py-1">
                            @foreach (['100', '250', '500', '1000', 'all'] as $size)
                                <option value="{{ $size }}" {{ ($perPage ?? '100') === $size ? 'selected' : '' }}>
                                    {{ strtoupper($size) === 'ALL' ? 'All' : $size }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Apply</button>
                    <a href="{{ route('inventory.us_fc') }}" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Clear</a>
                </form>
                <div class="mt-3 text-sm text-gray-700 dark:text-gray-200">
                    <strong>Snapshot Date:</strong> {{ $reportDate !== '' ? $reportDate : 'N/A' }}
                    <span class="mx-2">|</span>
                    <strong>Latest Available Date:</strong> {{ $latestReportDate ?? 'N/A' }}
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                <h3 class="font-semibold mb-2">FC Quantity Map</h3>
                @php
                    $mapPoints = $fcMapPoints ?? [];
                @endphp
                <div class="mb-2 text-sm text-gray-700 dark:text-gray-200">
                    Map points computed: <strong>{{ count($mapPoints) }}</strong>
                </div>
                @if (count($mapPoints) > 0)
                    <div id="us-fc-map" style="height: 520px; width: 100%; border-radius: 8px; overflow: hidden;"></div>
                @else
                    <div class="text-sm text-gray-700 dark:text-gray-200">
                        No geocoded FC points available for the current filters.
                    </div>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                <h3 class="font-semibold mb-2">Inventory Hierarchy (State → FC → Detail)</h3>
                <div class="mb-2 text-sm text-gray-700 dark:text-gray-200">
                    States on this page: <strong>{{ count($hierarchy ?? []) }}</strong>
                    <span class="mx-2">|</span>
                    Detail rows on this page: <strong>{{ count($rows) }}</strong>
                    <span class="mx-2">|</span>
                    Total filtered rows: <strong>{{ number_format($rows->total()) }}</strong>
                </div>
                <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                    <thead>
                    <tr>
                        <th class="text-left">Level</th>
                        <th class="text-left">FC ID</th>
                        <th class="text-left">City</th>
                        <th class="text-left">State</th>
                        <th class="text-left">SKU</th>
                        <th class="text-left">ASIN</th>
                        <th class="text-left">FNSKU</th>
                        <th class="text-left">Condition</th>
                        <th class="text-right">Qty</th>
                        <th class="text-left">Report Date</th>
                        <th class="text-left">Updated</th>
                    </tr>
                    </thead>
                    <tbody>
                    @php $stateIndex = 0; @endphp
                    @forelse($hierarchy as $stateNode)
                    @php
                        $stateId = 'state-' . $stateIndex;
                        $stateIndex++;
                        $stateCode = strtoupper((string) ($stateNode['state'] ?? ''));
                        $stateNames = [
                            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
                            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
                            'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
                            'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
                            'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
                            'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
                            'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
                            'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
                            'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
                            'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
                            'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
                            'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
                            'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
                        ];
                        $stateLabel = $stateCode;
                        if (isset($stateNames[$stateCode])) {
                            $stateLabel = $stateCode . ' - ' . $stateNames[$stateCode];
                        }
                    @endphp
                        <tr class="bg-gray-100 dark:bg-gray-700">
                            <td>
                                <button type="button" class="toggle-state font-mono text-xs px-2 py-1 border rounded" data-state-id="{{ $stateId }}">+</button>
                                <strong class="ml-2">State</strong>
                            </td>
                            <td></td>
                            <td></td>
                            <td><strong>{{ $stateLabel }}</strong></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="text-right"><strong>{{ number_format((int) $stateNode['qty']) }}</strong></td>
                            <td>{{ $stateNode['data_date'] ?: 'N/A' }}</td>
                            <td></td>
                        </tr>
                        @foreach($stateNode['fcs'] as $fcNode)
                            @php $fcId = $stateId . '-fc-' . $loop->index; @endphp
                            <tr class="fc-row hidden bg-gray-50 dark:bg-gray-800" data-state-parent="{{ $stateId }}">
                                <td class="pl-6">
                                    <button type="button" class="toggle-fc font-mono text-xs px-2 py-1 border rounded" data-fc-id="{{ $fcId }}">+</button>
                                    <strong class="ml-2">FC</strong>
                                </td>
                                <td><strong>{{ $fcNode['fc'] }}</strong></td>
                                <td>{{ $fcNode['city'] }}</td>
                                <td>{{ $fcNode['state'] }}</td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td></td>
                                <td class="text-right"><strong>{{ number_format((int) $fcNode['qty']) }}</strong></td>
                                <td>{{ $fcNode['data_date'] ?: 'N/A' }}</td>
                                <td>Rows: {{ number_format((int) $fcNode['row_count']) }}</td>
                            </tr>
                            @foreach($fcNode['details'] as $detailRow)
                                <tr class="detail-row hidden" data-state-parent="{{ $stateId }}" data-fc-parent="{{ $fcId }}">
                                    <td class="pl-12">Detail</td>
                                    <td>{{ $detailRow->fulfillment_center_id }}</td>
                                    <td>{{ $detailRow->fc_city ?? 'Unknown' }}</td>
                                    <td>{{ $detailRow->fc_state ?? 'Unknown' }}</td>
                                    <td>{{ $detailRow->seller_sku }}</td>
                                    <td>{{ $detailRow->asin }}</td>
                                    <td>{{ $detailRow->fnsku }}</td>
                                    <td>{{ $detailRow->item_condition }}</td>
                                    <td class="text-right">{{ number_format((int) $detailRow->quantity_available) }}</td>
                                    <td>{{ $detailRow->report_date }}</td>
                                    <td>{{ optional($detailRow->updated_at)->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    @empty
                        <tr><td colspan="11">No US FC inventory rows found. Run <code>php artisan inventory:sync-us-fc</code>.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                <div class="mt-3">
                    {{ $rows->links() }}
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const hideAllForState = (stateId) => {
                document.querySelectorAll(`.fc-row[data-state-parent="${stateId}"]`).forEach((row) => {
                    row.classList.add('hidden');
                });
                document.querySelectorAll(`.detail-row[data-state-parent="${stateId}"]`).forEach((row) => {
                    row.classList.add('hidden');
                });
                document.querySelectorAll(`.toggle-fc[data-fc-id^="${stateId}-fc-"]`).forEach((btn) => {
                    btn.textContent = '+';
                });
            };

            document.querySelectorAll('.toggle-state').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const stateId = btn.dataset.stateId;
                    const fcRows = document.querySelectorAll(`.fc-row[data-state-parent="${stateId}"]`);
                    const isOpening = btn.textContent === '+';
                    btn.textContent = isOpening ? '-' : '+';
                    if (!isOpening) {
                        hideAllForState(stateId);
                        return;
                    }
                    fcRows.forEach((row) => row.classList.remove('hidden'));
                });
            });

            document.querySelectorAll('.toggle-fc').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const fcId = btn.dataset.fcId;
                    const detailRows = document.querySelectorAll(`.detail-row[data-fc-parent="${fcId}"]`);
                    const isOpening = btn.textContent === '+';
                    btn.textContent = isOpening ? '-' : '+';
                    detailRows.forEach((row) => row.classList.toggle('hidden', !isOpening));
                });
            });
        })();
    </script>

    @if (count($mapPoints) > 0)
        <link
            rel="stylesheet"
            href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
            integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
            crossorigin=""
        />
        <link
            rel="stylesheet"
            href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css"
        />
        <link
            rel="stylesheet"
            href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css"
        />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
        <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
        <script>
            (function () {
                const points = @json($mapPoints);
                console.log('[US FC MAP] points', points.length, points.slice(0, 5));
                const selectedState = @json($state ?? '');
                const map = L.map('us-fc-map', { zoomControl: true }).setView([39.8283, -98.5795], 4);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);

                const clusterGroup = L.markerClusterGroup({ chunkedLoading: true });
                map.addLayer(clusterGroup);

                const bounds = [];
                let invalidPoints = 0;

                for (const p of points) {
                    const lat = Number(p.lat);
                    const lng = Number(p.lng);
                    if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
                        invalidPoints++;
                        continue;
                    }

                    const qty = Number(p.qty || 0);
                    const marker = L.marker([lat, lng]);

                    marker.bindPopup(
                        `<strong>${p.fc}</strong><br>` +
                        `${p.city}, ${p.state}<br>` +
                        `Qty: ${qty.toLocaleString()}<br>` +
                        `Rows: ${Number(p.rows || 0).toLocaleString()}<br>` +
                        `Data Date: ${p.data_date || 'N/A'}`
                    );

                    clusterGroup.addLayer(marker);
                    bounds.push([lat, lng]);
                }

                console.log('[US FC MAP] bounds count', bounds.length);
                console.log('[US FC MAP] invalid points skipped', invalidPoints);

                if (bounds.length > 1) {
                    map.fitBounds(bounds, { padding: [24, 24] });
                } else if (bounds.length === 1) {
                    map.setView(bounds[0], 8);
                }

                if (selectedState) {
                    const stateNames = {
                        AL: 'Alabama', AK: 'Alaska', AZ: 'Arizona', AR: 'Arkansas',
                        CA: 'California', CO: 'Colorado', CT: 'Connecticut', DE: 'Delaware',
                        FL: 'Florida', GA: 'Georgia', HI: 'Hawaii', ID: 'Idaho',
                        IL: 'Illinois', IN: 'Indiana', IA: 'Iowa', KS: 'Kansas',
                        KY: 'Kentucky', LA: 'Louisiana', ME: 'Maine', MD: 'Maryland',
                        MA: 'Massachusetts', MI: 'Michigan', MN: 'Minnesota', MS: 'Mississippi',
                        MO: 'Missouri', MT: 'Montana', NE: 'Nebraska', NV: 'Nevada',
                        NH: 'New Hampshire', NJ: 'New Jersey', NM: 'New Mexico', NY: 'New York',
                        NC: 'North Carolina', ND: 'North Dakota', OH: 'Ohio', OK: 'Oklahoma',
                        OR: 'Oregon', PA: 'Pennsylvania', RI: 'Rhode Island', SC: 'South Carolina',
                        SD: 'South Dakota', TN: 'Tennessee', TX: 'Texas', UT: 'Utah',
                        VT: 'Vermont', VA: 'Virginia', WA: 'Washington', WV: 'West Virginia',
                        WI: 'Wisconsin', WY: 'Wyoming', DC: 'District of Columbia'
                    };
                    const selectedStateCode = String(selectedState).toUpperCase();
                    const selectedStateName = stateNames[selectedStateCode] || selectedStateCode;

                    fetch('https://raw.githubusercontent.com/PublicaMundi/MappingAPI/master/data/geojson/us-states.json')
                        .then((resp) => resp.json())
                        .then((geojson) => {
                            const feature = (geojson.features || []).find((f) => {
                                const name = String(f?.properties?.name || '').trim();
                                return name.toLowerCase() === selectedStateName.toLowerCase();
                            });
                            if (!feature) {
                                return;
                            }

                            const highlight = L.geoJSON(feature, {
                                style: {
                                    color: '#ef4444',
                                    weight: 3,
                                    opacity: 0.95,
                                    fillColor: '#fca5a5',
                                    fillOpacity: 0.12
                                }
                            }).addTo(map);
                            map.fitBounds(highlight.getBounds(), { padding: [20, 20] });
                        })
                        .catch(() => {
                            // Ignore boundary overlay errors; markers still render.
                        });
                }
            })();
        </script>
    @endif
</x-app-layout>
