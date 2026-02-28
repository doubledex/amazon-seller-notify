<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                MCU #{{ $mcu->id }}
            </h2>
            <a href="{{ route('products.index') }}" class="inline-flex items-center px-3 py-2 rounded-md border border-gray-300 bg-white text-gray-800 text-sm">
                Back to Families
            </a>
        </div>
    </x-slot>

    <div class="py-6 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-4">
        @if(session('status'))
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm px-3 py-2 rounded">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-800 text-sm px-3 py-2 rounded">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm">
            <h3 class="text-sm font-semibold mb-3">MCU Details</h3>
            <form method="POST" action="{{ route('mcus.update', $mcu) }}" class="grid grid-cols-1 md:grid-cols-6 gap-3">
                @csrf
                @method('PATCH')
                <div class="md:col-span-3">
                    <label class="block text-xs text-gray-600 mb-1">Name</label>
                    <input name="name" value="{{ old('name', $mcu->name) }}" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Base UOM</label>
                    <input name="base_uom" value="{{ old('base_uom', $mcu->base_uom) }}" class="w-full border rounded px-2 py-1" required>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Family</label>
                    <div class="text-sm py-2">{{ $mcu->family?->name ?: ('Family #' . ($mcu->family_id ?? 'Unassigned')) }}</div>
                </div>
                <div class="md:col-span-6 grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Net Weight</label>
                        <input name="net_weight" type="number" step="0.0001" min="0" value="{{ old('net_weight', $mcu->net_weight) }}" class="w-full border rounded px-2 py-1">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Net Length</label>
                        <input name="net_length" type="number" step="0.0001" min="0" value="{{ old('net_length', $mcu->net_length) }}" class="w-full border rounded px-2 py-1">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Net Width</label>
                        <input name="net_width" type="number" step="0.0001" min="0" value="{{ old('net_width', $mcu->net_width) }}" class="w-full border rounded px-2 py-1">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Net Height</label>
                        <input name="net_height" type="number" step="0.0001" min="0" value="{{ old('net_height', $mcu->net_height) }}" class="w-full border rounded px-2 py-1">
                    </div>
                </div>
                <div class="md:col-span-6">
                    <button type="submit" class="px-3 py-2 rounded border border-gray-300 bg-white text-sm">Save MCU</button>
                </div>
            </form>

            <form method="POST" action="{{ route('mcus.family.update', $mcu) }}" class="mt-4 grid grid-cols-1 md:grid-cols-5 gap-3">
                @csrf
                @method('PATCH')
                <div class="md:col-span-3">
                    <label class="block text-xs text-gray-600 mb-1">Move To Family</label>
                    <select name="family_id" class="w-full border rounded px-2 py-1">
                        <option value="">Unassigned</option>
                        @foreach($familyOptions as $option)
                            <option value="{{ $option->id }}" {{ (int) $mcu->family_id === (int) $option->id ? 'selected' : '' }}>
                                #{{ $option->id }} {{ $option->name ?: 'Unnamed family' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2 flex items-end">
                    <button type="submit" class="px-3 py-2 rounded border border-gray-300 bg-white text-sm">Update Family</button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm">
            <h3 class="text-sm font-semibold mb-3">Sellable Unit Identifier</h3>
            @php
                $sellableUnit = $mcu->sellableUnits->first();
            @endphp
            <form method="POST" action="{{ route('mcus.sellable_unit.update', $mcu) }}" class="grid grid-cols-1 md:grid-cols-5 gap-3">
                @csrf
                @method('PATCH')
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Type</label>
                    <input value="barcode" class="w-full border rounded px-2 py-1 bg-gray-50" readonly>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Value</label>
                    <input name="barcode" value="{{ old('barcode', $sellableUnit?->barcode) }}" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Packaged Weight</label>
                    <input name="packaged_weight" type="number" step="0.0001" min="0" value="{{ old('packaged_weight', $sellableUnit?->packaged_weight) }}" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Packaged Length</label>
                    <input name="packaged_length" type="number" step="0.0001" min="0" value="{{ old('packaged_length', $sellableUnit?->packaged_length) }}" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Packaged Width</label>
                    <input name="packaged_width" type="number" step="0.0001" min="0" value="{{ old('packaged_width', $sellableUnit?->packaged_width) }}" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Packaged Height</label>
                    <input name="packaged_height" type="number" step="0.0001" min="0" value="{{ old('packaged_height', $sellableUnit?->packaged_height) }}" class="w-full border rounded px-2 py-1">
                </div>
                <div class="md:col-span-4 flex items-end">
                    <button type="submit" class="px-3 py-2 rounded border border-gray-300 bg-white text-sm">Save Barcode</button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm">
            <h3 class="text-sm font-semibold mb-3">Identifiers (Grouped by Type)</h3>

            <div class="overflow-x-auto mb-4">
                <table class="w-full text-sm border" cellspacing="0" cellpadding="6">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left border">Type</th>
                            <th class="text-left border">Value</th>
                            <th class="text-left border">Marketplace</th>
                            <th class="text-left border">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($mcu->marketplaceProjections as $projection)
                            <tr>
                                <td class="border">asin</td>
                                <td class="border">{{ $projection->child_asin }}</td>
                                <td class="border">{{ $projection->marketplace }}</td>
                                <td class="border" rowspan="4">
                                    <form method="POST" action="{{ route('mcus.projections.update', [$mcu, $projection]) }}" class="space-y-2 mb-2">
                                        @csrf
                                        @method('PATCH')
                                        <input type="text" name="marketplace" value="{{ $projection->marketplace }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="marketplace">
                                        <input type="text" name="parent_asin" value="{{ $projection->parent_asin }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="parent ASIN">
                                        <input type="text" name="child_asin" value="{{ $projection->child_asin }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="child ASIN">
                                        <input type="text" name="seller_sku" value="{{ $projection->seller_sku }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="seller SKU">
                                        <input type="text" name="fnsku" value="{{ $projection->fnsku }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="FNSKU">
                                        <div class="grid grid-cols-2 gap-2">
                                            <select name="fulfilment_type" class="border rounded px-2 py-1 text-xs">
                                                @foreach(['FBA', 'MFN'] as $type)
                                                    <option value="{{ $type }}" {{ strtoupper((string) $projection->fulfilment_type) === $type ? 'selected' : '' }}>{{ $type }}</option>
                                                @endforeach
                                            </select>
                                            <select name="fulfilment_region" class="border rounded px-2 py-1 text-xs">
                                                @foreach(['EU', 'NA', 'FE'] as $region)
                                                    <option value="{{ $region }}" {{ strtoupper((string) $projection->fulfilment_region) === $region ? 'selected' : '' }}>{{ $region }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <label class="text-xs">
                                            <input type="checkbox" name="active" value="1" {{ $projection->active ? 'checked' : '' }}>
                                            active
                                        </label>
                                        <button type="submit" class="px-2 py-1 rounded border border-gray-300 bg-white text-xs">Save</button>
                                    </form>
                                    <form method="POST" action="{{ route('mcus.projections.destroy', [$mcu, $projection]) }}" onsubmit="return confirm('Delete this identifier row?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-2 py-1 rounded border border-gray-300 bg-white text-xs">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <tr>
                                <td class="border">seller_sku</td>
                                <td class="border">{{ $projection->seller_sku }}</td>
                                <td class="border">{{ $projection->marketplace }}</td>
                            </tr>
                            <tr>
                                <td class="border">fnsku</td>
                                <td class="border">{{ $projection->fnsku ?: '-' }}</td>
                                <td class="border">{{ $projection->marketplace }}</td>
                            </tr>
                            <tr>
                                <td class="border">parent_asin</td>
                                <td class="border">{{ $projection->parent_asin ?: '-' }}</td>
                                <td class="border">{{ $projection->marketplace }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="border text-gray-500">No marketplace identifier rows yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <h4 class="text-xs font-semibold mb-2">Add Identifier Row</h4>
            <form method="POST" action="{{ route('mcus.projections.store', $mcu) }}" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                @csrf
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Marketplace</label>
                    <input name="marketplace" class="w-full border rounded px-2 py-1" required>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Parent ASIN</label>
                    <input name="parent_asin" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Child ASIN</label>
                    <input name="child_asin" class="w-full border rounded px-2 py-1" required>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Seller SKU</label>
                    <input name="seller_sku" class="w-full border rounded px-2 py-1" required>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">FNSKU</label>
                    <input name="fnsku" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Fulfilment Type</label>
                    <select name="fulfilment_type" class="w-full border rounded px-2 py-1">
                        @foreach(['MFN', 'FBA'] as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Fulfilment Region</label>
                    <select name="fulfilment_region" class="w-full border rounded px-2 py-1">
                        @foreach(['EU', 'NA', 'FE'] as $region)
                            <option value="{{ $region }}">{{ $region }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <label class="text-xs"><input type="checkbox" name="active" value="1" checked> active</label>
                    <button type="submit" class="px-3 py-2 rounded border border-gray-300 bg-white text-sm">Add</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
