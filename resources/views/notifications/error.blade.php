<!-- filepath: /c:/Users/DavidFlood/OneDrive/Coding/DF-Laravel-app/resources/views/notifications/error.blade.php -->
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Error') }}
        </h2>
    </x-slot>

    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
                <h1 class="text-2xl font-bold mb-4">Error</h1>
                <p>{{ $error }}</p>
                <p>{{ $msg }}</p>
                <a href="{{ route('notifications.index') }}" class="text-blue-500 hover:underline">Back to Notifications</a>
            </div>
        </div>
    </div>
</x-app-layout>