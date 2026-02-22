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
            <form class="flex flex-wrap items-end gap-3" onsubmit="return false;">
                <div class="min-w-[260px]">
                    <label for="product_picker" class="block text-sm font-medium mb-1">Select Existing Product</label>
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

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm mb-4">
            <form method="GET" action="{{ route('products.index') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="q" class="block text-sm font-medium mb-1">Search</label>
                    <input id="q" name="q" value="{{ $q }}" class="border rounded px-2 py-1" placeholder="Name, ID, SKU, ASIN">
                </div>
                <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Apply</button>
                <a href="{{ route('products.index') }}" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Clear</a>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm mb-4">
            <h3 class="text-sm font-semibold mb-3">Create Product</h3>
            <form method="POST" action="{{ route('products.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                @csrf
                <div class="md:col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">Name</label>
                    <input name="name" required class="w-full border rounded px-2 py-1" value="{{ old('name') }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Status</label>
                    <select name="status" class="w-full border rounded px-2 py-1">
                        <option value="active">active</option>
                        <option value="inactive">inactive</option>
                        <option value="draft">draft</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700 w-full">Create</button>
                </div>
                <div class="md:col-span-4">
                    <label class="block text-xs text-gray-600 mb-1">Notes</label>
                    <textarea name="notes" rows="2" class="w-full border rounded px-2 py-1">{{ old('notes') }}</textarea>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm overflow-x-auto">
            <table class="w-full text-sm" border="1" cellpadding="6" cellspacing="0">
                <thead class="bg-gray-100 dark:bg-gray-700">
                    <tr>
                        <th class="text-left">ID</th>
                        <th class="text-left">Name</th>
                        <th class="text-left">Primary Identifier</th>
                        <th class="text-left">Marketplace</th>
                        <th class="text-left">Status</th>
                        <th class="text-right">Identifiers</th>
                        <th class="text-right">Cost Layers</th>
                        <th class="text-left">Updated</th>
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
    </div>
</x-app-layout>
