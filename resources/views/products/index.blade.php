<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Products</h2>
            <a href="{{ route('dashboard') }}" class="inline-flex items-center px-3 py-2 rounded-md border border-gray-300 bg-white text-gray-800 text-sm">Back to Dashboard</a>
        </div>
    </x-slot>

    <div class="py-6 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">
        @if(session('status'))
            <div class="mb-4 p-3 rounded border border-green-200 bg-green-50 text-green-800 text-sm">{{ session('status') }}</div>
        @endif

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm mb-4">
            <form method="GET" action="{{ route('products.index') }}" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
                <div>
                    <label for="q" class="block text-sm font-medium mb-1">Search</label>
                    <input id="q" name="q" value="{{ $q }}" class="border rounded px-2 py-1" placeholder="Name, ID, SKU, ASIN">
                </div>
                <div>
                    <label for="status" class="block text-sm font-medium mb-1">Status</label>
                    <select id="status" name="status" class="w-full border rounded px-2 py-1">
                        <option value="">All</option>
                        <option value="active" {{ ($status ?? '') === 'active' ? 'selected' : '' }}>active</option>
                        <option value="inactive" {{ ($status ?? '') === 'inactive' ? 'selected' : '' }}>inactive</option>
                        <option value="draft" {{ ($status ?? '') === 'draft' ? 'selected' : '' }}>draft</option>
                    </select>
                </div>
                <div>
                    <label for="primary_type" class="block text-sm font-medium mb-1">Primary Type</label>
                    <select id="primary_type" name="primary_type" class="w-full border rounded px-2 py-1">
                        <option value="">All</option>
                        <option value="asin" {{ ($primaryType ?? '') === 'asin' ? 'selected' : '' }}>ASIN</option>
                        <option value="seller_sku" {{ ($primaryType ?? '') === 'seller_sku' ? 'selected' : '' }}>SKU</option>
                        <option value="fnsku" {{ ($primaryType ?? '') === 'fnsku' ? 'selected' : '' }}>FNSKU</option>
                        <option value="upc" {{ ($primaryType ?? '') === 'upc' ? 'selected' : '' }}>UPC</option>
                        <option value="ean" {{ ($primaryType ?? '') === 'ean' ? 'selected' : '' }}>EAN</option>
                        <option value="other" {{ ($primaryType ?? '') === 'other' ? 'selected' : '' }}>Other</option>
                    </select>
                </div>
                <div>
                    <label for="marketplace_id" class="block text-sm font-medium mb-1">Marketplace</label>
                    <select id="marketplace_id" name="marketplace_id" class="w-full border rounded px-2 py-1">
                        <option value="">All</option>
                        @foreach(($marketplaceOptions ?? []) as $option)
                            <option value="{{ $option->id }}" {{ ($marketplaceId ?? '') === (string) $option->id ? 'selected' : '' }}>
                                {{ $option->name ?: $option->id }} ({{ strtoupper((string) ($option->country_code ?? 'N/A')) }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="sort_by" class="block text-sm font-medium mb-1">Sort By</label>
                    <select id="sort_by" name="sort_by" class="w-full border rounded px-2 py-1">
                        <option value="updated_at" {{ ($sortBy ?? '') === 'updated_at' ? 'selected' : '' }}>Updated</option>
                        <option value="id" {{ ($sortBy ?? '') === 'id' ? 'selected' : '' }}>ID</option>
                        <option value="name" {{ ($sortBy ?? '') === 'name' ? 'selected' : '' }}>Name</option>
                        <option value="primary_identifier" {{ ($sortBy ?? '') === 'primary_identifier' ? 'selected' : '' }}>Primary Identifier</option>
                        <option value="marketplace" {{ ($sortBy ?? '') === 'marketplace' ? 'selected' : '' }}>Marketplace</option>
                        <option value="status" {{ ($sortBy ?? '') === 'status' ? 'selected' : '' }}>Status</option>
                        <option value="identifiers_count" {{ ($sortBy ?? '') === 'identifiers_count' ? 'selected' : '' }}>Identifier Count</option>
                        <option value="cost_layers_count" {{ ($sortBy ?? '') === 'cost_layers_count' ? 'selected' : '' }}>Cost Layer Count</option>
                    </select>
                </div>
                <div>
                    <label for="sort_dir" class="block text-sm font-medium mb-1">Sort Direction</label>
                    <select id="sort_dir" name="sort_dir" class="w-full border rounded px-2 py-1">
                        <option value="desc" {{ ($sortDir ?? '') === 'desc' ? 'selected' : '' }}>Descending</option>
                        <option value="asc" {{ ($sortDir ?? '') === 'asc' ? 'selected' : '' }}>Ascending</option>
                    </select>
                </div>
                <div>
                    <label for="per_page" class="block text-sm font-medium mb-1">Per Page</label>
                    <select id="per_page" name="per_page" class="w-full border rounded px-2 py-1">
                        <option value="25" {{ (int) ($perPage ?? 50) === 25 ? 'selected' : '' }}>25</option>
                        <option value="50" {{ (int) ($perPage ?? 50) === 50 ? 'selected' : '' }}>50</option>
                        <option value="100" {{ (int) ($perPage ?? 50) === 100 ? 'selected' : '' }}>100</option>
                        <option value="200" {{ (int) ($perPage ?? 50) === 200 ? 'selected' : '' }}>200</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Apply</button>
                    <a href="{{ route('products.index') }}" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Clear</a>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm overflow-x-auto">
            @php
                $sortableColumns = [
                    'id' => 'ID',
                    'name' => 'Name',
                    'primary_identifier' => 'Primary Identifier',
                    'marketplace' => 'Marketplace',
                    'status' => 'Status',
                    'identifiers_count' => 'Identifiers',
                    'cost_layers_count' => 'Cost Layers',
                    'updated_at' => 'Updated',
                ];

                $buildSortUrl = function (string $column, ?string $direction = null) use ($sortBy, $sortDir) {
                    $nextDirection = $direction;
                    if ($nextDirection === null) {
                        $nextDirection = ($sortBy ?? '') === $column && ($sortDir ?? 'desc') === 'asc' ? 'desc' : 'asc';
                    }

                    return route('products.index', array_merge(request()->query(), [
                        'sort_by' => $column,
                        'sort_dir' => $nextDirection,
                    ]));
                };
            @endphp
            <table class="w-full text-sm" border="1" cellpadding="6" cellspacing="0">
                <thead class="bg-gray-100 dark:bg-gray-700">
                    <tr>
                        @foreach($sortableColumns as $column => $label)
                            @php
                                $isActiveSort = ($sortBy ?? '') === $column;
                                $sortIndicator = $isActiveSort ? (($sortDir ?? 'desc') === 'asc' ? '↑' : '↓') : '';
                                $alignClass = in_array($column, ['identifiers_count', 'cost_layers_count'], true) ? 'text-right' : 'text-left';
                            @endphp
                            <th class="{{ $alignClass }}">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ $buildSortUrl($column) }}" class="hover:underline">
                                        {{ $label }}{{ $sortIndicator !== '' ? ' ' . $sortIndicator : '' }}
                                    </a>
                                    <details class="relative">
                                        <summary class="list-none cursor-pointer px-1 rounded border border-gray-300 bg-white text-xs text-gray-700">v</summary>
                                        <div class="absolute z-10 mt-1 min-w-[130px] rounded border border-gray-300 bg-white shadow p-1 text-xs">
                                            <a href="{{ $buildSortUrl($column, 'asc') }}" class="block px-2 py-1 hover:bg-gray-100 rounded">Sort Asc</a>
                                            <a href="{{ $buildSortUrl($column, 'desc') }}" class="block px-2 py-1 hover:bg-gray-100 rounded">Sort Desc</a>
                                        </div>
                                    </details>
                                </div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                        @php
                            $primaryType = trim((string) ($product->primary_identifier_type ?? ''));
                            $primaryValue = trim((string) ($product->primary_identifier_value ?? ''));
                            $primaryLabel = $primaryType !== '' || $primaryValue !== ''
                                ? trim($primaryType . ': ' . $primaryValue, ': ')
                                : '-';
                            $marketplaceName = trim((string) ($product->primary_marketplace_name ?? ''));
                            $marketplaceCountry = strtoupper(trim((string) ($product->primary_marketplace_country_code ?? '')));
                            $flagUrl = strlen($marketplaceCountry) === 2
                                ? 'https://flagcdn.com/24x18/' . strtolower($marketplaceCountry) . '.png'
                                : null;
                        @endphp
                        <tr>
                            <td><a class="text-blue-600 hover:underline" href="{{ route('products.show', $product) }}">{{ $product->id }}</a></td>
                            <td>{{ $product->name }}</td>
                            <td>{{ $primaryLabel }}</td>
                            <td>
                                @if($marketplaceName !== '' || !empty($product->primary_marketplace_id))
                                    <div class="inline-flex items-center gap-2">
                                        @if($flagUrl)
                                            <img src="{{ $flagUrl }}" alt="{{ $marketplaceCountry }} flag" class="w-5 h-3 rounded-sm" loading="lazy" onerror="this.style.display='none'">
                                        @endif
                                        <span>{{ $marketplaceName !== '' ? $marketplaceName : ($product->primary_marketplace_id ?? '-') }}</span>
                                    </div>
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $product->status }}</td>
                            <td class="text-right">{{ (int) $product->identifiers_count }}</td>
                            <td class="text-right">{{ (int) $product->cost_layers_count }}</td>
                            <td>{{ optional($product->updated_at)->format('Y-m-d H:i:s') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No products found.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-4">{{ $products->links() }}</div>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm mt-4">
            <form class="flex flex-wrap items-end gap-3" onsubmit="return false;">
                <div class="min-w-[260px]">
                    <label for="product_picker" class="block text-sm font-medium mb-1">Go To Product</label>
                    <select
                        id="product_picker"
                        class="w-full border rounded px-2 py-1"
                        onchange="if(this.value){ window.location.href='{{ url('/products') }}/'+this.value; }"
                    >
                        <option value="">Choose a product...</option>
                        @foreach(($productOptions ?? []) as $option)
                            <option value="{{ $option->id }}" {{ (int) ($selectedProductId ?? 0) === (int) $option->id ? 'selected' : '' }}>
                                {{ $option->id }} — {{ $option->name ?: 'Untitled Product' }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
