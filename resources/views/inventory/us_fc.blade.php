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
                        <input id="sku" name="sku" value="{{ $sku ?? '' }}" class="border rounded px-2 py-1" placeholder="seller sku">
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
                <h3 class="font-semibold mb-2">Quantity by FC (Granular)</h3>
                <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                    <thead>
                    <tr>
                        <th class="text-left">FC ID</th>
                        <th class="text-left">City</th>
                        <th class="text-left">State</th>
                        <th class="text-left">Data Date</th>
                        <th class="text-right">Rows</th>
                        <th class="text-right">Quantity</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($fcSummary as $row)
                        <tr>
                            <td>{{ $row->fc }}</td>
                            <td>{{ $row->city }}</td>
                            <td>{{ $row->state }}</td>
                            <td>{{ $row->data_date ?? 'N/A' }}</td>
                            <td class="text-right">{{ number_format((int) $row->row_count) }}</td>
                            <td class="text-right">{{ number_format((int) $row->qty) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No data.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                <h3 class="font-semibold mb-2">Quantity by State</h3>
                <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                    <thead>
                    <tr>
                        <th class="text-left">State</th>
                        <th class="text-left">Data Date</th>
                        <th class="text-right">Quantity</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($summary as $row)
                        <tr>
                            <td>{{ $row->state }}</td>
                            <td>{{ $row->data_date ?? 'N/A' }}</td>
                            <td class="text-right">{{ number_format((int) $row->qty) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">No data.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                    <thead>
                    <tr>
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
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ $row->fulfillment_center_id }}</td>
                            <td>{{ $row->fc_city ?? 'Unknown' }}</td>
                            <td>{{ $row->fc_state ?? 'Unknown' }}</td>
                            <td>{{ $row->seller_sku }}</td>
                            <td>{{ $row->asin }}</td>
                            <td>{{ $row->fnsku }}</td>
                            <td>{{ $row->item_condition }}</td>
                            <td class="text-right">{{ number_format((int) $row->quantity_available) }}</td>
                            <td>{{ $row->report_date }}</td>
                            <td>{{ optional($row->updated_at)->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10">No US FC inventory rows found. Run <code>php artisan inventory:sync-us-fc</code>.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                <div class="mt-3">
                    {{ $rows->links() }}
                </div>
            </div>
        </div>
    </div>

    @if (count($mapPoints) > 0)
        <link
            rel="stylesheet"
            href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
            integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
            crossorigin=""
        />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
        <script>
            (function () {
                const points = @json($mapPoints);
                console.log('[US FC MAP] points', points.length, points.slice(0, 5));
                const map = L.map('us-fc-map', { zoomControl: true }).setView([39.8283, -98.5795], 4);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);

                const maxQty = points.reduce((max, p) => Math.max(max, Number(p.qty || 0)), 1);
                const bounds = [];

                for (const p of points) {
                    const qty = Number(p.qty || 0);
                    const radius = Math.max(6, Math.min(24, 6 + (qty / maxQty) * 18));
                    const marker = L.circleMarker([p.lat, p.lng], {
                        radius,
                        weight: 1,
                        color: '#0b2a4a',
                        fillColor: '#3b82f6',
                        fillOpacity: 0.8
                    }).addTo(map);

                    marker.bindPopup(
                        `<strong>${p.fc}</strong><br>` +
                        `${p.city}, ${p.state}<br>` +
                        `Qty: ${qty.toLocaleString()}<br>` +
                        `Rows: ${Number(p.rows || 0).toLocaleString()}<br>` +
                        `Data Date: ${p.data_date || 'N/A'}`
                    );

                    bounds.push([p.lat, p.lng]);
                }

                console.log('[US FC MAP] bounds count', bounds.length);

                if (bounds.length > 1) {
                    map.fitBounds(bounds, { padding: [24, 24] });
                } else if (bounds.length === 1) {
                    map.setView(bounds[0], 8);
                }
            })();
        </script>
    @endif
</x-app-layout>
