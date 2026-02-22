<x-app-layout>
<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        {{ __('Daily Sales & Ad Spend (GBP Totals + Marketplace Breakdown)') }}
    </h2>
</x-slot>

<div class="py-4 px-4">
    <div class="bg-gray-50 dark:bg-gray-900 p-4 mb-4 rounded">
        <form method="GET" action="{{ route('metrics.index') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="from" class="block text-sm font-medium mb-1">From</label>
                <input type="date" id="from" name="from" value="{{ $from }}" class="border rounded px-2 py-1">
            </div>
            <div>
                <label for="to" class="block text-sm font-medium mb-1">To</label>
                <input type="date" id="to" name="to" value="{{ $to }}" class="border rounded px-2 py-1">
            </div>
            <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Apply</button>
            <a href="{{ route('metrics.index') }}" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Clear</a>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm overflow-x-auto">
        <p class="text-xs text-gray-600 mb-2">* Estimated sales value (temporary ASIN-based pricing). Values without * are actual order/line values from Amazon.</p>
        <table border="1" cellpadding="6" cellspacing="0" class="w-full text-sm">
            <thead class="bg-gray-100 dark:bg-gray-700">
                <tr>
                    <th class="text-left w-10"></th>
                    <th class="text-left">Date</th>
                    <th class="text-left">Day</th>
                    <th class="text-right">Orders</th>
                    <th class="text-right">Units</th>
                    <th class="text-right">Sales ex tax (GBP)</th>
                    <th class="text-right">Ad Spend (GBP)</th>
                    <th class="text-right">ACOS %</th>
                </tr>
            </thead>
            <tbody>
                @forelse(($weeklyRows ?? []) as $week)
                    @php
                        $weekId = 'week-row-' . str_replace('-', '', (string) $week['week_start']);
                        $weekGroupClass = 'week-group-' . str_replace('-', '', (string) $week['week_start']);
                    @endphp
                    <tr class="bg-gray-50 dark:bg-gray-700 font-semibold">
                        <td class="text-left w-10">
                            <button
                                type="button"
                                aria-expanded="false"
                                class="px-2 py-1 border rounded text-xs bg-white text-gray-700"
                                onclick="const rows=document.querySelectorAll('.{{ $weekGroupClass }}'); const anyVisible=Array.from(rows).some(r=>!r.classList.contains('hidden')); rows.forEach(r=>r.classList.toggle('hidden', anyVisible)); this.setAttribute('aria-expanded', (!anyVisible).toString()); this.querySelector('span').textContent = anyVisible ? '▸' : '▾';"
                            >
                                <span>▸</span>
                            </button>
                        </td>
                        <td>{{ $week['week_start'] }} to {{ $week['week_end'] }}</td>
                        <td>Week Summary</td>
                        <td class="text-right">{{ number_format((int) ($week['order_count'] ?? 0)) }}</td>
                        <td class="text-right">{{ number_format((int) ($week['units'] ?? 0)) }}</td>
                        <td class="text-right">{{ !empty($week['estimated_sales_data']) ? '*' : '' }}£{{ number_format((float) $week['sales_gbp'], 2) }}</td>
                        <td class="text-right">£{{ number_format((float) $week['ad_gbp'], 2) }}</td>
                        <td class="text-right">{{ $week['acos_percent'] !== null ? number_format((float) $week['acos_percent'], 2) . '%' : 'N/A' }}</td>
                    </tr>
                    @foreach(($week['days'] ?? []) as $day)
                    @php $rowId = 'day-row-' . str_replace('-', '', $day['date']); @endphp
                    <tr class="hidden {{ $weekGroupClass }}">
                        <td class="text-left w-10">
                            <button
                                type="button"
                                aria-expanded="false"
                                aria-controls="{{ $rowId }}"
                                class="px-2 py-1 border rounded text-xs bg-white text-gray-700"
                                onclick="const row=document.getElementById('{{ $rowId }}'); const expanded=!row.classList.contains('hidden'); row.classList.toggle('hidden'); this.setAttribute('aria-expanded', (!expanded).toString()); this.querySelector('span').textContent = expanded ? '▸' : '▾';"
                            >
                                <span>▸</span>
                            </button>
                        </td>
                        <td>{{ $day['date'] }}</td>
                        <td>{{ \Carbon\Carbon::parse($day['date'])->format('l') }}</td>
                        <td class="text-right">{{ number_format((int) ($day['order_count'] ?? 0)) }}</td>
                        <td class="text-right">{{ number_format((int) ($day['units'] ?? 0)) }}</td>
                        <td class="text-right">{{ !empty($day['estimated_sales_data']) ? '*' : '' }}£{{ number_format((float) $day['sales_gbp'], 2) }}</td>
                        <td class="text-right">£{{ number_format((float) $day['ad_gbp'], 2) }}</td>
                        <td class="text-right">{{ $day['acos_percent'] !== null ? number_format((float) $day['acos_percent'], 2) . '%' : 'N/A' }}</td>
                    </tr>
                    <tr id="{{ $rowId }}" class="hidden">
                        <td colspan="8" class="p-2">
                            <table border="1" cellpadding="6" cellspacing="0" class="w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-600">
                                    <tr>
                                        <th class="text-left">Marketplace</th>
                                        <th class="text-right">Orders</th>
                                        <th class="text-right">Units</th>
                                        <th class="text-right">Sales ex tax (Local)</th>
                                        <th class="text-right">Sales ex tax (GBP)</th>
                                        <th class="text-right">Ad Spend (Local)</th>
                                        <th class="text-right">Ad Spend (GBP)</th>
                                        <th class="text-right">ACOS %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $allItems = collect($day['items']);
                                        $gbItems = $allItems->filter(fn ($i) => in_array(strtoupper((string) ($i['country'] ?? '')), ['GB', 'UK'], true))->values();
                                        $naItems = $allItems->filter(fn ($i) => in_array(strtoupper((string) ($i['country'] ?? '')), ['US', 'CA', 'MX', 'BR'], true))->values();
                                        $euItems = $allItems->filter(fn ($i) => in_array(strtoupper((string) ($i['country'] ?? '')), ['AT','BE','CH','DE','DK','ES','FI','FR','IE','IT','LU','NL','NO','PL','SE'], true))->values();
                                        $otherItems = $allItems->filter(fn ($i) => !in_array(strtoupper((string) ($i['country'] ?? '')), ['GB', 'UK', 'US', 'CA', 'MX', 'BR', 'AT','BE','CH','DE','DK','ES','FI','FR','IE','IT','LU','NL','NO','PL','SE'], true))->values();

                                        $euSalesLocal = (float) $euItems->sum(fn ($i) => (float) ($i['sales_local'] ?? 0));
                                        $euAdLocal = (float) $euItems->sum(fn ($i) => (float) ($i['ad_local'] ?? 0));
                                        $euOrders = (int) $euItems->sum(fn ($i) => (int) ($i['order_count'] ?? 0));
                                        $euUnits = (int) $euItems->sum(fn ($i) => (int) ($i['units'] ?? 0));
                                        $euSalesGbp = (float) $euItems->sum(fn ($i) => (float) ($i['sales_gbp'] ?? 0));
                                        $euAdGbp = (float) $euItems->sum(fn ($i) => (float) ($i['ad_gbp'] ?? 0));
                                        $euAcos = $euSalesGbp > 0 ? ($euAdGbp / $euSalesGbp) * 100 : null;
                                    @endphp

                                    @foreach($euItems as $item)
                                        <tr>
                                            <td class="text-left">{{ $item['country'] }}</td>
                                            <td class="text-right">{{ number_format((int) ($item['order_count'] ?? 0)) }}</td>
                                            <td class="text-right">{{ number_format((int) ($item['units'] ?? 0)) }}</td>
                                            <td class="text-right">{{ !empty($item['estimated_sales_data']) ? '*' : '' }}{{ $item['currency_symbol'] }}{{ number_format((float) $item['sales_local'], 2) }}</td>
                                            <td class="text-right">{{ !empty($item['estimated_sales_data']) ? '*' : '' }}£{{ number_format((float) $item['sales_gbp'], 2) }}</td>
                                            <td class="text-right">{{ $item['currency_symbol'] }}{{ number_format((float) $item['ad_local'], 2) }}</td>
                                            <td class="text-right">£{{ number_format((float) $item['ad_gbp'], 2) }}</td>
                                            <td class="text-right">{{ $item['acos_percent'] !== null ? number_format((float) $item['acos_percent'], 2) . '%' : 'N/A' }}</td>
                                        </tr>
                                    @endforeach
                                    @if($euItems->isNotEmpty())
                                        <tr class="bg-gray-100 dark:bg-gray-700 font-semibold">
                                            <td class="text-left">EU Subtotal</td>
                                            <td class="text-right">{{ number_format($euOrders) }}</td>
                                            <td class="text-right">{{ number_format($euUnits) }}</td>
                                            <td class="text-right">€{{ number_format($euSalesLocal, 2) }}</td>
                                            <td class="text-right">£{{ number_format($euSalesGbp, 2) }}</td>
                                            <td class="text-right">€{{ number_format($euAdLocal, 2) }}</td>
                                            <td class="text-right">£{{ number_format($euAdGbp, 2) }}</td>
                                            <td class="text-right">{{ $euAcos !== null ? number_format($euAcos, 2) . '%' : 'N/A' }}</td>
                                        </tr>
                                    @endif
                                    @foreach($naItems as $item)
                                        <tr>
                                            <td class="text-left">{{ $item['country'] }}</td>
                                            <td class="text-right">{{ number_format((int) ($item['order_count'] ?? 0)) }}</td>
                                            <td class="text-right">{{ number_format((int) ($item['units'] ?? 0)) }}</td>
                                            <td class="text-right">{{ !empty($item['estimated_sales_data']) ? '*' : '' }}{{ $item['currency_symbol'] }}{{ number_format((float) $item['sales_local'], 2) }}</td>
                                            <td class="text-right">{{ !empty($item['estimated_sales_data']) ? '*' : '' }}£{{ number_format((float) $item['sales_gbp'], 2) }}</td>
                                            <td class="text-right">{{ $item['currency_symbol'] }}{{ number_format((float) $item['ad_local'], 2) }}</td>
                                            <td class="text-right">£{{ number_format((float) $item['ad_gbp'], 2) }}</td>
                                            <td class="text-right">{{ $item['acos_percent'] !== null ? number_format((float) $item['acos_percent'], 2) . '%' : 'N/A' }}</td>
                                        </tr>
                                    @endforeach
                                    @foreach($gbItems as $item)
                                        <tr>
                                            <td class="text-left">{{ $item['country'] }}</td>
                                            <td class="text-right">{{ number_format((int) ($item['order_count'] ?? 0)) }}</td>
                                            <td class="text-right">{{ number_format((int) ($item['units'] ?? 0)) }}</td>
                                            <td class="text-right">{{ !empty($item['estimated_sales_data']) ? '*' : '' }}{{ $item['currency_symbol'] }}{{ number_format((float) $item['sales_local'], 2) }}</td>
                                            <td class="text-right">{{ !empty($item['estimated_sales_data']) ? '*' : '' }}£{{ number_format((float) $item['sales_gbp'], 2) }}</td>
                                            <td class="text-right">{{ $item['currency_symbol'] }}{{ number_format((float) $item['ad_local'], 2) }}</td>
                                            <td class="text-right">£{{ number_format((float) $item['ad_gbp'], 2) }}</td>
                                            <td class="text-right">{{ $item['acos_percent'] !== null ? number_format((float) $item['acos_percent'], 2) . '%' : 'N/A' }}</td>
                                        </tr>
                                    @endforeach
                                    @foreach($otherItems as $item)
                                        <tr>
                                            <td class="text-left">{{ $item['country'] }}</td>
                                            <td class="text-right">{{ number_format((int) ($item['order_count'] ?? 0)) }}</td>
                                            <td class="text-right">{{ number_format((int) ($item['units'] ?? 0)) }}</td>
                                            <td class="text-right">{{ !empty($item['estimated_sales_data']) ? '*' : '' }}{{ $item['currency_symbol'] }}{{ number_format((float) $item['sales_local'], 2) }}</td>
                                            <td class="text-right">{{ !empty($item['estimated_sales_data']) ? '*' : '' }}£{{ number_format((float) $item['sales_gbp'], 2) }}</td>
                                            <td class="text-right">{{ $item['currency_symbol'] }}{{ number_format((float) $item['ad_local'], 2) }}</td>
                                            <td class="text-right">£{{ number_format((float) $item['ad_gbp'], 2) }}</td>
                                            <td class="text-right">{{ $item['acos_percent'] !== null ? number_format((float) $item['acos_percent'], 2) . '%' : 'N/A' }}</td>
                                        </tr>
                                    @endforeach
                                    @if($euItems->isEmpty() && $naItems->isEmpty() && $gbItems->isEmpty() && $otherItems->isEmpty())
                                        <tr>
                                            <td colspan="8">No marketplace data for this day.</td>
                                        </tr>
                                    @endif
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    @endforeach
                @empty
                    <tr>
                        <td colspan="8">No metrics found for this date range.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</x-app-layout>
