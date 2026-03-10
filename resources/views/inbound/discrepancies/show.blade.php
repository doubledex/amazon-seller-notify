<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Inbound Discrepancy #{{ $discrepancy->id }}
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
                <div class="flex flex-wrap gap-6 text-sm">
                    <div><strong>Shipment:</strong> {{ $discrepancy->shipment_id }}</div>
                    <div><strong>SKU:</strong> {{ $discrepancy->sku ?: 'n/a' }}</div>
                    <div><strong>FNSKU:</strong> {{ $discrepancy->fnsku ?: 'n/a' }}</div>
                    <div><strong>Status:</strong> {{ $discrepancy->status }}</div>
                    <div><strong>Severity:</strong> {{ $discrepancy->severity }}</div>
                    <div><strong>Split carton:</strong> {{ $discrepancy->split_carton ? 'Yes' : 'No' }}</div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                <h3 class="font-semibold mb-2">Quantities</h3>
                <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                    <tbody>
                        <tr><th class="text-left">Expected Units</th><td>{{ number_format((int) $discrepancy->expected_units) }}</td></tr>
                        <tr><th class="text-left">Received Units</th><td>{{ number_format((int) $discrepancy->received_units) }}</td></tr>
                        <tr><th class="text-left">Delta Units</th><td>{{ number_format((int) $discrepancy->delta) }}</td></tr>
                        <tr><th class="text-left">Units Per Carton</th><td>{{ number_format((int) $discrepancy->units_per_carton) }}</td></tr>
                        <tr><th class="text-left">Carton Count</th><td>{{ number_format((int) $discrepancy->carton_count) }}</td></tr>
                        <tr><th class="text-left">Carton Equivalent Delta</th><td>{{ number_format((float) $discrepancy->carton_equivalent_delta, 4) }}</td></tr>
                        <tr><th class="text-left">Challenge Deadline</th><td>{{ optional($discrepancy->challenge_deadline_at)->format('Y-m-d H:i:s') ?? 'n/a' }}</td></tr>
                        <tr><th class="text-left">Value Impact</th><td>{{ number_format((float) $discrepancy->value_impact, 2) }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                <form method="POST" action="{{ route('inbound.discrepancies.build_evidence', $discrepancy->id) }}">
                    @csrf
                    <button type="submit" class="px-3 py-2 rounded-md border text-sm border-blue-300 bg-blue-50 text-blue-700">
                        Build/Refresh claim dossier
                    </button>
                    <a href="{{ route('inbound.discrepancies.index') }}" class="ml-2 text-sm underline">Back to queue</a>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                <h3 class="font-semibold mb-2">Claim Cases</h3>
                <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                    <thead>
                    <tr>
                        <th class="text-left">Case ID</th>
                        <th class="text-left">Outcome</th>
                        <th class="text-left">Submitted</th>
                        <th class="text-left">Deadline</th>
                        <th class="text-left">Evidence Complete</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($discrepancy->claimCases as $case)
                        <tr>
                            <td>#{{ $case->id }}</td>
                            <td>{{ $case->outcome ?: 'pending' }}</td>
                            <td>{{ optional($case->submitted_at)->format('Y-m-d H:i:s') ?? 'n/a' }}</td>
                            <td>{{ optional($case->challenge_deadline_at)->format('Y-m-d H:i:s') ?? 'n/a' }}</td>
                            <td>{{ data_get($case->evidence_validation, 'complete') ? 'Yes' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No claim cases yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                <h3 class="font-semibold mb-2">SLA Transitions</h3>
                <table class="w-full text-sm border-collapse" border="1" cellpadding="6" cellspacing="0">
                    <thead>
                    <tr>
                        <th class="text-left">From</th>
                        <th class="text-left">To</th>
                        <th class="text-left">When</th>
                        <th class="text-left">Hours Remaining</th>
                        <th class="text-left">Deadline (Transition)</th>
                        <th class="text-left">Marketplace</th>
                        <th class="text-left">Program</th>
                        <th class="text-left">Metadata</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($discrepancy->slaTransitions->sortByDesc('transitioned_at') as $transition)
                        <tr>
                            <td>{{ $transition->from_state ?: 'n/a' }}</td>
                            <td>{{ $transition->to_state }}</td>
                            <td>{{ optional($transition->transitioned_at)->format('Y-m-d H:i:s') ?? 'n/a' }}</td>
                            <td>{{ data_get($transition->metadata, 'hours_remaining', 'n/a') }}</td>
                            <td>{{ data_get($transition->metadata, 'deadline_at', 'n/a') }}</td>
                            <td>{{ data_get($transition->metadata, 'marketplace_id', 'n/a') }}</td>
                            <td>{{ data_get($transition->metadata, 'program', 'n/a') }}</td>
                            <td>
                                <pre class="text-xs whitespace-pre-wrap">{{ json_encode($transition->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No SLA transitions recorded yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
