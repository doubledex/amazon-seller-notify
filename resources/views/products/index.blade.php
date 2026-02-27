<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Products (Grouped by Family → MCU)
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm mb-1">Search (MCU / ASIN / SKU)</label>
                    <input type="text" name="q" value="{{ $q }}" class="w-full border rounded px-2 py-1" />
                </div>
                <div>
                    <label class="block text-sm mb-1">Marketplace</label>
                    <select name="marketplace" class="w-full border rounded px-2 py-1">
                        <option value="">All</option>
                        @foreach($marketplaceOptions as $option)
                            <option value="{{ $option->id }}" {{ $marketplace === $option->id ? 'selected' : '' }}>
                                {{ $option->name ?: $option->id }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <button class="px-4 py-2 rounded border border-gray-300 bg-white">Apply</button>
                </div>
            </form>
        </div>

        @forelse($families as $familyRow)
            @php
                $family = $familyRow['family'];
                $familyMcus = $familyRow['mcus'];
            @endphp
            <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm">
                <div class="mb-3">
                    <h3 class="text-sm font-semibold">
                        Family ID: {{ $family?->id ?? 'Unassigned' }}
                    </h3>
                    <div class="text-xs text-gray-500">
                        {{ $family?->name ?? 'No family name' }}
                        @if($family?->marketplace)
                            · {{ $family->marketplace }}
                        @endif
                        @if($family?->parent_asin)
                            · Parent ASIN: {{ $family->parent_asin }}
                        @endif
                    </div>
                </div>

                @foreach($familyMcus as $mcuRow)
                    @php
                        $mcu = $mcuRow['mcu'];
                    @endphp
                    <div class="border rounded mb-4 p-3">
                        <div class="font-semibold mb-2">MCU #{{ $mcu->id }} — {{ $mcu->name ?: 'Unnamed MCU' }}</div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 text-xs">
                            <div>
                                <div class="font-semibold mb-1">Sellable Units</div>
                                @forelse($mcuRow['sellable_units'] as $unit)
                                    <div>SU #{{ $unit->id }} · barcode: {{ $unit->barcode ?? '-' }} · pkg_wt: {{ $unit->packaged_weight ?? '-' }}</div>
                                @empty
                                    <div>-</div>
                                @endforelse
                            </div>

                            <div>
                                <div class="font-semibold mb-1">Marketplace Projections</div>
                                @forelse($mcuRow['marketplace_projections'] as $projection)
                                    <div>
                                        MP #{{ $projection->id }} · {{ $projection->marketplace }} ·
                                        P: {{ $projection->parent_asin ?? '-' }} ·
                                        C: {{ $projection->child_asin }} ·
                                        SKU: {{ $projection->seller_sku }} ·
                                        {{ $projection->fulfilment_type }}/{{ $projection->fulfilment_region }}
                                    </div>
                                @empty
                                    <div>-</div>
                                @endforelse
                            </div>

                            <div>
                                <div class="font-semibold mb-1">Cost Contexts</div>
                                @forelse($mcuRow['cost_contexts'] as $cost)
                                    <div>
                                        CC #{{ $cost->id }} · {{ $cost->region }} · {{ $cost->currency }} {{ number_format((float) $cost->landed_cost_per_unit, 4) }} · {{ optional($cost->effective_from)->format('Y-m-d') }}
                                    </div>
                                @empty
                                    <div>-</div>
                                @endforelse
                            </div>

                            <div>
                                <div class="font-semibold mb-1">Inventory States</div>
                                @forelse($mcuRow['inventory_states'] as $state)
                                    <div>
                                        IS #{{ $state->id }} · {{ $state->location }} · on_hand {{ $state->on_hand }} · reserved {{ $state->reserved }} · safety {{ $state->safety_buffer }} · available {{ $state->available }}
                                    </div>
                                @empty
                                    <div>-</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="mt-3 text-xs">
                            <div class="font-semibold mb-1">Margin Snapshots (derived)</div>
                            @forelse($mcuRow['margin_snapshots'] as $snapshot)
                                <div>
                                    Projection {{ $snapshot['projection_id'] }} · region {{ $snapshot['region'] }} · margin {{ number_format((float) $snapshot['margin_amount'], 4) }} · margin % {{ $snapshot['margin_percent'] ?? '-' }}
                                </div>
                            @empty
                                <div>-</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        @empty
            <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm">No MCU families found.</div>
        @endforelse
    </div>
</x-app-layout>
