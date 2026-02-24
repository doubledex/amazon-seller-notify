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
                <h3 class="font-semibold mb-2">Quantity by FC (Granular)</h3>
                <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                    <thead>
                    <tr>
                        <th class="text-left">FC ID</th>
                        <th class="text-left">City</th>
                        <th class="text-left">State</th>
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
                            <td class="text-right">{{ number_format((int) $row->row_count) }}</td>
                            <td class="text-right">{{ number_format((int) $row->qty) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No data.</td></tr>
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
                        <th class="text-right">Quantity</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($summary as $row)
                        <tr>
                            <td>{{ $row->state }}</td>
                            <td class="text-right">{{ number_format((int) $row->qty) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2">No data.</td></tr>
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
</x-app-layout>
