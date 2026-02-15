<x-app-layout>
<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        {{ __('ASINs by European Marketplace') }}
    </h2>
</x-slot>

<div class="py-4 px-4">
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <form method="GET" action="{{ route('asins.europe') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="status" class="block text-sm font-medium mb-1">Status</label>
                <select id="status" name="status" class="border rounded px-2 py-1">
                    <option value="all" {{ ($statusFilter ?? 'all') === 'all' ? 'selected' : '' }}>All</option>
                    <option value="active" {{ ($statusFilter ?? 'all') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ ($statusFilter ?? 'all') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    <option value="unknown" {{ ($statusFilter ?? 'all') === 'unknown' ? 'selected' : '' }}>Unknown</option>
                </select>
            </div>
            <div>
                <label for="q" class="block text-sm font-medium mb-1">ASIN / SKU Search</label>
                <input id="q" name="q" type="text" value="{{ $search ?? '' }}" class="border rounded px-2 py-1" placeholder="e.g. B08... or SKU">
            </div>
            <button type="submit" class="inline-block px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">
                Apply
            </button>
        </form>
        <a
            href="{{ route('asins.europe', array_merge(request()->query(), ['export' => 'csv'])) }}"
            class="inline-block px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700"
        >
            Export CSV
        </a>
    </div>

    <div class="bg-gray-50 dark:bg-gray-900 p-4 mb-4 rounded">
        <p class="text-sm text-gray-700 dark:text-gray-300">
            Marketplaces included: {{ implode(', ', $europeanCountryCodes) }}
        </p>
        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
            Listing source: SP-API report <code>GET_MERCHANT_LISTINGS_ALL_DATA</code> (run <code>php artisan listings:sync-europe</code> to refresh).
        </p>
    </div>

    @if($marketplaceAsins->isEmpty())
        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm">
            <p>No ASIN listings matched your current filters.</p>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                Try changing status/search, or run sync jobs to refresh data.
            </p>
        </div>
    @else
        @foreach($marketplaceAsins as $marketplace)
            <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm mb-4">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                    <h3 class="font-bold text-lg">
                        {{ $marketplace['country_code'] }} - {{ $marketplace['name'] }}
                    </h3>
                    <div class="text-sm text-gray-600 dark:text-gray-300">
                        <span class="mr-3">Marketplace ID: <code>{{ $marketplace['id'] }}</code></span>
                        <span class="mr-3">Listings: <strong>{{ $marketplace['listing_count'] }}</strong></span>
                        <span class="mr-3">Unique ASINs: <strong>{{ $marketplace['unique_asin_count'] }}</strong></span>
                        <span class="mr-3">Parents: <strong>{{ $marketplace['parent_count'] }}</strong></span>
                        <span class="mr-3">Children: <strong>{{ $marketplace['child_count'] }}</strong></span>
                        <span class="mr-3">Active: <strong>{{ $marketplace['active_count'] }}</strong></span>
                        <span class="mr-3">Inactive: <strong>{{ $marketplace['inactive_count'] }}</strong></span>
                        <span class="mr-3">Unknown: <strong>{{ $marketplace['unknown_count'] }}</strong></span>
                    </div>
                </div>

                @if($marketplace['asins']->isEmpty())
                    <p class="text-sm text-gray-600 dark:text-gray-400">No ASINs found for this marketplace yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table border="1" cellpadding="8" cellspacing="0" class="w-full">
                            <thead class="bg-gray-100 dark:bg-gray-700">
                                <tr>
                                    <th>ASIN</th>
                                    <th>SKU</th>
                                    <th>Type</th>
                                    <th>Title</th>
                                    <th>Listing Status</th>
                                    <th>Raw Status</th>
                                    <th>Quantity</th>
                                    <th>Status Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($marketplace['asins'] as $asinRow)
                                    <tr>
                                        <td><code>{{ $asinRow['asin'] !== '' ? $asinRow['asin'] : 'N/A' }}</code></td>
                                        <td><code>{{ $asinRow['seller_sku'] }}</code></td>
                                        <td>{{ $asinRow['is_parent'] ? 'Parent' : 'Child' }}</td>
                                        <td>{{ $asinRow['item_name'] !== '' ? $asinRow['item_name'] : 'N/A' }}</td>
                                        <td>{{ $asinRow['listing_status'] }}</td>
                                        <td>{{ $asinRow['raw_status'] !== '' ? $asinRow['raw_status'] : 'N/A' }}</td>
                                        <td>{{ $asinRow['quantity'] ?? 'N/A' }}</td>
                                        <td>{{ $asinRow['status_updated_at'] ? \Carbon\Carbon::parse($asinRow['status_updated_at'])->format('Y-m-d H:i:s') : 'N/A' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach
    @endif
</div>
</x-app-layout>
