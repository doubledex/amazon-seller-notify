<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Inbound Discrepancies
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                <form method="GET" action="{{ route('inbound.discrepancies.index') }}" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-sm font-medium mb-1" for="status">Status</label>
                        <select id="status" name="status" class="border rounded px-2 py-1">
                            <option value="open" {{ $status === 'open' ? 'selected' : '' }}>Open</option>
                            <option value="resolved" {{ $status === 'resolved' ? 'selected' : '' }}>Resolved</option>
                            <option value="all" {{ $status === 'all' ? 'selected' : '' }}>All</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" for="severity">Severity</label>
                        <input id="severity" name="severity" type="text" value="{{ $severity }}" placeholder="critical" class="border rounded px-2 py-1 w-32">
                    </div>
                    <div class="flex items-center gap-2 pb-1">
                        <input id="split_only" name="split_only" type="checkbox" value="1" {{ $splitOnly ? 'checked' : '' }}>
                        <label for="split_only" class="text-sm">Split carton only</label>
                    </div>
                    <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Apply</button>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                <form method="POST" action="{{ route('inbound.discrepancies.evaluate_sla') }}">
                    @csrf
                    <button type="submit" class="px-3 py-2 rounded-md border text-sm border-blue-300 bg-blue-50 text-blue-700">
                        Evaluate SLA now
                    </button>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                    <thead>
                    <tr>
                        <th class="text-left">ID</th>
                        <th class="text-left">Shipment</th>
                        <th class="text-left">SKU/FNSKU</th>
                        <th class="text-right">Expected</th>
                        <th class="text-right">Received</th>
                        <th class="text-right">Delta</th>
                        <th class="text-left">Split</th>
                        <th class="text-left">Severity</th>
                        <th class="text-left">Deadline</th>
                        <th class="text-right">Value</th>
                        <th class="text-left">Claim Cases</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>
                                <a href="{{ route('inbound.discrepancies.show', $row->id) }}" class="text-blue-700 underline">
                                    #{{ $row->id }}
                                </a>
                            </td>
                            <td>{{ $row->shipment_id }}</td>
                            <td>
                                <div>{{ $row->sku ?: 'n/a' }}</div>
                                <div class="text-xs text-gray-500">{{ $row->fnsku ?: 'n/a' }}</div>
                            </td>
                            <td class="text-right">{{ number_format((int) $row->expected_units) }}</td>
                            <td class="text-right">{{ number_format((int) $row->received_units) }}</td>
                            <td class="text-right">{{ number_format((int) $row->delta) }}</td>
                            <td>{{ $row->split_carton ? 'Yes' : 'No' }}</td>
                            <td>{{ $row->severity ?: 'n/a' }}</td>
                            <td>{{ optional($row->challenge_deadline_at)->format('Y-m-d H:i') ?? 'n/a' }}</td>
                            <td class="text-right">{{ number_format((float) $row->value_impact, 2) }}</td>
                            <td>{{ (int) $row->claim_cases_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="11">No discrepancies found for this filter.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                <div class="mt-3">
                    {{ $rows->links() }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
