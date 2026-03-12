<x-app-layout>
    @php
        $debugApiPayload = $debugApiPayload ?? null;
        $debugApiStatus = $debugApiStatus ?? null;
    @endphp
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
            @if ($debugApiStatus)
                <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded">
                    {{ $debugApiStatus }}
                </div>
            @endif

            <style>
                .dev-json-tree {
                    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
                    font-size: 14px;
                    line-height: 1.65;
                    background: #111827;
                    color: #f3f4f6;
                    border: 1px solid #374151;
                    border-radius: 8px;
                    padding: 12px;
                    margin-top: 8px;
                    overflow-x: auto;
                }
                .dev-json-tree details > div {
                    border-left: 1px solid #374151;
                    margin-left: 6px;
                }
                .dev-json-key { color: #93c5fd; }
                .dev-json-string { color: #86efac; }
                .dev-json-number { color: #fca5a5; }
                .dev-json-boolean { color: #fcd34d; }
                .dev-json-null { color: #d1d5db; font-style: italic; }
                .dev-json-clickable { cursor: pointer; text-decoration: underline dotted; text-underline-offset: 2px; }
                .dev-json-copy-status { margin-top: 8px; font-size: 12px; color: #9ca3af; min-height: 18px; }
            </style>

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

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                <h3 class="font-semibold mb-2">Raw API Payload</h3>
                @php($itemsPayload = $discrepancy->shipment?->api_items_payload ?? [])
                <div class="text-sm mb-2">
                    <strong>Source API:</strong>
                    {{ $discrepancy->shipment?->api_source_version ?: 'n/a' }}
                </div>
                <div class="space-y-3">
                    <details>
                        <summary class="cursor-pointer text-sm font-medium">Shipment payload JSON</summary>
                        <pre class="text-xs whitespace-pre-wrap mt-2">{{ json_encode($discrepancy->shipment?->api_shipment_payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                    <details>
                        <summary class="cursor-pointer text-sm font-medium">Items payload JSON</summary>
                        <pre class="text-xs whitespace-pre-wrap mt-2">{{ json_encode($discrepancy->shipment?->api_items_payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                    <details>
                        <summary class="cursor-pointer text-sm font-medium">listShipmentItems response JSON</summary>
                        <pre class="text-xs whitespace-pre-wrap mt-2">{{ json_encode(data_get($itemsPayload, 'list_shipment_items.pages', []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                    <details>
                        <summary class="cursor-pointer text-sm font-medium">listShipmentBoxes response JSON</summary>
                        <pre class="text-xs whitespace-pre-wrap mt-2">{{ json_encode(data_get($itemsPayload, 'list_shipment_boxes.pages', []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                    <details>
                        <summary class="cursor-pointer text-sm font-medium">listShipmentPallets response JSON</summary>
                        <pre class="text-xs whitespace-pre-wrap mt-2">{{ json_encode(data_get($itemsPayload, 'list_shipment_pallets.pages', []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <form method="POST" action="{{ route('inbound.discrepancies.debug_fetch', $discrepancy->id) }}">
                        @csrf
                        <button type="submit" class="px-3 py-2 rounded-md border text-sm border-amber-300 bg-amber-50 text-amber-800">
                            Debug Call API
                        </button>
                    </form>
                    <form method="POST" action="{{ route('inbound.discrepancies.build_evidence', $discrepancy->id) }}">
                        @csrf
                        <button type="submit" class="px-3 py-2 rounded-md border text-sm border-blue-300 bg-blue-50 text-blue-700">
                            Build/Refresh claim dossier
                        </button>
                    </form>
                    <a href="{{ route('inbound.discrepancies.index') }}" class="text-sm underline">Back to queue</a>
                </div>
            </div>

            @if (is_array($debugApiPayload))
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 overflow-x-auto">
                    <h3 class="font-semibold mb-2">Live API Debug Payload</h3>
                    <div class="text-sm mb-2">
                        <strong>Source API:</strong> {{ data_get($debugApiPayload, 'source_api', 'n/a') }}
                    </div>
                    <div class="space-y-3">
                        <details open>
                            <summary class="cursor-pointer text-sm font-medium">Live API payload JSON</summary>
                            <div id="dev-json-tree-live-inbound" class="dev-json-tree"></div>
                            <div id="dev-json-copy-status-live-inbound" class="dev-json-copy-status"></div>
                            <script type="application/json" id="dev-json-tree-live-inbound-data">@json($debugApiPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
                        </details>
                    </div>
                </div>
            @endif

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
    <script>
        (function () {
            function showCopyStatus(statusEl, text, isError) {
                statusEl.textContent = text;
                statusEl.style.color = isError ? '#fca5a5' : '#9ca3af';
                if (statusEl._timer) {
                    clearTimeout(statusEl._timer);
                }
                statusEl._timer = setTimeout(function () {
                    statusEl.textContent = '';
                }, 1400);
            }

            function pathSegmentForObjectKey(key) {
                if (/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(key)) {
                    return '.' + key;
                }
                return "['" + key.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "']";
            }

            function stringifyValue(value) {
                if (typeof value === 'string') {
                    return '"' + value + '"';
                }
                return String(value);
            }

            function createValueNode(value, path, statusEl) {
                const span = document.createElement('span');
                if (value === null) {
                    span.className = 'dev-json-null';
                    span.textContent = 'null';
                } else if (typeof value === 'string') {
                    span.className = 'dev-json-string';
                    span.textContent = '"' + value + '"';
                } else if (typeof value === 'number') {
                    span.className = 'dev-json-number';
                    span.textContent = String(value);
                } else if (typeof value === 'boolean') {
                    span.className = 'dev-json-boolean';
                    span.textContent = value ? 'true' : 'false';
                } else {
                    span.textContent = String(value);
                }

                span.classList.add('dev-json-clickable');
                span.title = 'Click to copy path and value';
                span.addEventListener('click', async function () {
                    const text = 'path: ' + path + '\nvalue: ' + stringifyValue(value);
                    try {
                        await navigator.clipboard.writeText(text);
                        showCopyStatus(statusEl, 'Copied ' + path, false);
                    } catch (e) {
                        showCopyStatus(statusEl, 'Copy failed for ' + path, true);
                    }
                });
                return span;
            }

            function renderTree(value, key, path, statusEl) {
                const wrapper = document.createElement('div');
                if (Array.isArray(value)) {
                    const details = document.createElement('details');
                    details.open = key === undefined;
                    const summary = document.createElement('summary');
                    summary.className = 'cursor-pointer';
                    if (key !== undefined) {
                        const keySpan = document.createElement('span');
                        keySpan.className = 'dev-json-key';
                        keySpan.textContent = '"' + key + '"';
                        summary.appendChild(keySpan);
                        summary.appendChild(document.createTextNode(': '));
                    }
                    summary.appendChild(document.createTextNode('[' + value.length + ']'));
                    details.appendChild(summary);

                    value.forEach(function (item, idx) {
                        const row = document.createElement('div');
                        row.className = 'pl-5';
                        row.appendChild(renderTree(item, idx, path + '[' + idx + ']', statusEl));
                        details.appendChild(row);
                    });
                    wrapper.appendChild(details);
                    return wrapper;
                }
                if (value && typeof value === 'object') {
                    const keys = Object.keys(value);
                    const details = document.createElement('details');
                    details.open = key === undefined;
                    const summary = document.createElement('summary');
                    summary.className = 'cursor-pointer';
                    if (key !== undefined) {
                        const keySpan = document.createElement('span');
                        keySpan.className = 'dev-json-key';
                        keySpan.textContent = '"' + key + '"';
                        summary.appendChild(keySpan);
                        summary.appendChild(document.createTextNode(': '));
                    }
                    summary.appendChild(document.createTextNode('{' + keys.length + '}'));
                    details.appendChild(summary);

                    keys.forEach(function (childKey) {
                        const row = document.createElement('div');
                        row.className = 'pl-5';
                        row.appendChild(renderTree(value[childKey], childKey, path + pathSegmentForObjectKey(childKey), statusEl));
                        details.appendChild(row);
                    });
                    wrapper.appendChild(details);
                    return wrapper;
                }

                const line = document.createElement('div');
                if (key !== undefined) {
                    const keySpan = document.createElement('span');
                    keySpan.className = 'dev-json-key';
                    keySpan.textContent = '"' + key + '"';
                    line.appendChild(keySpan);
                    line.appendChild(document.createTextNode(': '));
                }
                line.appendChild(createValueNode(value, path, statusEl));
                wrapper.appendChild(line);
                return wrapper;
            }

            function mountTree(treeContainerId, dataScriptId, statusId) {
                const container = document.getElementById(treeContainerId);
                const dataScript = document.getElementById(dataScriptId);
                const statusEl = document.getElementById(statusId);
                if (!container || !dataScript || !statusEl) {
                    return;
                }
                try {
                    const payload = JSON.parse(dataScript.textContent || 'null');
                    container.innerHTML = '';
                    container.appendChild(renderTree(payload, undefined, '$', statusEl));
                } catch (e) {
                    container.textContent = 'Failed to parse JSON payload.';
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () {
                    mountTree('dev-json-tree-live-inbound', 'dev-json-tree-live-inbound-data', 'dev-json-copy-status-live-inbound');
                });
            } else {
                mountTree('dev-json-tree-live-inbound', 'dev-json-tree-live-inbound-data', 'dev-json-copy-status-live-inbound');
            }
        })();
    </script>
</x-app-layout>
