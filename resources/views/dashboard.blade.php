<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg mb-4">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h3 class="text-lg font-semibold mb-2">Marketplace Holidays (Last 7 / Next 30 days)</h3>
                    @php
                        $holidayItems = $holidayPanel['items'] ?? [];
                        $holidayErrors = $holidayPanel['errors'] ?? [];
                    @endphp
                    @if(empty($holidayItems))
                        <p class="text-sm text-gray-600 dark:text-gray-300">No holidays found in this window.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                                <thead class="bg-gray-100 dark:bg-gray-700">
                                <tr>
                                    <th class="text-left">Date</th>
                                    <th class="text-left">Country</th>
                                    <th class="text-left">Holiday</th>
                                    <th class="text-left">Type</th>
                                    <th class="text-left">Marketplaces</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($holidayItems as $item)
                                    <tr>
                                        <td>{{ $item['date'] ?? '' }}</td>
                                        <td>{{ $item['country'] ?? '' }}</td>
                                        <td>
                                            {{ $item['name'] ?? '' }}
                                            @if(!empty($item['local_name']) && ($item['local_name'] !== ($item['name'] ?? '')))
                                                <span class="text-gray-500">({{ $item['local_name'] }})</span>
                                            @endif
                                        </td>
                                        <td>{{ implode(', ', $item['types'] ?? []) }}</td>
                                        <td>
                                            @php
                                                $marketplaceNames = collect($item['marketplaces'] ?? [])->pluck('name')->filter()->values()->all();
                                            @endphp
                                            {{ implode(', ', $marketplaceNames) }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @if(!empty($holidayErrors))
                        <p class="text-xs text-amber-600 mt-2">
                            Some countries could not be refreshed right now. Showing partial results.
                        </p>
                    @endif
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    {{ __("You're logged in!") }}
                </div>
            </div>
            <a href="{{ route('orders.index') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View Amazon Orders</a>
            <a href="{{ route('asins.europe') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View ASINs by European Marketplace</a>
            <a href="{{ route('metrics.index') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View Daily Sales & Ad Spend (UK/EU/NA)</a>
            <a href="{{ route('ads.reports') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View Amazon Ads Queued Reports</a>
            <a href="{{ route('sqs_messages.index') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View SQS Messages</a>
            <a href="{{ route('notifications.index') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View Notifications</a>
        </div>
    </div>
</x-app-layout>
