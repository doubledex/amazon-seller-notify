<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    {{ __("You're logged in!") }}
                </div>
            </div>
            <a href="{{ route('orders.index') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View Amazon Orders</a>
            <a href="{{ route('asins.europe') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View ASINs by European Marketplace</a>
            <a href="{{ route('metrics.index') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View Daily Sales & Ad Spend (UK/EU)</a>
            <a href="{{ route('ads.reports') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View Amazon Ads Queued Reports</a>
            <a href="{{ route('sqs_messages.index') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View SQS Messages</a>
            <a href="{{ route('notifications.index') }}" class="block mt-4 bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100">View Notifications</a>
        </div>
    </div>
</x-app-layout>
