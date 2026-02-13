<x-app-layout>
<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        {{ __('Your Amazon Marketplaces') }}
    </h2>
</x-slot>

<div class="py-4 px-4">
    <div class="bg-white dark:bg-gray-800 p-4 rounded mb-4">
        <h3 class="font-bold text-lg mb-3">Marketplaces You Participate In</h3>
        
        @if(count($marketplaces) > 0)
            <table border="1" cellpadding="8" cellspacing="0" class="w-full">
                <thead class="bg-gray-100 dark:bg-gray-700">
                    <tr>
                        <th>Marketplace ID</th>
                        <th>Country Code</th>
                        <th>Marketplace Name</th>
                        <th>Currency</th>
                        <th>Language</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($marketplaces as $marketplace)
                        <tr>
                            <td><code>{{ $marketplace['id'] }}</code></td>
                            <td><strong>{{ $marketplace['countryCode'] }}</strong></td>
                            <td>{{ $marketplace['name'] }}</td>
                            <td>{{ $marketplace['defaultCurrency'] }}</td>
                            <td>{{ $marketplace['defaultLanguage'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            
            <div class="mt-6 p-4 bg-blue-50 dark:bg-blue-900 rounded">
                <h4 class="font-bold mb-2">Marketplace IDs (stored in DB):</h4>
                <code class="block p-2 bg-gray-100 dark:bg-gray-800 rounded">
{{ implode(',', array_keys($marketplaces)) }}
                </code>
                <p class="mt-2 text-sm">No .env update required. IDs are fetched from the API and stored automatically.</p>
            </div>
        @else
            <p>No marketplaces found.</p>
        @endif
    </div>
    
    <div class="bg-gray-100 dark:bg-gray-900 p-4 rounded">
        <h3 class="font-bold mb-2">Note</h3>
        <p class="text-sm">If this list looks incomplete, refresh after ensuring your SP-API credentials have access to all participating marketplaces.</p>
    </div>
</div>
</x-app-layout>
