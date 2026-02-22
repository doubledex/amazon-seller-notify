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
            <div class="mb-4 p-3 rounded border border-red-200 bg-red-50 text-red-800 text-sm">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm mb-4">
            <h3 class="text-sm font-semibold mb-3">Product Details</h3>
            <form method="POST" action="{{ route('products.update', $product) }}" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                @csrf
                @method('PATCH')
                <div class="md:col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">Name</label>
                    <input name="name" required class="w-full border rounded px-2 py-1" value="{{ old('name', $product->name) }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Status</label>
                    <select name="status" class="w-full border rounded px-2 py-1">
                        @foreach(['active','inactive','draft'] as $status)
                            <option value="{{ $status }}" {{ old('status', $product->status) === $status ? 'selected' : '' }}>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs text-gray-600 mb-1">Notes</label>
                    <textarea name="notes" rows="3" class="w-full border rounded px-2 py-1">{{ old('notes', $product->notes) }}</textarea>
                </div>
                <div>
                    <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Save</button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm mb-4">
            <h3 class="text-sm font-semibold mb-3">Add Identifier</h3>
            <form method="POST" action="{{ route('products.identifiers.store', $product) }}" class="grid grid-cols-1 md:grid-cols-6 gap-3">
                @csrf
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Type</label>
                    <select name="identifier_type" class="w-full border rounded px-2 py-1">
                        @foreach(['seller_sku','asin','fnsku','upc','ean','other'] as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs text-gray-600 mb-1">Value</label>
                    <input name="identifier_value" required class="w-full border rounded px-2 py-1" value="{{ old('identifier_value') }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Marketplace ID</label>
                    <input name="marketplace_id" class="w-full border rounded px-2 py-1" value="{{ old('marketplace_id') }}">
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Region</label>
                    <select name="region" class="w-full border rounded px-2 py-1">
                        <option value="">-</option>
                        @foreach(['EU','NA','FE'] as $region)
                            <option value="{{ $region }}">{{ $region }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <label class="text-xs"><input type="checkbox" name="is_primary" value="1"> Primary</label>
                    <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Add</button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm overflow-x-auto">
            <h3 class="text-sm font-semibold mb-3">Identifiers</h3>
            <table class="w-full text-sm" border="1" cellpadding="6" cellspacing="0">
                <thead class="bg-gray-100 dark:bg-gray-700">
                    <tr>
                        <th class="text-left">Type</th>
                        <th class="text-left">Value</th>
                        <th class="text-left">Marketplace</th>
                        <th class="text-left">Region</th>
                        <th class="text-left">Primary</th>
                        <th class="text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($product->identifiers as $identifier)
                        <tr>
                            <td>{{ $identifier->identifier_type }}</td>
                            <td>{{ $identifier->identifier_value }}</td>
                            <td>{{ $identifier->marketplace_id ?: '-' }}</td>
                            <td>{{ $identifier->region ?: '-' }}</td>
                            <td>{{ $identifier->is_primary ? 'yes' : 'no' }}</td>
                            <td>
                                <form method="POST" action="{{ route('products.identifiers.destroy', $identifier) }}" onsubmit="return confirm('Delete this identifier?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-2 py-1 rounded border text-xs border-gray-300 bg-white text-gray-700">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No identifiers yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
