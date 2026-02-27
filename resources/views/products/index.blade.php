<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Products (MCU-first)
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-3">
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
                <div>
                    <label class="block text-sm mb-1">Per Page</label>
                    <select name="per_page" class="w-full border rounded px-2 py-1">
                        @foreach([25,50,100,200] as $size)
                            <option value="{{ $size }}" {{ (int) $perPage === $size ? 'selected' : '' }}>{{ $size }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <button class="px-4 py-2 rounded border border-gray-300 bg-white">Apply</button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm overflow-x-auto">
            <table class="w-full text-sm" border="1" cellpadding="6" cellspacing="0">
                <thead class="bg-gray-100 dark:bg-gray-700">
                    <tr>
                        <th>MCU</th>
                        <th>Marketplace</th>
                        <th>Parent/Child ASIN</th>
                        <th>Seller SKU</th>
                        <th>Fulfilment</th>
                        <th>Current Landed Cost</th>
                        <th>Inventory State</th>
                        <th>Margin Snapshot</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($mcus as $row)
                        @php
                            $mcu = $row['mcu'];
                            $projection = $row['projection'];
                            $costByRegion = $row['cost_by_region'];
                            $inventory = $row['inventory'];
                            $margin = $row['margin_snapshot'];
                        @endphp
                        <tr>
                            <td>
                                <div class="font-semibold">{{ $mcu->name ?: ('MCU #' . $mcu->id) }}</div>
                                <div class="text-xs text-gray-500">ID: {{ $mcu->id }}</div>
                            </td>
                            <td>{{ $projection?->marketplace ?? '-' }}</td>
                            <td>
                                P: {{ $projection?->parent_asin ?? '-' }}<br>
                                C: {{ $projection?->child_asin ?? '-' }}
                            </td>
                            <td>{{ $projection?->seller_sku ?? '-' }}</td>
                            <td>{{ $projection?->fulfilment_type ?? '-' }} / {{ $projection?->fulfilment_region ?? '-' }}</td>
                            <td>
                                @forelse($costByRegion as $region => $ctx)
                                    <div>{{ $region }}: {{ $ctx->currency }} {{ number_format((float) $ctx->landed_cost_per_unit, 4) }}</div>
                                @empty
                                    -
                                @endforelse
                            </td>
                            <td>
                                @forelse($inventory as $state)
                                    <div class="text-xs mb-1">
                                        {{ $state['location'] }}: on_hand {{ $state['on_hand'] }}, reserved {{ $state['reserved'] }}, safety {{ $state['safety_buffer'] }}, available {{ $state['available'] }}
                                    </div>
                                @empty
                                    -
                                @endforelse
                            </td>
                            <td>
                                @if($margin)
                                    <div class="text-xs">margin: {{ number_format((float) $margin['margin_amount'], 4) }}</div>
                                    <div class="text-xs">margin %: {{ $margin['margin_percent'] ?? '-' }}</div>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No MCUs found.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-4">{{ $mcus->links() }}</div>
        </div>
    </div>
</x-app-layout>
