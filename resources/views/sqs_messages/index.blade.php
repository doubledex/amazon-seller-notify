<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

<h1>SQS Messages</h1>

@if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

<form method="POST" action="{{ route('sqs_messages.fetch') }}" class="mb-4">
    @csrf
    <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">
        Fetch Latest
    </button>
</form>

<table class="min-w-full divide-y divide-gray-200 border border-gray-300">
    <thead class="bg-gray-50">
        <tr>
            {{-- <th>ID</th> --}}
            {{-- <th>Message ID</th> --}}
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Event Time</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notification Type</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">INFO</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Flagged</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
        </tr>
    </thead>
    <tbody class="bg-white divide-y divide-gray-200">
        @foreach ($messages as $message)
            @php($body = json_decode($message->body))
            @php($reportNode = $body->payload->reportProcessingFinishedNotification ?? null)
            @php($hasReportDocument = !empty($reportNode?->reportDocumentId) && !empty($reportNode?->reportType))

            <tr>
                <td class="px-6 py-4 whitespace-nowrap">
                    @if(!empty($message->EventTime))
                        {{ \Carbon\Carbon::parse($message->EventTime)->format('Y-m-d, H:i') }}
                    @else
                        n/a
                    @endif
                </td>
                <td class="px-6 py-4 whitespace-nowrap">{{ $message->NotificationType }}</td>
                <td class="px-6 py-4 whitespace-nowrap">
                    @isset($body->Payload->SKU)
                        {{ $body->Payload->ASIN }},
                        {{ $body->Payload->SKU }}
                    @endisset
                    @isset($body->payload->reportProcessingFinishedNotification)
                    {{-- ->reportProcessingFinishedNotification->reportType->) --}}
                    
                    {{ $body->payload->reportProcessingFinishedNotification->reportId ?? "n/a" }},
                    {{ $body->payload->reportProcessingFinishedNotification->reportType ?? "n/a" }},
                    {{ $body->payload->reportProcessingFinishedNotification->processingStatus ?? "n/a" }}
                    @endisset

                </td>
                <td class="px-6 py-4 whitespace-nowrap">{{ $message->flagged ? 'Yes' : 'No' }}</td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <a href="{{ route('sqs_messages.show', $message->id) }}">View</a>
                    @if($hasReportDocument)
                        |
                        <a href="{{ route('sqs_messages.report_download', $message->id) }}">Download report</a>
                    @endif
                    <form action="{{ route('sqs_messages.flag', $message->id) }}" method="POST" style="display: inline;">
                        @csrf
                        <button type="submit">Flag</button>
                    </form>
                    <form action="{{ route('sqs_messages.destroy', $message->id) }}" method="POST" style="display: inline;">
                        @csrf
                        @method('DELETE')
                        <button type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

{{ $messages->links() }}

</x-app-layout>
