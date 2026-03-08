<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Inbound Discrepancy KPIs') }}
        </h2>
    </x-slot>

    <div class="py-4 px-4 space-y-4">
        <div class="bg-gray-50 dark:bg-gray-900 p-4 rounded">
            <form method="GET" action="{{ route('metrics.inbound_discrepancies') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="from" class="block text-sm font-medium mb-1">From</label>
                    <input type="date" id="from" name="from" value="{{ $from }}" class="border rounded px-2 py-1">
                </div>
                <div>
                    <label for="to" class="block text-sm font-medium mb-1">To</label>
                    <input type="date" id="to" name="to" value="{{ $to }}" class="border rounded px-2 py-1">
                </div>
                <button type="submit" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Apply</button>
                <a href="{{ route('metrics.inbound_discrepancies') }}" class="px-3 py-2 rounded-md border text-sm border-gray-300 bg-white text-gray-700">Clear</a>
                <a href="{{ route('metrics.inbound_discrepancies.csv', ['from' => $from, 'to' => $to]) }}" class="px-3 py-2 rounded-md border text-sm border-indigo-200 bg-indigo-50 text-indigo-700">Export CSV</a>
            </form>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm overflow-x-auto">
            <h3 class="font-semibold mb-2">Daily aggregate KPIs</h3>
            <table class="w-full text-sm" border="1" cellpadding="6" cellspacing="0">
                <thead class="bg-gray-100 dark:bg-gray-700">
                    <tr>
                        <th class="text-left">Date</th>
                        <th class="text-right">Discrepancy Rate / 1,000 Units</th>
                        <th class="text-right">Claim Submitted Before Deadline %</th>
                        <th class="text-right">Claim Win Rate %</th>
                        <th class="text-right">Avg Reimbursement Cycle (Days)</th>
                        <th class="text-right">Recovered vs Disputed %</th>
                        <th class="text-right">Aged Open SLA Buckets (Missed / 0-7 / 8-14 / 15+ / No Deadline)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($daily as $row)
                        <tr>
                            <td>{{ $row->metric_date }}</td>
                            <td class="text-right">{{ number_format((float) $row->discrepancy_rate_per_1000, 2) }}</td>
                            <td class="text-right">{{ $row->claims_submitted_before_deadline_percent !== null ? number_format((float) $row->claims_submitted_before_deadline_percent, 2).'%' : 'N/A' }}</td>
                            <td class="text-right">{{ $row->claim_win_rate_percent !== null ? number_format((float) $row->claim_win_rate_percent, 2).'%' : 'N/A' }}</td>
                            <td class="text-right">{{ $row->avg_reimbursement_cycle_days !== null ? number_format((float) $row->avg_reimbursement_cycle_days, 2) : 'N/A' }}</td>
                            <td class="text-right">{{ $row->recovered_vs_disputed_percent !== null ? number_format((float) $row->recovered_vs_disputed_percent, 2).'%' : 'N/A' }}</td>
                            <td class="text-right">{{ $row->aged_open_missed }} / {{ $row->aged_open_due_0_7_days }} / {{ $row->aged_open_due_8_14_days }} / {{ $row->aged_open_due_15_plus_days }} / {{ $row->aged_open_no_deadline }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-gray-500">No rows for selected date range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="bg-white dark:bg-gray-800 p-4 rounded shadow-sm overflow-x-auto">
            <h3 class="font-semibold mb-2">Split-carton anomaly rate (FC / SKU / carrier)</h3>
            <table class="w-full text-sm" border="1" cellpadding="6" cellspacing="0">
                <thead class="bg-gray-100 dark:bg-gray-700">
                    <tr>
                        <th class="text-left">Date</th>
                        <th class="text-left">FC</th>
                        <th class="text-left">SKU</th>
                        <th class="text-left">Carrier</th>
                        <th class="text-right">Split-carton anomaly rate %</th>
                        <th class="text-right">Split-carton count</th>
                        <th class="text-right">Discrepancy count</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($splitRows as $row)
                        <tr>
                            <td>{{ $row->metric_date }}</td>
                            <td>{{ $row->fulfillment_center_id }}</td>
                            <td>{{ $row->sku }}</td>
                            <td>{{ $row->carrier_name }}</td>
                            <td class="text-right">{{ number_format((float) $row->split_carton_anomaly_rate_percent, 2) }}%</td>
                            <td class="text-right">{{ number_format((int) $row->split_carton_count) }}</td>
                            <td class="text-right">{{ number_format((int) $row->discrepancy_count) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-gray-500">No split-carton rows for selected date range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
