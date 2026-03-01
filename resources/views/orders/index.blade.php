<x-app-layout>
<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        {{ __('Orders') }}
    </h2>
</x-slot>

<div class="py-4 px-4">
    <!-- Simple Filters -->
    <div class="bg-gray-50 dark:bg-gray-900 p-4 mb-4 rounded">
        <form method="GET" action="{{ route('orders.index') }}" id="filterForm">
            <div class="mb-3">
                <strong class="block mb-2">Filter by Country:</strong>
                <div class="flex flex-wrap gap-2">
                    @foreach($countries as $countryCode => $country)
                        <label class="cursor-pointer">
                            <input
                                type="checkbox"
                                name="countries[]"
                                value="{{ $countryCode }}"
                                class="sr-only peer"
                                {{ in_array($countryCode, $selectedCountries) ? 'checked' : '' }}
                                onchange="document.getElementById('filterForm').submit()"
                            >
                            <span
                                class="inline-flex items-center justify-between gap-3 min-w-[88px] px-3 py-2 text-base border rounded-md shadow-sm"
                                style="{{ in_array($countryCode, $selectedCountries)
                                    ? 'background:#cfe4ff;color:#0b2a4a;border-color:#88b5ff;box-shadow:0 0 0 2px #b9d4ff;'
                                    : 'background:#e2e8f0;color:#1f2937;border-color:#cbd5e1;' }}"
                            >
                                <span class="font-semibold">{{ $country['country'] }}</span>
                                <span class="inline-flex items-center">
                                    <img
                                        src="{{ $country['flagUrl'] }}"
                                        alt="{{ $country['country'] }} flag"
                                        class="w-5 h-3 rounded-sm"
                                        loading="lazy"
                                        onerror="this.style.display='none'"
                                    >
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
            
            <div class="mt-3 flex flex-wrap items-center gap-4">
                <div class="flex items-center gap-3">
                    <label for="b2b" class="block"><strong>B2B:</strong></label>
                    <select
                        id="b2b"
                        name="b2b"
                        class="border rounded px-2 py-1"
                        onchange="document.getElementById('filterForm').submit()"
                    >
                        <option value="">All</option>
                        <option value="1" {{ ($selectedB2b ?? '') === '1' ? 'selected' : '' }}>Business</option>
                        <option value="0" {{ ($selectedB2b ?? '') === '0' ? 'selected' : '' }}>Consumer</option>
                    </select>
                </div>

                <div class="flex items-center gap-3">
                    <label for="per_page" class="block"><strong>Per Page:</strong></label>
                    <select
                        id="per_page"
                        name="per_page"
                        class="border rounded px-2 py-1"
                        onchange="document.getElementById('filterForm').submit()"
                    >
                        @foreach([25, 50, 100, 200] as $size)
                            <option value="{{ $size }}" {{ (int)($perPage ?? 25) === $size ? 'selected' : '' }}>
                                {{ $size }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-3">
                    <label for="status" class="block"><strong>Order Status:</strong></label>
                    <select
                        id="status"
                        name="status"
                        class="border rounded px-2 py-1"
                        onchange="document.getElementById('filterForm').submit()"
                    >
                        <option value="">All</option>
                        @foreach(($statusOptions ?? []) as $status)
                            <option value="{{ $status }}" {{ ($selectedStatus ?? '') === $status ? 'selected' : '' }}>
                                {{ $status }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-3">
                    <label for="network" class="block"><strong>Network:</strong></label>
                    <select
                        id="network"
                        name="network"
                        class="border rounded px-2 py-1"
                        onchange="document.getElementById('filterForm').submit()"
                    >
                        <option value="">All</option>
                        @foreach(($networkOptions ?? []) as $network)
                            <option value="{{ $network }}" {{ ($selectedNetwork ?? '') === $network ? 'selected' : '' }}>
                                {{ $network }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-3">
                    <label for="method" class="block"><strong>Method:</strong></label>
                    <select
                        id="method"
                        name="method"
                        class="border rounded px-2 py-1"
                        onchange="document.getElementById('filterForm').submit()"
                    >
                        <option value="">All</option>
                        @foreach(($methodOptions ?? []) as $method)
                            <option value="{{ $method }}" {{ ($selectedMethod ?? '') === $method ? 'selected' : '' }}>
                                {{ $method }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-3">
                <a href="{{ route('orders.index') }}" style="background:#ccc; padding:6px 12px; border-radius:4px; text-decoration:none; color:#000; display:inline-block;">
                    Clear Filters
                </a>
            </div>
            @foreach(request()->only(['created_after','created_before','view']) as $key => $value)
                @if(!empty($value))
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
        </form>
    </div>

    <div class="bg-gray-50 dark:bg-gray-900 p-4 mb-4 rounded">
        <form method="GET" action="{{ route('orders.index') }}" id="dateRangeForm">
            @php
                $todayDate = now()->format('Y-m-d');
                $defaultAfterDate = now()->subDays(7)->format('Y-m-d');
                $allAfterDate = $oldestDate ? (new DateTime($oldestDate))->format('Y-m-d') : '';
                $allBeforeDate = $newestDate ? (new DateTime($newestDate))->format('Y-m-d') : $todayDate;
                $currentAfterDate = request('created_after', $defaultAfterDate);
                $currentBeforeDate = request('created_before', $todayDate);
                $activePresetStyle = 'background:#cfe4ff;color:#0b2a4a;border-color:#88b5ff;box-shadow:0 0 0 2px #b9d4ff;';
                $inactivePresetStyle = 'background:#e2e8f0;color:#1f2937;border-color:#cbd5e1;';
            @endphp
            <div class="flex flex-wrap items-center gap-3">
                <label for="created_after" class="block"><strong>From:</strong></label>
                <input
                    type="date"
                    id="created_after"
                    name="created_after"
                    value="{{ $currentAfterDate }}"
                    min="{{ $oldestDate ? (new DateTime($oldestDate))->format('Y-m-d') : '' }}"
                    max="{{ now()->format('Y-m-d') }}"
                    class="border rounded px-2 py-1"
                    onchange="document.getElementById('dateRangeForm').submit()"
                >

                <label for="created_before" class="block"><strong>To:</strong></label>
                <input
                    type="date"
                    id="created_before"
                    name="created_before"
                    value="{{ $currentBeforeDate }}"
                    min="{{ $oldestDate ? (new DateTime($oldestDate))->format('Y-m-d') : '' }}"
                    max="{{ now()->format('Y-m-d') }}"
                    class="border rounded px-2 py-1"
                    onchange="document.getElementById('dateRangeForm').submit()"
                >

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="px-3 py-2 rounded-md border text-sm"
                        style="{{ $currentAfterDate === $todayDate && $currentBeforeDate === $todayDate ? $activePresetStyle : $inactivePresetStyle }}"
                        onclick="
                            const form = document.getElementById('dateRangeForm');
                            const today = new Date();
                            const toLocalDate = (date) => {
                                const local = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
                                return local.toISOString().slice(0, 10);
                            };
                            form.created_before.value = toLocalDate(today);
                            form.created_after.value = toLocalDate(today);
                            form.submit();
                        "
                    >
                        Today
                    </button>
                    <button
                        type="button"
                        class="px-3 py-2 rounded-md border text-sm"
                        style="{{ $currentAfterDate === now()->subDay()->format('Y-m-d') && $currentBeforeDate === now()->subDay()->format('Y-m-d') ? $activePresetStyle : $inactivePresetStyle }}"
                        onclick="
                            const form = document.getElementById('dateRangeForm');
                            const today = new Date();
                            const yesterday = new Date(today);
                            yesterday.setDate(today.getDate() - 1);
                            const toLocalDate = (date) => {
                                const local = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
                                return local.toISOString().slice(0, 10);
                            };
                            form.created_before.value = toLocalDate(yesterday);
                            form.created_after.value = toLocalDate(yesterday);
                            form.submit();
                        "
                    >
                        Yesterday
                    </button>
                    @foreach([7, 14, 30, 60, 90] as $days)
                        <button
                        type="button"
                        class="px-3 py-2 rounded-md border text-sm"
                        style="{{ $currentBeforeDate === $todayDate && $currentAfterDate === now()->subDays($days)->format('Y-m-d') ? $activePresetStyle : $inactivePresetStyle }}"
                        onclick="
                            const form = document.getElementById('dateRangeForm');
                            const to = new Date();
                            const from = new Date(to);
                            from.setDate(to.getDate() - {{ $days }});
                            const toLocalDate = (date) => {
                                const local = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
                                return local.toISOString().slice(0, 10);
                            };
                            form.created_before.value = toLocalDate(to);
                            form.created_after.value = toLocalDate(from);
                            form.submit();
                            "
                        >
                            {{ $days }} days
                        </button>
                    @endforeach
                    <button
                        type="button"
                        class="px-3 py-2 rounded-md border text-sm"
                        style="{{ $currentAfterDate === $allAfterDate && $currentBeforeDate === $allBeforeDate ? $activePresetStyle : $inactivePresetStyle }}"
                        onclick="
                            const form = document.getElementById('dateRangeForm');
                            form.created_after.value = '{{ $allAfterDate }}';
                            form.created_before.value = '{{ $allBeforeDate }}';
                            form.submit();
                        "
                    >
                        All
                    </button>
                </div>

                @foreach(request()->except(['created_after','created_before','page']) as $key => $value)
                    @if(is_array($value))
                        @foreach($value as $v)
                            <input type="hidden" name="{{ $key }}[]" value="{{ $v }}">
                        @endforeach
                    @else
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
            </div>
        </form>
    </div>

    <div class="mb-3 flex items-center justify-between gap-3">
        <p>
            Last sync run:
            @if(!empty($lastOrderSyncRun))
                @php
                    $syncTime = $lastOrderSyncRun->finished_at ?: $lastOrderSyncRun->started_at;
                @endphp
                {{ \Illuminate\Support\Carbon::parse($syncTime)->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}
            @else
                Never
            @endif
        </p>
        <div class="flex items-center gap-2">
            @php $baseQuery = request()->except('view'); @endphp
            <a
                href="{{ route('orders.index', array_merge($baseQuery, ['view' => 'table'])) }}"
                class="px-3 py-2 rounded-md border text-sm {{ request('view', 'table') === 'map' ? 'border-gray-300 bg-white text-gray-700' : 'border-blue-300 bg-blue-100 text-blue-900' }}"
            >
                Table
            </a>
            <a
                href="{{ route('orders.index', array_merge($baseQuery, ['view' => 'map'])) }}"
                class="px-3 py-2 rounded-md border text-sm {{ request('view', 'table') === 'map' ? 'border-blue-300 bg-blue-100 text-blue-900' : 'border-gray-300 bg-white text-gray-700' }}"
            >
                Map
            </a>
            <form method="POST" action="{{ route('orders.syncNow') }}">
                @csrf
                <input type="hidden" name="days" value="7">
                <button
                    type="submit"
                    class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700"
                >
                    Sync Now
                </button>
            </form>
        </div>
    </div>
    @if(isset($ordersPaginator) && request('view', 'table') !== 'map')
        <div class="mb-3 text-sm text-gray-600">
            Showing {{ $ordersPaginator->firstItem() ?? 0 }} to {{ $ordersPaginator->lastItem() ?? 0 }} of {{ $ordersPaginator->total() }} results
        </div>
    @endif
    <div class="mb-3 text-sm text-gray-600">
        @if(session('sync_status'))
            {{ session('sync_status') }}
        @endif
    </div>
@if (request('view', 'table') === 'map')
        <div class="p-4 rounded-lg border border-gray-200 bg-white shadow-sm">
            <div id="orders-map" style="height: 600px; border-radius: 8px;"></div>
            <div class="mt-3 flex flex-wrap items-center gap-3 text-sm text-gray-600">
                <div id="map-meta"></div>
            </div>
        </div>

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
                const map = L.map('orders-map', { zoomControl: true }).setView([54.5, -3.0], 5);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);

                const clusterGroup = L.markerClusterGroup({ chunkedLoading: true });
                map.addLayer(clusterGroup);

                const params = new URLSearchParams(window.location.search);
                params.delete('view');
                const orderLinkBase = @json(route('orders.show', ['order_id' => '__ID__']));
                fetch("{{ route('orders.mapData') }}?" + params.toString())
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) {
                            document.getElementById('map-meta').textContent = data.error;
                            return;
                        }

                        const points = data.points || [];
                        let invalidCoords = 0;
                        points.forEach(p => {
                            const lat = parseFloat(p.lat);
                            const lng = parseFloat(p.lng);
                            if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
                                invalidCoords++;
                                return;
                            }
                            const marker = L.marker([lat, lng]);
                            const ordersHtml = (p.orderIds || [])
                                .map(id => `<div><a href="${orderLinkBase.replace('__ID__', id)}">${id}</a></div>`)
                                .join('');
                            const place = [p.city, p.region].filter(Boolean).join(', ');
                            const headline = [p.country, p.postal || p.city || ''].filter(Boolean).join(' ');
                            const coords = `${lat} , ${lng}`;
                            const popup = `
                                <div style="min-width:200px;">
                                    <div><strong>${headline}</strong></div>
                                    ${place ? `<div style="color:#4b5563;">${place}</div>` : ''}
                                    <div style="color:#6b7280;font-size:12px;">${coords}</div>
                                    <div>Orders: ${p.count}</div>
                                    <div style="margin-top:6px;">${ordersHtml || ''}</div>
                                </div>
                            `;
                            marker.bindPopup(popup);
                            clusterGroup.addLayer(marker);
                        });

                        if (points.length > 0) {
                            const bounds = L.latLngBounds(points.map(p => [parseFloat(p.lat), parseFloat(p.lng)]).filter(p => Number.isFinite(p[0]) && Number.isFinite(p[1])));
                            map.fitBounds(bounds, { padding: [20, 20] });
                        }

                        const missingOrders = (data.missingPostalOrderIds || []).filter(Boolean);
                        const missingText = missingOrders.length
                            ? `Missing postal on ${data.missingPostal || 0} orders (e.g. ${missingOrders.slice(0, 3).join(', ')})`
                            : `Missing postal on ${data.missingPostal || 0} orders`;

                        const cityPins = data.cityFallbackPins || 0;
                        const topPostalGroups = Array.isArray(data.topPostalGroups) ? data.topPostalGroups : [];
                        const topGroupsText = topPostalGroups.length
                            ? ` | Top groups: ${topPostalGroups.map(g => `${g.country} ${g.postal} (${g.count})`).join(', ')}`
                            : '';
                        const geocodeMode = data.liveGeocoding === false
                            ? 'Live geocode: off (map uses stored geocodes only)'
                            : 'Live geocode: on';
                        document.getElementById('map-meta').textContent =
                            `Orders: ${data.totalOrders || 0} | Postal groups: ${data.totalPostalGroups || 0} | Pins: ${points.length} | City pins: ${cityPins} | Invalid coords: ${invalidCoords} | ${missingText} | New geocodes: ${data.geocodedThisRequest || 0} | Geocode failed: ${data.geocodeFailed || 0}` +
                            (data.samplePostals && data.samplePostals.length ? ` | Samples: ${data.samplePostals.join(', ')}` : '') +
                            topGroupsText +
                            ` | ${geocodeMode}`;
                    })
                    .catch(() => {
                        document.getElementById('map-meta').textContent = 'Unable to load map data.';
                    });

            })();
        </script>
    @else
        @if (count($orders) > 0)
        <div class="mb-2 text-sm" style="display:flex; gap:16px; align-items:center;">
            <span><span style="color:#0f9d58; font-size:16px; line-height:1;">●</span> Geocode exists</span>
            <span><span style="color:#9aa3af; font-size:16px; line-height:1;">○</span> Geocode missing</span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full border-collapse" border="1" cellpadding="5" cellspacing="0">
            <thead>
            <tr class="bg-gray-100 dark:bg-gray-800">
                <th colspan="2">Purchase Date</th>
                <th>Order Status</th>
                <th>Order ID</th>
                <th>B2B</th>
                <th>MF</th>
                <th>Ship To</th>
                <th>Network</th>
                <th>Geo</th>
                <th>Unshipped</th>
                <th>Shipped</th>
                <th>Method</th>
                <th>Net (ex tax)</th>
                <th>Amazon Fees</th>
                <th>Landed Cost</th>
                <th>Margin Proxy</th>
                <th>Currency</th>
                <th>Ship to</th>
                <th>Marketplace</th>
                <th>Company Name</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($orders as $order)
                @php
                    $marketplaceId = $order['MarketplaceId'] ?? null;
                    $marketplaceName = $marketplaceId ? trim((string) (($marketplaces[$marketplaceId]['name'] ?? '') ?: '')) : '';
                    $marketplaceCountryCode = $marketplaceId
                        ? strtoupper(trim((string) (($marketplaces[$marketplaceId]['countryCode'] ?? $marketplaces[$marketplaceId]['country'] ?? ''))))
                        : '';
                    $marketplaceFlagUrl = strlen($marketplaceCountryCode) === 2
                        ? 'https://flagcdn.com/24x18/' . strtolower($marketplaceCountryCode) . '.png'
                        : null;
                    $purchaseLocal = $order['PurchaseDateLocal'] ?? null;
                    $purchaseUtc = $order['PurchaseDate'] ?? null;
                    $purchaseSource = $purchaseLocal ?: $purchaseUtc;
                    $purchaseDateOut = 'N/A';
                    $purchaseTimeOut = 'N/A';
                    if (!empty($purchaseSource)) {
                        try {
                            $dt = new DateTime($purchaseSource);
                            $purchaseDateOut = $dt->format('Y-m-d');
                            $purchaseTimeOut = $dt->format('H:i:s');
                        } catch (Exception $e) {
                            $purchaseDateOut = 'N/A';
                            $purchaseTimeOut = 'N/A';
                        }
                    }

                    $netAmount = isset($order['OrderNetExTax']['Amount']) && is_numeric($order['OrderNetExTax']['Amount'])
                        ? (float) $order['OrderNetExTax']['Amount']
                        : null;
                    $netCurrency = strtoupper(trim((string) ($order['OrderNetExTax']['CurrencyCode'] ?? '')));
                    $feeAmount = isset($order['AmazonFees']['Amount']) && is_numeric($order['AmazonFees']['Amount'])
                        ? abs((float) $order['AmazonFees']['Amount'])
                        : null;
                    $feeCurrency = strtoupper(trim((string) ($order['AmazonFees']['CurrencyCode'] ?? '')));
                    $landedAmount = isset($order['LandedCost']['Amount']) && is_numeric($order['LandedCost']['Amount'])
                        ? (float) $order['LandedCost']['Amount']
                        : null;
                    $landedCurrency = strtoupper(trim((string) ($order['LandedCost']['CurrencyCode'] ?? '')));
                    $marginAmount = isset($order['MarginProxy']['Amount']) && is_numeric($order['MarginProxy']['Amount'])
                        ? (float) $order['MarginProxy']['Amount']
                        : null;
                    $marginCurrency = strtoupper(trim((string) ($order['MarginProxy']['CurrencyCode'] ?? $netCurrency)));
                    if (
                        $netAmount !== null &&
                        $feeAmount !== null &&
                        $landedAmount !== null &&
                        $netCurrency !== '' &&
                        $netCurrency === $feeCurrency &&
                        $netCurrency === $landedCurrency
                    ) {
                        $marginAmount = round($netAmount - $feeAmount - $landedAmount, 2);
                        $marginCurrency = $netCurrency;
                    }
                @endphp
                <tr>
                    <td>{{ $purchaseDateOut }}</td>
                    <td>{{ $purchaseTimeOut }}</td>
                    <td>{{ $order['OrderStatus'] }}</td>
                    <td>
                        <a href="{{ route('orders.show', ['order_id' => $order['AmazonOrderId']]) }}" class="text-blue-600 hover:underline">
                            {{ $order['AmazonOrderId'] }}
                        </a>
                    </td>
                    <td style="text-align:center;">
                        @if(!empty($order['IsBusinessOrder']))
                            <span style="display:inline-block; padding:2px 6px; border-radius:10px; background:#0b2a4a; color:#fff; font-size:12px; font-weight:600;">B2B</span>
                        @else
                            <span style="color:#9aa3af;">–</span>
                        @endif
                    </td>
                    <td style="text-align:center;">
                        @if(isset($order['IsMarketplaceFacilitator']) && $order['IsMarketplaceFacilitator'] === true)
                            <span style="display:inline-block; padding:2px 6px; border-radius:10px; background:#1f6b3b; color:#fff; font-size:12px; font-weight:600;">MF</span>
                        @elseif(isset($order['IsMarketplaceFacilitator']) && $order['IsMarketplaceFacilitator'] === false)
                            <span style="color:#9aa3af;">No</span>
                        @else
                            <span style="color:#9aa3af;">N/A</span>
                        @endif
                    </td>
                    <td>{{ $order['ShippingAddress']['City'] ?? 'N/A' }}</td>
                    <td>{{ $order['FulfillmentChannel'] ?? 'N/A' }}</td>
                    <td style="text-align:center;">
                        @php
                            $geo = $order['Geocode'] ?? ['exists' => false, 'lat' => null, 'lng' => null];
                        @endphp
                        @if(!empty($geo['exists']))
                            <span
                                title="Geocode found via {{ $geo['source'] ?? 'lookup' }} ({{ $geo['lat'] }}, {{ $geo['lng'] }})"
                                style="display:inline-block; color:#0f9d58; font-size:16px; line-height:1;"
                            >●</span>
                        @else
                            <span
                                title="No geocode found for this postal code"
                                style="display:inline-block; color:#9aa3af; font-size:16px; line-height:1;"
                            >○</span>
                        @endif
                    </td>
                    <td style="text-align: center;">{{ $order['NumberOfItemsUnshipped'] ?? '' }}</td>
                    <td style="text-align: center;">{{ $order['NumberOfItemsShipped'] ?? '' }}</td>
                    <td>{{ $order['PaymentMethodDetails'][0] ?? 'N/A' }}</td>
                    <td dir="rtl">
                        @if(str_starts_with((string) ($order['OrderNetExTax']['Source'] ?? ''), 'estimated'))
                            *
                        @endif
                        {{ $order['OrderNetExTax']['Amount'] ?? 'N/A' }}
                    </td>
                    <td dir="rtl">
                        @if(($order['AmazonFees']['Source'] ?? '') === 'estimated_product_fees')
                            *
                        @endif
                        {{ $feeAmount !== null ? number_format($feeAmount, 2) : 'N/A' }}
                        {{ $feeCurrency }}
                    </td>
                    <td dir="rtl">
                        {{ $landedAmount !== null ? number_format($landedAmount, 2) : 'N/A' }}
                        {{ $landedCurrency }}
                    </td>
                    <td dir="rtl">
                        {{ $marginAmount !== null ? number_format($marginAmount, 2) : 'N/A' }}
                        {{ $marginCurrency }}
                    </td>
                    <td>{{ $order['OrderNetExTax']['CurrencyCode'] ?? '' }}</td>
                    <td>{{ $order['ShippingAddress']['CountryCode'] ?? '' }}</td>
                    <td>
                        @if($marketplaceId)
                            <div class="inline-flex items-center gap-2">
                                @if($marketplaceFlagUrl)
                                    <img src="{{ $marketplaceFlagUrl }}" alt="{{ $marketplaceCountryCode }} flag" class="w-5 h-3 rounded-sm" loading="lazy" onerror="this.style.display='none'">
                                @endif
                                <span>{{ $marketplaceName !== '' ? $marketplaceName : $marketplaceId }}</span>
                            </div>
                        @else
                            N/A
                        @endif
                    </td>
                    <td>{{ $order['ShippingAddress']['CompanyName'] ?? 'N/A' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>

        @if(isset($ordersPaginator))
            <div class="mt-4">
                {{ $ordersPaginator->links() }}
            </div>
            @if($ordersPaginator->onLastPage())
                <div class="mt-3">
                    <form method="POST" action="{{ route('orders.syncOlder') }}" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Loading...';">
                        @csrf
                        @foreach(request()->except('page') as $key => $value)
                            @if(is_array($value))
                                @foreach($value as $v)
                                    <input type="hidden" name="{{ $key }}[]" value="{{ $v }}">
                                @endforeach
                            @else
                                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                            @endif
                        @endforeach
                        <input type="hidden" name="return_page" value="{{ $ordersPaginator->currentPage() + 1 }}">
                        <input type="hidden" name="per_page" value="{{ $perPage ?? 25 }}">
                        <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">
                            Next
                        </button>
                    </form>
                </div>
            @endif
        @endif
        @else
            @if(!empty($dbEmpty))
                <p>No cached orders yet. Run the sync and try again.</p>
            @else
                <p>No orders found for the selected filters. Try clearing filters.</p>
            @endif
        @endif
@endif

</div>

</x-app-layout>
