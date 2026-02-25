<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Product {{ $product->id }}</h2>
            <a href="{{ route('products.index') }}" class="inline-flex items-center px-3 py-2 rounded-md border border-gray-300 bg-white text-gray-800 text-sm">Back to Products</a>
        </div>
    </x-slot>

    <div class="py-6 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">
        @if(session('status'))
            <div class="mb-4 p-3 rounded border border-green-200 bg-green-50 text-green-800 text-sm">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="mb-4 p-3 rounded border border-red-200 bg-red-50 text-red-800 text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm mb-4">
            <h3 class="text-sm font-semibold mb-3">Product Details</h3>
            <form method="POST" action="{{ route('products.update', $product) }}" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                @csrf @method('PATCH')
                <div class="md:col-span-2"><label class="block text-xs text-gray-600 mb-1">Name</label><input name="name" required class="w-full border rounded px-2 py-1" value="{{ old('name', $product->name) }}"></div>
                <div><label class="block text-xs text-gray-600 mb-1">Status</label><select name="status" class="w-full border rounded px-2 py-1">@foreach(['active','inactive','draft'] as $status)<option value="{{ $status }}" {{ old('status', $product->status) === $status ? 'selected' : '' }}>{{ $status }}</option>@endforeach</select></div>
                <div class="md:col-span-3"><label class="block text-xs text-gray-600 mb-1">Notes</label><textarea name="notes" rows="3" class="w-full border rounded px-2 py-1">{{ old('notes', $product->notes) }}</textarea></div>
                <div><button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Save</button></div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm mb-4">
            <h3 class="text-sm font-semibold mb-3">Identifiers</h3>
            <p class="text-xs text-gray-500 mb-3">Use explicit marketplace + region context for each identifier.</p>
            <form method="POST" action="{{ route('products.identifiers.store', $product) }}" class="grid grid-cols-1 md:grid-cols-6 gap-3 mb-4">
                @csrf
                <div><label class="block text-xs text-gray-600 mb-1">Type</label><select name="identifier_type" class="w-full border rounded px-2 py-1">@foreach(['seller_sku','asin','fnsku','upc','ean','other'] as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select></div>
                <div class="md:col-span-2"><label class="block text-xs text-gray-600 mb-1">Value</label><input name="identifier_value" required class="w-full border rounded px-2 py-1" value="{{ old('identifier_value') }}"></div>
                <div><label class="block text-xs text-gray-600 mb-1">Marketplace</label><select name="marketplace_id" class="w-full border rounded px-2 py-1"><option value="">-</option>@foreach($marketplaces as $marketplace)<option value="{{ $marketplace->id }}">{{ $marketplace->id }} · {{ $marketplace->name }}{{ $marketplace->country_code ? ' (' . $marketplace->country_code . ')' : '' }}</option>@endforeach</select></div>
                <div><label class="block text-xs text-gray-600 mb-1">Region</label><select name="region" class="w-full border rounded px-2 py-1"><option value="">-</option>@foreach(['EU','NA','FE'] as $region)<option value="{{ $region }}">{{ $region }}</option>@endforeach</select></div>
                <div class="flex items-end gap-2"><label class="text-xs"><input type="checkbox" name="is_primary" value="1"> Primary</label><button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Add</button></div>
            </form>

            <table class="w-full text-sm" border="1" cellpadding="6" cellspacing="0">
                <thead class="bg-gray-100 dark:bg-gray-700"><tr><th class="text-left">Type</th><th class="text-left">Value</th><th class="text-left">Marketplace</th><th class="text-left">Region</th><th class="text-left">Primary</th><th class="text-left">Actions</th></tr></thead>
                <tbody>
                    @forelse($product->identifiers as $identifier)
                        <tr>
                            <td><form method="POST" action="{{ route('products.identifiers.update', $identifier) }}" class="inline">@csrf @method('PATCH')<select name="identifier_type" class="border rounded px-2 py-1 text-xs">@foreach(['seller_sku','asin','fnsku','upc','ean','other'] as $type)<option value="{{ $type }}" {{ $identifier->identifier_type === $type ? 'selected' : '' }}>{{ $type }}</option>@endforeach</select></td>
                            <td><input name="identifier_value" value="{{ $identifier->identifier_value }}" class="border rounded px-2 py-1 text-xs w-full"></td>
                            <td><select name="marketplace_id" class="border rounded px-2 py-1 text-xs w-full"><option value="">-</option>@foreach($marketplaces as $marketplace)<option value="{{ $marketplace->id }}" {{ $identifier->marketplace_id === $marketplace->id ? 'selected' : '' }}>{{ $marketplace->id }} · {{ $marketplace->name }}</option>@endforeach</select></td>
                            <td><select name="region" class="border rounded px-2 py-1 text-xs"><option value="">-</option>@foreach(['EU','NA','FE'] as $region)<option value="{{ $region }}" {{ $identifier->region === $region ? 'selected' : '' }}>{{ $region }}</option>@endforeach</select></td>
                            <td><label class="text-xs"><input type="checkbox" name="is_primary" value="1" {{ $identifier->is_primary ? 'checked' : '' }}> primary</label></td>
                            <td><button type="submit" class="px-2 py-1 rounded border text-xs border-gray-300 bg-white text-gray-700">Save</button></form> <form method="POST" action="{{ route('products.identifiers.destroy', $identifier) }}" class="inline" onsubmit="return confirm('Delete this identifier?');">@csrf @method('DELETE')<button type="submit" class="px-2 py-1 rounded border text-xs border-gray-300 bg-white text-gray-700">Delete</button></form></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No identifiers yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm mb-4">
            <h3 class="text-sm font-semibold mb-3">Landed Cost History</h3>
            @foreach($product->identifiers as $identifier)
                <div class="border rounded p-3 mb-4">
                    <h4 class="text-xs font-semibold mb-2">{{ strtoupper($identifier->identifier_type) }}: {{ $identifier->identifier_value }} @ {{ $identifier->marketplace_id ?? '-' }} / {{ $identifier->region ?? '-' }}</h4>
                    <form method="POST" action="{{ route('products.identifiers.cost_layers.store', [$product, $identifier]) }}" class="grid grid-cols-1 md:grid-cols-8 gap-2 mb-3">
                        @csrf
                        <input name="effective_from" type="date" class="border rounded px-2 py-1 text-xs" required>
                        <input name="effective_to" type="date" class="border rounded px-2 py-1 text-xs" placeholder="open">
                        <input name="currency" class="border rounded px-2 py-1 text-xs" value="{{ $identifier->marketplace?->default_currency ?? 'USD' }}" required>
                        <select name="allocation_basis" class="border rounded px-2 py-1 text-xs"><option value="per_unit">per_unit</option><option value="per_shipment">per_shipment</option></select>
                        <input name="shipment_reference" class="border rounded px-2 py-1 text-xs" placeholder="shipment ref">
                        <input name="source" class="border rounded px-2 py-1 text-xs" placeholder="source">
                        <input name="notes" class="border rounded px-2 py-1 text-xs md:col-span-2" placeholder="notes">
                        <button type="submit" class="px-2 py-1 rounded border text-xs border-gray-300 bg-white text-gray-700">Add cost layer</button>
                    </form>
                    <table class="w-full text-xs" border="1" cellpadding="5" cellspacing="0">
                        <thead><tr><th>Effective From</th><th>Effective To</th><th>Currency</th><th>Unit Landed Cost</th><th>Source</th><th>Actions</th></tr></thead>
                        <tbody>
                            @forelse($identifier->costLayers as $layer)
                                <tr>
                                    <td>{{ $layer->effective_from?->format('Y-m-d') }}</td><td>{{ $layer->effective_to?->format('Y-m-d') ?? 'open' }}</td><td>{{ $layer->currency }}</td><td>{{ number_format((float) $layer->unit_landed_cost, 4) }}</td><td>{{ $layer->source ?? '-' }}</td>
                                    <td>
                                        <details>
                                            <summary class="cursor-pointer">Components ({{ $layer->components->count() }})</summary>
                                            <div class="mt-2">
                                                <form method="POST" action="{{ route('products.identifiers.cost_components.store', [$product, $identifier, $layer]) }}" class="grid grid-cols-1 md:grid-cols-6 gap-2 mb-2">
                                                    @csrf
                                                    <input name="component_type" class="border rounded px-2 py-1" placeholder="shipping" required>
                                                    <input name="amount" type="number" step="0.0001" min="0" class="border rounded px-2 py-1 js-cost-amount" required>
                                                    <select name="amount_basis" class="border rounded px-2 py-1 js-cost-basis"><option value="per_unit">per_unit</option><option value="per_shipment">per_shipment</option></select>
                                                    <input name="allocation_quantity" type="number" step="0.0001" min="0.0001" class="border rounded px-2 py-1 js-alloc-qty" placeholder="qty">
                                                    <input name="allocation_unit" class="border rounded px-2 py-1" placeholder="unit/carton">
                                                    <div class="text-xs text-gray-600 flex items-center">Preview unit cost: <span class="js-cost-preview ml-1">0.0000</span></div>
                                                    <button type="submit" class="px-2 py-1 rounded border text-xs border-gray-300 bg-white text-gray-700">Add component</button>
                                                </form>
                                                <table class="w-full text-xs" border="1" cellpadding="4" cellspacing="0">
                                                    <thead><tr><th>Type</th><th>Amount</th><th>Basis</th><th>Allocation</th><th>Normalized/Unit</th><th>Actions</th></tr></thead>
                                                    <tbody>
                                                        @forelse($layer->components as $component)
                                                            <tr>
                                                                <td>{{ $component->component_type }}</td><td>{{ number_format((float) $component->amount, 4) }}</td><td>{{ $component->amount_basis }}</td><td>{{ $component->allocation_quantity }} {{ $component->allocation_unit }}</td><td>{{ number_format((float) $component->normalized_unit_amount, 4) }}</td>
                                                                <td><form method="POST" action="{{ route('products.identifiers.cost_components.destroy', [$product, $identifier, $layer, $component]) }}" onsubmit="return confirm('Delete component?');">@csrf @method('DELETE')<button class="px-2 py-1 rounded border text-xs border-gray-300 bg-white">Delete</button></form></td>
                                                            </tr>
                                                        @empty
                                                            <tr><td colspan="6">No components.</td></tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </details>
                                        <form method="POST" action="{{ route('products.identifiers.cost_layers.destroy', [$product, $identifier, $layer]) }}" class="mt-2" onsubmit="return confirm('Delete cost layer?');">@csrf @method('DELETE')<button class="px-2 py-1 rounded border text-xs border-gray-300 bg-white">Delete layer</button></form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6">No cost layers.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm">
            <h3 class="text-sm font-semibold mb-3">Sale Price History</h3>
            @foreach($product->identifiers as $identifier)
                <div class="border rounded p-3 mb-4">
                    <h4 class="text-xs font-semibold mb-2">{{ strtoupper($identifier->identifier_type) }}: {{ $identifier->identifier_value }} @ {{ $identifier->marketplace_id ?? '-' }}</h4>
                    <form method="POST" action="{{ route('products.identifiers.sale_prices.store', [$product, $identifier]) }}" class="grid grid-cols-1 md:grid-cols-7 gap-2 mb-3">
                        @csrf
                        <input name="effective_from" type="date" class="border rounded px-2 py-1 text-xs" required>
                        <input name="effective_to" type="date" class="border rounded px-2 py-1 text-xs">
                        <input name="currency" class="border rounded px-2 py-1 text-xs" value="{{ $identifier->marketplace?->default_currency ?? 'USD' }}" required>
                        <input name="sale_price" type="number" step="0.0001" min="0" class="border rounded px-2 py-1 text-xs" required>
                        <input name="source" class="border rounded px-2 py-1 text-xs" placeholder="source">
                        <input name="notes" class="border rounded px-2 py-1 text-xs" placeholder="notes">
                        <button type="submit" class="px-2 py-1 rounded border text-xs border-gray-300 bg-white text-gray-700">Add sale price</button>
                    </form>
                    <table class="w-full text-xs" border="1" cellpadding="5" cellspacing="0">
                        <thead><tr><th>Identifier</th><th>Effective From</th><th>Effective To</th><th>Currency</th><th>Sale Price</th><th>Source</th><th>Actions</th></tr></thead>
                        <tbody>
                            @forelse($identifier->salePrices as $salePrice)
                                <tr>
                                    <td>{{ $identifier->identifier_type }}:{{ $identifier->identifier_value }}</td>
                                    <td>{{ $salePrice->effective_from?->format('Y-m-d') }}</td>
                                    <td>{{ $salePrice->effective_to?->format('Y-m-d') ?? 'open' }}</td>
                                    <td>{{ $salePrice->currency }}</td>
                                    <td>{{ number_format((float) $salePrice->sale_price, 4) }}</td>
                                    <td>{{ $salePrice->source ?? '-' }}</td>
                                    <td><form method="POST" action="{{ route('products.identifiers.sale_prices.destroy', [$product, $identifier, $salePrice]) }}" onsubmit="return confirm('Delete sale price?');">@csrf @method('DELETE')<button class="px-2 py-1 rounded border text-xs border-gray-300 bg-white">Delete</button></form></td>
                                </tr>
                            @empty
                                <tr><td colspan="7">No sale prices.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    </div>

    <script>
        document.querySelectorAll('.grid').forEach(function (form) {
            const amount = form.querySelector('.js-cost-amount');
            const basis = form.querySelector('.js-cost-basis');
            const qty = form.querySelector('.js-alloc-qty');
            const preview = form.querySelector('.js-cost-preview');
            if (!amount || !basis || !qty || !preview) return;
            const updatePreview = function () {
                const amountValue = parseFloat(amount.value || '0');
                const quantityValue = parseFloat(qty.value || '0');
                let normalized = amountValue;
                if (basis.value === 'per_shipment') {
                    normalized = quantityValue > 0 ? (amountValue / quantityValue) : 0;
                }
                preview.textContent = normalized.toFixed(4);
            };
            ['input','change'].forEach(evt => {
                amount.addEventListener(evt, updatePreview);
                basis.addEventListener(evt, updatePreview);
                qty.addEventListener(evt, updatePreview);
            });
        });
    </script>
</x-app-layout>
