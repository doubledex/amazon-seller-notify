<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Cashflow Projection') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-4">
                <form id="cashflow-filters" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[140px]">
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">View</label>
                        <select name="view" class="w-full border-gray-300 rounded-md shadow-sm">
                            <option value="outstanding">Outstanding</option>
                            <option value="day">Day</option>
                            <option value="week">Week</option>
                            <option value="today_timing">Today timing</option>
                        </select>
                    </div>
                    <div class="min-w-[150px]">
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Date (UTC)</label>
                        <input name="date" type="date" class="w-full border-gray-300 rounded-md shadow-sm" value="{{ now()->utc()->toDateString() }}">
                    </div>
                    <div class="min-w-[170px]">
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">From (Maturity UTC)</label>
                        <input name="from" type="date" class="w-full border-gray-300 rounded-md shadow-sm" value="{{ now()->utc()->toDateString() }}">
                    </div>
                    <div class="min-w-[170px]">
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">To (Maturity UTC)</label>
                        <input name="to" type="date" class="w-full border-gray-300 rounded-md shadow-sm" value="{{ now()->utc()->addWeek()->toDateString() }}">
                    </div>
                    <div class="min-w-[320px]">
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Marketplace</label>
                        <select name="marketplace_id" class="w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">All marketplaces</option>
                            @foreach(($marketplaces ?? []) as $marketplace)
                                <option value="{{ $marketplace['id'] }}">
                                    {{ trim(($marketplace['flag'] ?? '') . ' ' . ($marketplace['country_code'] ?? '')) }} - {{ $marketplace['name'] ?? 'Unknown' }} ({{ $marketplace['id'] }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-[120px]">
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Region</label>
                        <select name="region" class="w-full border-gray-300 rounded-md shadow-sm">
                            <option value="">All</option>
                            <option value="EU">EU</option>
                            <option value="NA">NA</option>
                            <option value="FE">FE</option>
                        </select>
                    </div>
                    <div class="min-w-[130px]">
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Currency</label>
                        <input name="currency" type="text" maxlength="3" class="w-full border-gray-300 rounded-md shadow-sm" placeholder="GBP / USD / EUR">
                    </div>
                    <div>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md">Load projection</button>
                    </div>
                </form>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="px-3 py-1 text-xs border rounded js-range-preset" data-preset="today">Today</button>
                    <button type="button" class="px-3 py-1 text-xs border rounded js-range-preset" data-preset="tomorrow">Tomorrow</button>
                    <button type="button" class="px-3 py-1 text-xs border rounded js-range-preset" data-preset="this_week">This Week</button>
                    <button type="button" class="px-3 py-1 text-xs border rounded js-range-preset" data-preset="next_week">Next Week</button>
                </div>
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
            const orderShowUrlTemplate = @json(route('orders.show', ['order_id' => '__ORDER_ID__']));
            const fromInput = form.querySelector('input[name="from"]');
            const toInput = form.querySelector('input[name="to"]');
            const viewInput = form.querySelector('select[name="view"]');

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
                        renderTodayTiming(json.data.timeline || [], json.data.transactions || []);
                    } else if (json.data.view === 'outstanding') {
                        renderOutstanding(json.data);
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
                const tbody = rows.map(row => `<tr>${columns.map(c => `<td class="px-3 py-2 border-b text-sm align-top ${cellClass(c)}">${formatCell(c, row[c])}</td>`).join('')}</tr>`).join('');
                output.innerHTML = `<div class="w-full overflow-x-auto"><table class="w-full border-collapse table-auto">${thead}${tbody}</table></div>`;
            }

            function renderTodayTiming(timelineRows, transactionRows) {
                const timelineHtml = timelineRows.length
                    ? renderTableHtml(timelineRows, 'Hourly Timing Summary')
                    : '<div class="text-sm text-gray-500 mb-3">No timing summary data found.</div>';
                const transactionsHtml = transactionRows.length
                    ? renderTableHtml(transactionRows, 'Transactions with Time (UTC)')
                    : '<div class="text-sm text-gray-500">No transactions found for selected filters.</div>';

                output.innerHTML = `${timelineHtml}<div class="mt-4">${transactionsHtml}</div>`;
            }

            function renderOutstanding(data) {
                const totals = data.totals_by_currency || {};
                const totalLines = Object.keys(totals)
                    .sort()
                    .map(code => `${code}: ${Number(totals[code]).toFixed(2)}`)
                    .join(' | ');
                const summary = `<div class="text-sm mb-3">Outstanding transactions: ${data.total_transactions || 0}${totalLines ? ` | Totals: ${totalLines}` : ''}</div>`;
                const table = (data.transactions || []).length
                    ? renderTableHtml(data.transactions, 'Outstanding Transactions by Maturity (UTC, ascending)')
                    : '<div class="text-sm text-gray-500">No outstanding transactions found for selected filters.</div>';
                output.innerHTML = `${summary}${table}`;
            }

            function renderTableHtml(rows, title) {
                const columns = Object.keys(rows[0]);
                const thead = `<tr>${columns.map(c => `<th class="px-3 py-2 text-left border-b">${c}</th>`).join('')}</tr>`;
                const tbody = rows.map(row => `<tr>${columns.map(c => `<td class="px-3 py-2 border-b text-sm align-top ${cellClass(c)}">${formatCell(c, row[c])}</td>`).join('')}</tr>`).join('');
                const heading = title ? `<div class="text-sm font-semibold mb-2">${title}</div>` : '';
                return `${heading}<div class="w-full overflow-x-auto"><table class="w-full border-collapse table-auto">${thead}${tbody}</table></div>`;
            }

            function formatCell(column, value) {
                if (column === 'amazon_order_id') {
                    const orderId = (value || '').toString().trim();
                    if (orderId !== '') {
                        const href = orderShowUrlTemplate.replace('__ORDER_ID__', encodeURIComponent(orderId));
                        return `<a href="${href}" class="text-indigo-600 hover:underline">${escapeHtml(orderId)}</a>`;
                    }
                }

                return escapeHtml(value ?? '');
            }

            function escapeHtml(value) {
                return String(value)
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            }

            function cellClass(column) {
                if (['amazon_order_id', 'transaction_id', 'marketplace_id'].includes(column)) {
                    return 'break-all';
                }

                if (['maturity_datetime_utc', 'posted_datetime_utc', 'effective_payment_time_utc', 'hour_utc'].includes(column)) {
                    return 'whitespace-nowrap';
                }

                return '';
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                load();
            });

            document.querySelectorAll('.js-range-preset').forEach(btn => {
                btn.addEventListener('click', function () {
                    const now = new Date();
                    const ymd = d => {
                        const y = d.getUTCFullYear();
                        const m = String(d.getUTCMonth() + 1).padStart(2, '0');
                        const day = String(d.getUTCDate()).padStart(2, '0');
                        return `${y}-${m}-${day}`;
                    };
                    const startOfWeekUtc = d => {
                        const day = d.getUTCDay(); // 0=Sun
                        const diff = day === 0 ? -6 : 1 - day; // Monday start
                        const out = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()));
                        out.setUTCDate(out.getUTCDate() + diff);
                        return out;
                    };

                    let from = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
                    let to = new Date(from);
                    const preset = this.dataset.preset;
                    if (preset === 'tomorrow') {
                        from.setUTCDate(from.getUTCDate() + 1);
                        to = new Date(from);
                    } else if (preset === 'this_week') {
                        from = startOfWeekUtc(now);
                        to = new Date(from);
                        to.setUTCDate(to.getUTCDate() + 6);
                    } else if (preset === 'next_week') {
                        from = startOfWeekUtc(now);
                        from.setUTCDate(from.getUTCDate() + 7);
                        to = new Date(from);
                        to.setUTCDate(to.getUTCDate() + 6);
                    }

                    fromInput.value = ymd(from);
                    toInput.value = ymd(to);
                    viewInput.value = 'outstanding';
                    load();
                });
            });

            load();
        })();
    </script>
</x-app-layout>
