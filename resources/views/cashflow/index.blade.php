<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Cashflow Projection') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-4">
                <form id="cashflow-filters" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                    <div>
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">View</label>
                        <select name="view" class="w-full border-gray-300 rounded-md shadow-sm">
                            <option value="day">Day</option>
                            <option value="week">Week</option>
                            <option value="today_timing">Today timing</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Date (UTC)</label>
                        <input name="date" type="date" class="w-full border-gray-300 rounded-md shadow-sm" value="{{ now()->utc()->toDateString() }}">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Marketplace ID</label>
                        <input name="marketplace_id" type="text" class="w-full border-gray-300 rounded-md shadow-sm" placeholder="Optional">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Region</label>
                        <select name="region" class="w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">All</option>
                            <option value="EU">EU</option>
                            <option value="NA">NA</option>
                            <option value="FE">FE</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Currency</label>
                        <input name="currency" type="text" maxlength="3" class="w-full border-gray-300 rounded-md shadow-sm" placeholder="GBP / USD / EUR">
                    </div>
                    <div>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md">Load projection</button>
                    </div>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-4">
                <div id="cashflow-meta" class="text-sm text-gray-600 dark:text-gray-300 mb-3"></div>
                <div id="cashflow-output" class="overflow-auto"></div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('cashflow-filters');
            const output = document.getElementById('cashflow-output');
            const meta = document.getElementById('cashflow-meta');
            const projectionUrl = @json(route('cashflow.projection'));

            async function load() {
                try {
                    const params = new URLSearchParams();
                    for (const [key, value] of new FormData(form).entries()) {
                        if ((value || '').toString().trim() !== '') {
                            params.set(key, value.toString().trim());
                        }
                    }

                    output.innerHTML = '<div class="text-sm text-gray-500">Loading…</div>';
                    meta.textContent = '';

                    const url = `${projectionUrl}?${params.toString()}`;
                    const response = await fetch(url, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });

                    const json = await response.json().catch(() => null);
                    if (!response.ok || !json) {
                        const message = json && json.error ? json.error : `Failed to load projection (HTTP ${response.status}).`;
                        output.innerHTML = `<div class="text-red-600 text-sm">${message}</div>`;
                        return;
                    }

                    meta.textContent = `Generated: ${json.generated_at_utc} | View: ${json.data.view}`;

                    if (json.data.view === 'today_timing') {
                        renderTable(json.data.timeline || []);
                    } else {
                        renderTable(json.data.buckets || []);
                    }
                } catch (error) {
                    output.innerHTML = '<div class="text-red-600 text-sm">Failed to load projection.</div>';
                }
            }

            function renderTable(rows) {
                if (!rows.length) {
                    output.innerHTML = '<div class="text-sm text-gray-500">No data found for selected filters.</div>';
                    return;
                }

                const columns = Object.keys(rows[0]);
                const thead = `<tr>${columns.map(c => `<th class="px-3 py-2 text-left border-b">${c}</th>`).join('')}</tr>`;
                const tbody = rows.map(row => `<tr>${columns.map(c => `<td class="px-3 py-2 border-b text-sm">${row[c] ?? ''}</td>`).join('')}</tr>`).join('');
                output.innerHTML = `<table class="min-w-full border-collapse">${thead}${tbody}</table>`;
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                load();
            });

            load();
        })();
    </script>
</x-app-layout>
