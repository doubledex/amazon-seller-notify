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
            <h3 class="text-sm font-semibold mb-3">MCU Identifiers</h3>

            <div class="overflow-x-auto mb-4">
                <table class="w-full text-sm border" cellspacing="0" cellpadding="6">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left border">Type</th>
                            <th class="text-left border">Value</th>
                            <th class="text-left border">Channel</th>
                            <th class="text-left border">Marketplace</th>
                            <th class="text-left border">Region</th>
                            <th class="text-left border">Projection?</th>
                            <th class="text-left border">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($mcu->identifiers as $identifier)
                            <tr>
                                <td class="border">
                                    <form method="POST" action="{{ route('mcus.identifiers.update', [$mcu, $identifier]) }}" class="grid grid-cols-1 md:grid-cols-7 gap-2">
                                        @csrf
                                        @method('PATCH')
                                        <select name="identifier_type" class="border rounded px-2 py-1 text-xs">
                                            @foreach(['asin', 'seller_sku', 'fnsku', 'barcode', 'cost_identifier', 'other'] as $type)
                                                <option value="{{ $type }}" {{ $identifier->identifier_type === $type ? 'selected' : '' }}>{{ $type }}</option>
                                            @endforeach
                                        </select>
                                </td>
                                <td class="border"><input name="identifier_value" value="{{ $identifier->identifier_value }}" class="w-full border rounded px-2 py-1 text-xs"></td>
                                <td class="border"><input name="channel" value="{{ $identifier->channel }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="amazon"></td>
                                <td class="border"><input name="marketplace" value="{{ $identifier->marketplace }}" class="w-full border rounded px-2 py-1 text-xs"></td>
                                <td class="border"><input name="region" value="{{ $identifier->region }}" class="w-full border rounded px-2 py-1 text-xs"></td>
                                <td class="border text-center"><input type="checkbox" name="is_projection_identifier" value="1" {{ $identifier->is_projection_identifier ? 'checked' : '' }}></td>
                                <td class="border whitespace-nowrap">
                                        <button type="submit" class="px-2 py-1 rounded border border-gray-300 bg-white text-xs">Save</button>
                                    </form>
                                    <form method="POST" action="{{ route('mcus.identifiers.destroy', [$mcu, $identifier]) }}" class="inline" onsubmit="return confirm('Delete this identifier?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-2 py-1 rounded border border-gray-300 bg-white text-xs">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="border text-gray-500">No MCU identifiers yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <h4 class="text-xs font-semibold mb-2">Add MCU Identifier</h4>
            <form method="POST" action="{{ route('mcus.identifiers.store', $mcu) }}" class="grid grid-cols-1 md:grid-cols-7 gap-3 mb-4">
                @csrf
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Type</label>
                    <select name="identifier_type" class="w-full border rounded px-2 py-1">
                        @foreach(['asin', 'seller_sku', 'fnsku', 'barcode', 'cost_identifier', 'other'] as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Value</label>
                    <input name="identifier_value" class="w-full border rounded px-2 py-1" required>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Channel</label>
                    <input name="channel" class="w-full border rounded px-2 py-1" placeholder="amazon">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Marketplace</label>
                    <input name="marketplace" class="w-full border rounded px-2 py-1">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Region</label>
                    <input name="region" class="w-full border rounded px-2 py-1" placeholder="EU">
                </div>
                <div class="flex items-end">
                    <label class="text-xs"><input type="checkbox" name="is_projection_identifier" value="1"> projection identifier</label>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="px-3 py-2 rounded border border-gray-300 bg-white text-sm">Add</button>
                </div>
            </form>

            <h3 class="text-sm font-semibold mb-3">Marketplace Projections</h3>

            <div class="overflow-x-auto mb-4">
                <table class="w-full text-sm border" cellspacing="0" cellpadding="6">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left border">Channel</th>
                            <th class="text-left border">Marketplace</th>
                            <th class="text-left border">Parent ASIN</th>
                            <th class="text-left border">ASIN</th>
                            <th class="text-left border">Seller SKU</th>
                            <th class="text-left border">External ID</th>
                            <th class="text-left border">FNSKU</th>
                            <th class="text-left border">Fulfilment</th>
                            <th class="text-left border">Region</th>
                            <th class="text-left border">Active</th>
                            <th class="text-left border">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="bg-gray-50">
                            <td class="border">
                                <select name="channel" form="projection-create-form" class="w-full border rounded px-2 py-1 text-xs">
                                    @foreach(['amazon', 'woocommerce', 'other'] as $channel)
                                        <option value="{{ $channel }}">{{ $channel }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="border">
                                <input name="marketplace" form="projection-create-form" class="w-full border rounded px-2 py-1 text-xs" required>
                            </td>
                            <td class="border">
                                <input name="parent_asin" form="projection-create-form" class="w-full border rounded px-2 py-1 text-xs">
                            </td>
                            <td class="border">
                                <input name="child_asin" form="projection-create-form" class="w-full border rounded px-2 py-1 text-xs">
                            </td>
                            <td class="border">
                                <input name="seller_sku" form="projection-create-form" class="w-full border rounded px-2 py-1 text-xs" required>
                            </td>
                            <td class="border">
                                <input name="external_product_id" form="projection-create-form" class="w-full border rounded px-2 py-1 text-xs">
                            </td>
                            <td class="border">
                                <input name="fnsku" form="projection-create-form" class="w-full border rounded px-2 py-1 text-xs">
                            </td>
                            <td class="border">
                                <select name="fulfilment_type" form="projection-create-form" class="w-full border rounded px-2 py-1 text-xs">
                                    @foreach(['MFN', 'FBA'] as $type)
                                        <option value="{{ $type }}">{{ $type }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="border">
                                <select name="fulfilment_region" form="projection-create-form" class="w-full border rounded px-2 py-1 text-xs">
                                    @foreach(['EU', 'NA', 'FE'] as $region)
                                        <option value="{{ $region }}">{{ $region }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="border text-center">
                                <input type="checkbox" name="active" value="1" form="projection-create-form" checked>
                            </td>
                            <td class="border">
                                <button type="submit" form="projection-create-form" class="px-2 py-1 rounded border border-gray-300 bg-white text-xs">Add</button>
                                <form id="projection-create-form" method="POST" action="{{ route('mcus.projections.store', $mcu) }}" class="hidden">
                                    @csrf
                                </form>
                            </td>
                        </tr>
                        @forelse($mcu->marketplaceProjections as $projection)
                            @php
                                $updateFormId = 'projection-update-' . $projection->id;
                            @endphp
                            <tr>
                                <td class="border">
                                    <select name="channel" form="{{ $updateFormId }}" class="w-full border rounded px-2 py-1 text-xs">
                                        @foreach(['amazon', 'woocommerce', 'other'] as $channel)
                                            <option value="{{ $channel }}" {{ ($projection->channel ?? 'amazon') === $channel ? 'selected' : '' }}>{{ $channel }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="border">
                                    <input type="text" name="marketplace" value="{{ $projection->marketplace }}" form="{{ $updateFormId }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="marketplace">
                                </td>
                                <td class="border">
                                    <input type="text" name="parent_asin" value="{{ $projection->parent_asin }}" form="{{ $updateFormId }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="parent ASIN">
                                </td>
                                <td class="border">
                                    <input type="text" name="child_asin" value="{{ $projection->child_asin }}" form="{{ $updateFormId }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="ASIN">
                                </td>
                                <td class="border">
                                    <input type="text" name="seller_sku" value="{{ $projection->seller_sku }}" form="{{ $updateFormId }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="seller SKU">
                                </td>
                                <td class="border">
                                    <input type="text" name="external_product_id" value="{{ $projection->external_product_id }}" form="{{ $updateFormId }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="external product id">
                                </td>
                                <td class="border">
                                    <input type="text" name="fnsku" value="{{ $projection->fnsku }}" form="{{ $updateFormId }}" class="w-full border rounded px-2 py-1 text-xs" placeholder="FNSKU">
                                </td>
                                <td class="border">
                                    <select name="fulfilment_type" form="{{ $updateFormId }}" class="w-full border rounded px-2 py-1 text-xs">
                                        @foreach(['FBA', 'MFN'] as $type)
                                            <option value="{{ $type }}" {{ strtoupper((string) $projection->fulfilment_type) === $type ? 'selected' : '' }}>{{ $type }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="border">
                                    <select name="fulfilment_region" form="{{ $updateFormId }}" class="w-full border rounded px-2 py-1 text-xs">
                                        @foreach(['EU', 'NA', 'FE'] as $region)
                                            <option value="{{ $region }}" {{ strtoupper((string) $projection->fulfilment_region) === $region ? 'selected' : '' }}>{{ $region }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="border text-center">
                                    <input type="checkbox" name="active" value="1" form="{{ $updateFormId }}" {{ $projection->active ? 'checked' : '' }}>
                                </td>
                                <td class="border">
                                    <button type="submit" form="{{ $updateFormId }}" class="px-2 py-1 rounded border border-gray-300 bg-white text-xs mb-1">Save</button>
                                    <form method="POST" action="{{ route('mcus.projections.destroy', [$mcu, $projection]) }}" onsubmit="return confirm('Delete this projection row?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-2 py-1 rounded border border-gray-300 bg-white text-xs">Delete</button>
                                    </form>
                                    <form id="{{ $updateFormId }}" method="POST" action="{{ route('mcus.projections.update', [$mcu, $projection]) }}" class="hidden">
                                        @csrf
                                        @method('PATCH')
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="border text-gray-500">No marketplace identifier rows yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
