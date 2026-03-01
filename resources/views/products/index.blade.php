<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Families and MCUs
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
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
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <div>
                    <label class="block text-sm mb-1">Search (Family / MCU / ASIN / SKU)</label>
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
                    <label class="block text-sm mb-1">MCU Sort</label>
                    <select name="mcu_sort" class="w-full border rounded px-2 py-1">
                        <option value="name" {{ $mcuSort === 'name' ? 'selected' : '' }}>Name</option>
                        <option value="identifier" {{ $mcuSort === 'identifier' ? 'selected' : '' }}>Identifier</option>
                        <option value="asin" {{ $mcuSort === 'asin' ? 'selected' : '' }}>ASIN</option>
                        <option value="seller_sku" {{ $mcuSort === 'seller_sku' ? 'selected' : '' }}>Seller SKU</option>
                        <option value="fnsku" {{ $mcuSort === 'fnsku' ? 'selected' : '' }}>FNSKU</option>
                        <option value="barcode" {{ $mcuSort === 'barcode' ? 'selected' : '' }}>Barcode</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm mb-1">Direction</label>
                    <select name="mcu_dir" class="w-full border rounded px-2 py-1">
                        <option value="asc" {{ $mcuDir === 'asc' ? 'selected' : '' }}>Ascending</option>
                        <option value="desc" {{ $mcuDir === 'desc' ? 'selected' : '' }}>Descending</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button class="px-4 py-2 rounded border border-gray-300 bg-white">Apply</button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm">
            <h3 class="text-sm font-semibold mb-3">Create Family</h3>
            <form method="POST" action="{{ route('families.store') }}" class="grid grid-cols-1 md:grid-cols-6 gap-3">
                @csrf
                <div class="md:col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">Family name</label>
                    <input
                        name="name"
                        value="{{ old('name') }}"
                        class="w-full border rounded px-2 py-1 text-sm"
                        maxlength="255"
                        required
                    />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">Marketplace</label>
                    <select name="marketplace" class="w-full border rounded px-2 py-1 text-sm" required>
                        <option value="">Select marketplace</option>
                        @foreach($marketplaceOptions as $option)
                            <option value="{{ $option->id }}" {{ old('marketplace') === $option->id ? 'selected' : '' }}>
                                {{ $option->name ?: $option->id }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-1">
                    <label class="block text-xs text-gray-600 mb-1">Parent ASIN (optional)</label>
                    <input
                        name="parent_asin"
                        value="{{ old('parent_asin') }}"
                        class="w-full border rounded px-2 py-1 text-sm uppercase"
                        maxlength="32"
                        placeholder="B0..."
                    />
                </div>
                <div class="md:col-span-1 flex items-end">
                    <button type="submit" class="px-3 py-2 rounded border border-gray-300 bg-white text-sm">
                        Create family
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold">Family Groups</h3>
                <span class="text-xs text-gray-500">Paginated</span>
            </div>

            @forelse($families as $family)
                <details class="border rounded p-3">
                    <summary class="cursor-pointer">
                        <div class="inline-flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-semibold">Family #{{ $family->id }}</span>
                            <span>{{ $family->name ?: 'Unnamed family' }}</span>
                            <span class="text-gray-500">({{ $family->mcus_count }} MCUs)</span>
                            @if($family->marketplace)
                                <span class="text-gray-500">· {{ $family->marketplace }}</span>
                            @endif
                            @if($family->parent_asin)
                                <span class="text-gray-500">· Parent ASIN: {{ $family->parent_asin }}</span>
                            @endif
                        </div>
                    </summary>

                    <div class="mt-3 space-y-3">
                        <form method="POST" action="{{ route('families.update', $family) }}" class="grid grid-cols-1 md:grid-cols-5 gap-2">
                            @csrf
                            @method('PATCH')
                            <div class="md:col-span-3">
                                <label class="block text-xs text-gray-600 mb-1">Family name</label>
                                <input
                                    name="name"
                                    value="{{ old('name', $family->name) }}"
                                    class="w-full border rounded px-2 py-1 text-sm"
                                    maxlength="255"
                                    required
                                />
                            </div>
                            <div class="md:col-span-2 flex items-end">
                                <button type="submit" class="px-3 py-2 rounded border border-gray-300 bg-white text-sm">
                                    Save family name
                                </button>
                            </div>
                        </form>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm border" cellspacing="0" cellpadding="6">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="text-left border">MCU</th>
                                        <th class="text-left border">Name</th>
                                        <th class="text-left border">Identifiers</th>
                                        <th class="text-left border">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($family->mcus as $mcu)
                                        <tr>
                                            <td class="border">
                                                <a href="{{ route('mcus.show', $mcu) }}" class="underline">#{{ $mcu->id }}</a>
                                            </td>
                                            <td class="border">
                                                <a href="{{ route('mcus.show', $mcu) }}" class="underline">{{ $mcu->name ?: 'Unnamed MCU' }}</a>
                                            </td>
                                            <td class="border">
                                                @php
                                                    $projection = $mcu->marketplaceProjections->first();
                                                    $identifierRows = $mcu->identifiers->take(3);
                                                    $sellableUnit = $mcu->sellableUnits->first();
                                                @endphp
                                                <div class="text-xs">
                                                    @if($identifierRows->isNotEmpty())
                                                        @foreach($identifierRows as $identifier)
                                                            <div>{{ $identifier->identifier_type }}: {{ $identifier->identifier_value }}</div>
                                                        @endforeach
                                                    @elseif($projection)
                                                        <div>ASIN: {{ $projection->child_asin ?: '-' }}</div>
                                                        <div>SKU: {{ $projection->seller_sku }}</div>
                                                    @else
                                                        <div>-</div>
                                                    @endif
                                                    @if($sellableUnit?->barcode)
                                                        <div>Barcode: {{ $sellableUnit->barcode }}</div>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="border">
                                                <a href="{{ route('mcus.show', $mcu) }}" class="text-sm underline">Open</a>
                                                <form method="POST" action="{{ route('mcus.family.update', $mcu) }}" class="mt-2">
                                                    @csrf
                                                    @method('PATCH')
                                                    <div class="flex gap-2">
                                                        <select name="family_id" class="border rounded px-2 py-1 text-xs">
                                                            <option value="">Unassigned</option>
                                                            @foreach($familyOptions as $option)
                                                                <option value="{{ $option->id }}" {{ (int) $mcu->family_id === (int) $option->id ? 'selected' : '' }}>
                                                                    #{{ $option->id }} {{ $option->name ?: 'Unnamed family' }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                        <button type="submit" class="px-2 py-1 rounded border border-gray-300 bg-white text-xs">Move</button>
                                                    </div>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="border text-gray-500">No MCUs in this family.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
            @empty
                <div class="text-sm text-gray-500">No families found.</div>
            @endforelse

            <div>
                {{ $families->links() }}
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm space-y-3">
            <h3 class="text-sm font-semibold">Unassigned MCUs</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm border" cellspacing="0" cellpadding="6">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left border">MCU</th>
                            <th class="text-left border">Name</th>
                            <th class="text-left border">Identifiers</th>
                            <th class="text-left border">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($unassignedMcus as $mcu)
                            @php
                                $projection = $mcu->marketplaceProjections->first();
                                $identifierRows = $mcu->identifiers->take(3);
                                $sellableUnit = $mcu->sellableUnits->first();
                            @endphp
                            <tr>
                                <td class="border">
                                    <a href="{{ route('mcus.show', $mcu) }}" class="underline">#{{ $mcu->id }}</a>
                                </td>
                                <td class="border">
                                    <a href="{{ route('mcus.show', $mcu) }}" class="underline">{{ $mcu->name ?: 'Unnamed MCU' }}</a>
                                </td>
                                <td class="border text-xs">
                                    @if($identifierRows->isNotEmpty())
                                        @foreach($identifierRows as $identifier)
                                            <div>{{ $identifier->identifier_type }}: {{ $identifier->identifier_value }}</div>
                                        @endforeach
                                    @elseif($projection)
                                        <div>ASIN: {{ $projection->child_asin ?: '-' }}</div>
                                        <div>SKU: {{ $projection->seller_sku }}</div>
                                    @else
                                        <div>-</div>
                                    @endif
                                    @if($sellableUnit?->barcode)
                                        <div>Barcode: {{ $sellableUnit->barcode }}</div>
                                    @endif
                                </td>
                                <td class="border">
                                    <a href="{{ route('mcus.show', $mcu) }}" class="text-sm underline">Open</a>
                                    <form method="POST" action="{{ route('mcus.family.update', $mcu) }}" class="mt-2">
                                        @csrf
                                        @method('PATCH')
                                        <div class="flex gap-2">
                                            <select name="family_id" class="border rounded px-2 py-1 text-xs">
                                                <option value="">Unassigned</option>
                                                @foreach($familyOptions as $option)
                                                    <option value="{{ $option->id }}">
                                                        #{{ $option->id }} {{ $option->name ?: 'Unnamed family' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="px-2 py-1 rounded border border-gray-300 bg-white text-xs">Assign</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="border text-gray-500">No unassigned MCUs.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>
                {{ $unassignedMcus->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
