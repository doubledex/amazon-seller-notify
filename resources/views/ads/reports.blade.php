<x-app-layout>
<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        {{ __('Amazon Ads Queued Reports') }}
    </h2>
</x-slot>

<div class="py-4 px-4">
    @if (session('status'))
        <div class="mb-4 rounded border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800">
            {!! nl2br(e(session('status'))) !!}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <div class="bg-gray-50 dark:bg-gray-900 p-4 mb-4 rounded">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <form method="GET" action="{{ route('ads.reports') }}" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="scope" class="block text-sm font-medium mb-1">Scope</label>
                <select id="scope" name="scope" class="border rounded px-2 py-1">
                    <option value="outstanding" {{ $scope === 'outstanding' ? 'selected' : '' }}>Outstanding Only</option>
                    <option value="all" {{ $scope === 'all' ? 'selected' : '' }}>All</option>
                </select>
            </div>
            <div>
                <label for="status" class="block text-sm font-medium mb-1">Status</label>
                <input type="text" id="status" name="status" value="{{ $status }}" placeholder="e.g. PENDING" class="border rounded px-2 py-1">
            </div>
            <div>
                <label for="profile_id" class="block text-sm font-medium mb-1">Profile ID</label>
                <input type="text" id="profile_id" name="profile_id" value="{{ $profileId }}" class="border rounded px-2 py-1">
            </div>
            <div>
                <label for="ad_product" class="block text-sm font-medium mb-1">Ad Product</label>
                <input type="text" id="ad_product" name="ad_product" value="{{ $adProduct }}" placeholder="SPONSORED_PRODUCTS" class="border rounded px-2 py-1">
            </div>
            <div>
                <label for="http_status" class="block text-sm font-medium mb-1">Last HTTP Status</label>
                <input type="text" id="http_status" name="http_status" value="{{ $httpStatus ?? '' }}" placeholder="e.g. 429" class="border rounded px-2 py-1">
            </div>
            <div class="pb-1">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="stuck_only" value="1" {{ !empty($stuckOnly) ? 'checked' : '' }}>
                    <span class="text-sm">Stuck only</span>
                </label>
            </div>
            <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Apply</button>
            <a href="{{ route('ads.reports') }}" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Clear</a>
            </form>
            <form method="POST" action="{{ route('ads.reports.poll') }}">
                @csrf
                <button type="submit" class="px-3 py-2 rounded-md border text-sm border-blue-300 bg-blue-50 text-blue-700">
                    Poll now
                </button>
            </form>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm">
        <div class="overflow-x-auto">
            <table border="1" cellpadding="8" cellspacing="0" class="w-full">
                <thead class="bg-gray-100 dark:bg-gray-700">
                    <tr>
                        <th>Report ID</th>
                        <th>Status</th>
                        <th>Profile (Country)</th>
                        <th>Region</th>
                        <th>Product</th>
                        <th>Date Range</th>
                        <th>Requested</th>
                        <th>Last Checked</th>
                        <th>Wait (mins)</th>
                        <th>Checks</th>
                        <th>Retries</th>
                        <th>Next Check</th>
                        <th>HTTP</th>
                        <th>Request ID</th>
                        <th>Stuck Alert</th>
                        <th>Processed</th>
                        <th>Rows</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        @php
                            $waitSeconds = $row->processed_at
                                ? (int) ($row->waited_seconds ?? 0)
                                : ($row->requested_at ? $row->requested_at->diffInSeconds(now()) : (int) ($row->waited_seconds ?? 0));
                        @endphp
                        <tr>
                            <td><code>{{ $row->report_id }}</code></td>
                            <td>{{ $row->status }}</td>
                            <td><code>{{ $row->profile_id }}</code> ({{ $row->country_code ?? 'N/A' }})</td>
                            <td>{{ $row->region ?? 'N/A' }}</td>
                            <td>{{ $row->ad_product }}</td>
                            <td>{{ optional($row->start_date)->format('Y-m-d') }} to {{ optional($row->end_date)->format('Y-m-d') }}</td>
                            <td>{{ optional($row->requested_at)->format('Y-m-d H:i:s') ?? 'N/A' }}</td>
                            <td>{{ optional($row->last_checked_at)->format('Y-m-d H:i:s') ?? 'N/A' }}</td>
                            <td>{{ number_format($waitSeconds / 60, 1) }}</td>
                            <td>{{ $row->check_attempts }}</td>
                            <td>{{ $row->retry_count ?? 0 }}</td>
                            <td>{{ optional($row->next_check_at)->format('Y-m-d H:i:s') ?? 'N/A' }}</td>
                            <td>{{ $row->last_http_status ?? 'N/A' }}</td>
                            <td><code>{{ $row->last_request_id ?? 'N/A' }}</code></td>
                            <td>{{ optional($row->stuck_alerted_at)->format('Y-m-d H:i:s') ?? 'No' }}</td>
                            <td>{{ optional($row->processed_at)->format('Y-m-d H:i:s') ?? 'No' }}</td>
                            <td>{{ $row->processed_rows }}</td>
                        </tr>
                        @if(!empty($row->failure_reason) || !empty($row->processing_error))
                            <tr>
                                <td colspan="17" class="text-sm">
                                    @if(!empty($row->failure_reason))
                                        Failure: {{ $row->failure_reason }}
                                    @endif
                                    @if(!empty($row->processing_error))
                                        {{ !empty($row->failure_reason) ? ' | ' : '' }}Processing Error: {{ $row->processing_error }}
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="17">No queued reports found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $rows->links() }}
        </div>
    </div>
</div>
</x-app-layout>
