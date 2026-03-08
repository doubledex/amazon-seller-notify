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
                        <input name="from" type="date" class="w-full border-gray-300 rounded-md shadow-sm" value="{{ now()->utc()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString() }}">
                    </div>
                    <div class="min-w-[170px]">
                        <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">To (Maturity UTC)</label>
                        <input name="to" type="date" class="w-full border-gray-300 rounded-md shadow-sm" value="{{ now()->utc()->endOfWeek(\Carbon\Carbon::SUNDAY)->toDateString() }}">
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
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md">Apply</button>
                    </div>
                </form>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" class="px-3 py-1 text-xs border rounded js-range-preset bg-white text-gray-700 hover:bg-gray-50" data-preset="all">All</button>
                    <button type="button" class="px-3 py-1 text-xs border rounded js-range-preset bg-white text-gray-700 hover:bg-gray-50" data-preset="today">Today</button>
                    <button type="button" class="px-3 py-1 text-xs border rounded js-range-preset bg-white text-gray-700 hover:bg-gray-50" data-preset="tomorrow">Tomorrow</button>
                    <button type="button" class="px-3 py-1 text-xs border rounded js-range-preset bg-white text-gray-700 hover:bg-gray-50" data-preset="this_week">This Week</button>
                    <button type="button" class="px-3 py-1 text-xs border rounded js-range-preset bg-white text-gray-700 hover:bg-gray-50" data-preset="next_week">Next Week</button>
                    <button type="submit" form="cashflow-filters" class="px-4 py-1 text-xs bg-indigo-600 text-white rounded-md">Apply</button>
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
            const marketplacesById = @json(collect($marketplaces ?? [])->keyBy('id')->all());
            const fromInput = form.querySelector('input[name="from"]');
            const toInput = form.querySelector('input[name="to"]');
            const dateInput = form.querySelector('input[name="date"]');
            const viewInput = form.querySelector('select[name="view"]');
            const presetButtons = Array.from(document.querySelectorAll('.js-range-preset'));
            let availableAllRange = { from: '', to: '' };

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
                    if (json.data.warning) {
                        meta.textContent += ` | Warning: ${json.data.warning}`;
                    }

                    if (json.data.view === 'today_timing') {
                        renderTodayTiming(json.data.timeline || [], json.data.transactions || []);
                    } else if (json.data.view === 'outstanding') {
                        renderOutstanding(json.data);
                    } else {
                        renderTable(json.data.buckets || []);
                    }

                    updatePresetHighlight();
                } catch (error) {
                    output.innerHTML = '<div class="text-red-600 text-sm">Failed to load projection.</div>';
                }
            }

            function renderTable(rows) {
                if (!rows.length) {
                    output.innerHTML = '<div class="text-sm text-gray-500">No data found for selected filters.</div>';
                    return;
                }

                const columns = orderedColumns(Object.keys(rows[0]));
                const thead = `<tr>${columns.map(c => `<th class="px-3 py-2 text-left border-b">${headerLabel(c)}</th>`).join('')}</tr>`;
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
                availableAllRange = {
                    from: extractYmd(data.available_maturity_from_utc),
                    to: extractYmd(data.available_maturity_to_utc),
                };

                const totals = data.totals_by_currency || {};
                const missingRows = Number(data.missing_total_amount_rows || 0);
                const diagnostics = data.diagnostics || null;
                const totalGbp = Number(data.total_gbp || 0);
                const totalLines = Object.keys(totals)
                    .sort()
                    .map(code => `${code}: ${Number(totals[code]).toFixed(2)}`)
                    .join(' | ');
                const leftSummary = `Outstanding transactions: ${data.total_transactions || 0}${totalLines ? ` | Totals: ${totalLines}` : ''}${missingRows > 0 ? ` | Missing totalAmount rows: ${missingRows}` : ''}`;
                const summary = `
                    <div class="text-sm mb-3 flex items-center justify-between gap-8">
                        <span>${leftSummary}</span>
                        <span class="whitespace-nowrap pl-8">(Total: ${totalGbp.toFixed(2)} GBP)</span>
                    </div>
                `;
                const diagnosticsBlock = diagnostics
                    ? `<div class="text-xs text-gray-600 mb-3">Diagnostics: run=${escapeHtml(diagnostics.ran_at_utc || 'N/A')} | lookback_days=${diagnostics.lookback_days ?? 'N/A'} | regions=${diagnostics.regions_processed ?? 0} | marketplaces=${diagnostics.marketplaces_processed ?? 0} | seen=${diagnostics.transactions_seen ?? 0} | written=${diagnostics.rows_written ?? 0} | excluded_status=${diagnostics.excluded_by_status ?? 0} | excluded_missing_maturity=${diagnostics.excluded_missing_maturity ?? 0} | missing_total=${diagnostics.rows_missing_total_amount ?? 0} | outside_lookback_not_scanned=${diagnostics.outside_lookback_not_scanned ? 'yes' : 'no'}</div>`
                    : '';
                const table = (data.transactions || []).length
                    ? renderTableHtml(data.transactions, 'Outstanding Transactions by Maturity (UTC, ascending)')
                    : '<div class="text-sm text-gray-500">No outstanding transactions found for selected filters.</div>';
                output.innerHTML = `${summary}${diagnosticsBlock}${table}`;
            }

            function renderTableHtml(rows, title) {
                const columns = orderedColumns(Object.keys(rows[0]));
                const thead = `<tr>${columns.map(c => `<th class="px-3 py-2 text-left border-b">${headerLabel(c)}</th>`).join('')}</tr>`;
                const tbody = rows.map(row => `<tr>${columns.map(c => `<td class="px-3 py-2 border-b text-sm align-top ${cellClass(c)}">${formatCell(c, row[c])}</td>`).join('')}</tr>`).join('');
                const heading = title ? `<div class="text-sm font-semibold mb-2">${title}</div>` : '';
                return `${heading}<div class="w-full overflow-x-auto"><table class="w-full border-collapse table-auto">${thead}${tbody}</table></div>`;
            }

            function orderedColumns(columns) {
                const rightMost = ['transaction_id', 'missing_total_amount'];
                const withoutRightMost = columns.filter(c => !rightMost.includes(c));
                const rightMostPresent = rightMost.filter(c => columns.includes(c));
                return [...withoutRightMost, ...rightMostPresent];
            }

            function formatCell(column, value) {
                if (column === 'amazon_order_id') {
                    const orderId = (value || '').toString().trim();
                    if (orderId !== '') {
                        const href = orderShowUrlTemplate.replace('__ORDER_ID__', encodeURIComponent(orderId));
                        return `<a href="${href}" class="text-indigo-600 hover:underline">${escapeHtml(orderId)}</a>`;
                    }
                }
                if (column === 'marketplace_id') {
                    const marketplaceId = (value || '').toString().trim();
                    if (marketplaceId !== '') {
                        const m = marketplacesById[marketplaceId] || null;
                        if (m) {
                            const label = `${(m.flag || '').trim()} ${(m.country_code || '').trim()} - ${(m.name || '').trim()}`.trim();
                            return `${escapeHtml(label)} <span class="text-gray-500">(${escapeHtml(marketplaceId)})</span>`;
                        }
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

            function headerLabel(column) {
                if (column === 'days_posted_to_maturity') {
                    return 'days';
                }

                return column;
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                load();
            });

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

            function getPresetRange(preset) {
                const now = new Date();
                let from = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
                let to = new Date(from);

                if (preset === 'all') {
                    return { from: availableAllRange.from, to: availableAllRange.to };
                }

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

                return { from: ymd(from), to: ymd(to) };
            }

            function extractYmd(value) {
                const text = (value || '').toString().trim();
                const match = text.match(/^(\d{4}-\d{2}-\d{2})/);
                return match ? match[1] : '';
            }

            async function fetchOutstandingExtentsForCurrentFilters() {
                const params = new URLSearchParams();
                const formData = new FormData(form);
                params.set('view', 'outstanding');
                for (const [key, value] of formData.entries()) {
                    if (['from', 'to', 'date', 'view'].includes(key)) {
                        continue;
                    }
                    const text = (value || '').toString().trim();
                    if (text !== '') {
                        params.set(key, text);
                    }
                }

                const response = await fetch(`${projectionUrl}?${params.toString()}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const json = await response.json().catch(() => null);
                if (!response.ok || !json || !json.data || json.data.view !== 'outstanding') {
                    return { from: '', to: '' };
                }

                return {
                    from: extractYmd(json.data.available_maturity_from_utc),
                    to: extractYmd(json.data.available_maturity_to_utc),
                };
            }

            function updatePresetHighlight() {
                const from = fromInput.value;
                const to = toInput.value;
                let matchedPreset = '';

                for (const btn of presetButtons) {
                    const candidate = getPresetRange(btn.dataset.preset || '');
                    if (candidate.from === from && candidate.to === to) {
                        matchedPreset = btn.dataset.preset || '';
                        break;
                    }
                }

                presetButtons.forEach(btn => {
                    const isActive = (btn.dataset.preset || '') === matchedPreset;
                    if (isActive) {
                        btn.style.backgroundColor = '#4f46e5';
                        btn.style.color = '#ffffff';
                        btn.style.borderColor = '#4f46e5';
                    } else {
                        btn.style.backgroundColor = '#ffffff';
                        btn.style.color = '#374151';
                        btn.style.borderColor = '#d1d5db';
                    }
                });
            }

            presetButtons.forEach(btn => {
                btn.addEventListener('click', async function () {
                    const preset = this.dataset.preset || '';
                    let range = getPresetRange(preset);
                    if (preset === 'all') {
                        const fetched = await fetchOutstandingExtentsForCurrentFilters();
                        availableAllRange = fetched;
                        range = fetched;
                    }

                    fromInput.value = range.from;
                    toInput.value = range.to;
                    if (dateInput) {
                        dateInput.value = range.from || dateInput.value;
                    }
                    viewInput.value = 'outstanding';
                    updatePresetHighlight();
                    load();
                });
            });

            fromInput.addEventListener('change', updatePresetHighlight);
            toInput.addEventListener('change', updatePresetHighlight);

            (async function init() {
                try {
                    availableAllRange = await fetchOutstandingExtentsForCurrentFilters();
                } catch (e) {
                    availableAllRange = { from: '', to: '' };
                }
                updatePresetHighlight();
                load();
            })();
        })();
    </script>
</x-app-layout>
