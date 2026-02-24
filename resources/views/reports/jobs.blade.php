<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Report Jobs') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif
            @if (session('error'))
                <div class="rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
                    {{ session('error') }}
                </div>
            @endif
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                <form method="GET" action="{{ route('reports.jobs') }}" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="scope" class="block text-sm font-medium mb-1">Scope</label>
                        <select id="scope" name="scope" class="border rounded px-2 py-1">
                            <option value="outstanding" {{ $scope === 'outstanding' ? 'selected' : '' }}>Outstanding Only</option>
                            <option value="all" {{ $scope === 'all' ? 'selected' : '' }}>All</option>
                        </select>
                    </div>
                    <div>
                        <label for="provider" class="block text-sm font-medium mb-1">Provider</label>
                        <input id="provider" name="provider" value="{{ $provider }}" class="border rounded px-2 py-1" placeholder="sp_api_seller">
                    </div>
                    <div>
                        <label for="processor" class="block text-sm font-medium mb-1">Processor</label>
                        <input id="processor" name="processor" value="{{ $processor }}" class="border rounded px-2 py-1" placeholder="marketplace_listings">
                    </div>
                    <div>
                        <label for="status" class="block text-sm font-medium mb-1">Status</label>
                        <input id="status" name="status" value="{{ $status }}" class="border rounded px-2 py-1" placeholder="processing">
                    </div>
                    <div>
                        <label for="region" class="block text-sm font-medium mb-1">Region</label>
                        <input id="region" name="region" value="{{ $region }}" class="border rounded px-2 py-1" placeholder="EU">
                    </div>
                    <div>
                        <label for="marketplace" class="block text-sm font-medium mb-1">Marketplace</label>
                        <input id="marketplace" name="marketplace" value="{{ $marketplace }}" class="border rounded px-2 py-1" placeholder="A1PA6795UKMFR9">
                    </div>
                    <div>
                        <label for="report_type" class="block text-sm font-medium mb-1">Report Type</label>
                        <input id="report_type" name="report_type" value="{{ $reportType }}" class="border rounded px-2 py-1" placeholder="GET_MERCHANT_LISTINGS_ALL_DATA">
                    </div>
                    <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Apply</button>
                    <a href="{{ route('reports.jobs') }}" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Clear</a>
                </form>
                <form method="POST" action="{{ route('reports.jobs.poll') }}" class="flex items-end gap-2">
                    @csrf
                    <input type="hidden" name="scope" value="{{ $scope }}">
                    <input type="hidden" name="provider" value="{{ $provider !== '' ? $provider : 'sp_api_seller' }}">
                    <input type="hidden" name="processor" value="{{ $processor }}">
                    <input type="hidden" name="status" value="{{ $status }}">
                    <input type="hidden" name="region" value="{{ $region }}">
                    <input type="hidden" name="marketplace" value="{{ $marketplace }}">
                    <input type="hidden" name="report_type" value="{{ $reportType }}">
                    <div>
                        <label for="limit" class="block text-sm font-medium mb-1">Poll Limit</label>
                        <input id="limit" name="limit" type="number" min="1" value="100" class="border rounded px-2 py-1 w-24">
                    </div>
                    <button type="submit" class="px-3 py-2 rounded-md border text-sm border-blue-300 bg-blue-50 text-blue-700">
                        Poll now
                    </button>
                </form>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                <h3 class="font-semibold mb-2">Status Summary</h3>
                <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                    <thead>
                    <tr>
                        <th class="text-left">Status</th>
                        <th class="text-right">Count</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($statusSummary as $row)
                        <tr>
                            <td>{{ $row->status }}</td>
                            <td class="text-right">{{ number_format((int) $row->total) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2">No report jobs found.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                    <thead>
                    <tr>
                        <th class="text-left">ID</th>
                        <th class="text-left">Provider</th>
                        <th class="text-left">Processor</th>
                        <th class="text-left">Region</th>
                        <th class="text-left">Marketplace</th>
                        <th class="text-left">Report Type</th>
                        <th class="text-left">Status</th>
                        <th class="text-left">External Report</th>
                        <th class="text-left">External Doc</th>
                        <th class="text-right">Attempts</th>
                        <th class="text-right">Rows Parsed</th>
                        <th class="text-right">Rows Ingested</th>
                        <th class="text-left">Next Poll</th>
                        <th class="text-left">Completed</th>
                        <th class="text-left">Last Error</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ $row->id }}</td>
                            <td>{{ $row->provider }}</td>
                            <td>{{ $row->processor }}</td>
                            <td>{{ $row->region }}</td>
                            <td>{{ $row->marketplace_id }}</td>
                            <td>{{ $row->report_type }}</td>
                            <td>{{ $row->status }}</td>
                            <td><code>{{ $row->external_report_id }}</code></td>
                            <td><code>{{ $row->external_document_id }}</code></td>
                            <td class="text-right">{{ (int) $row->attempt_count }}</td>
                            <td class="text-right">{{ (int) $row->rows_parsed }}</td>
                            <td class="text-right">{{ (int) $row->rows_ingested }}</td>
                            <td>{{ optional($row->next_poll_at)->format('Y-m-d H:i:s') }}</td>
                            <td>{{ optional($row->completed_at)->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $row->last_error }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="15">No matching report jobs.</td></tr>
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
