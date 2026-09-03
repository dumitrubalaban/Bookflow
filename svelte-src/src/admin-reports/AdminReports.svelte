<script>
    import { onMount } from 'svelte';
    import { Loader2, TrendingUp, CalendarCheck, Users as UsersIcon, Wallet } from '@lucide/svelte';

    export let config; // window.bookflowAdminReports

    const STATUS_COLORS = {
        pending: '#f0ad4e', confirmed: '#5cb85c', paid: '#337ab7', 'partially-paid': '#8e6fd6',
        'in-progress': '#5bc0de', completed: '#6c757d', 'no-show': '#d9534f',
        cancelled: '#9b9b9b', refunded: '#c0392b',
    };
    const STATUS_LABEL_KEYS = {
        pending: 'statusPending', confirmed: 'statusConfirmed', 'partially-paid': 'statusPartiallyPaid',
        paid: 'statusPaid', 'in-progress': 'statusInProgress', completed: 'statusCompleted',
        cancelled: 'statusCancelled', refunded: 'statusRefunded', 'no-show': 'statusNoShow',
    };
    function statusLabel(s) { return config.i18n[STATUS_LABEL_KEYS[s]] || s; }
    function statusColor(s) { return STATUS_COLORS[s] || '#646970'; }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function toDateStr(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; }

    const RANGES = ['last7', 'last30', 'thisMonth', 'thisYear', 'allTime'];
    function rangeLabel(r) {
        return { last7: config.i18n.last7Days, last30: config.i18n.last30Days, thisMonth: config.i18n.thisMonth,
                 thisYear: config.i18n.thisYear, allTime: config.i18n.allTime }[r];
    }
    function rangeToDates(r) {
        const now = new Date();
        const to = toDateStr(now);
        if (r === 'last7') {
            const d = new Date(now); d.setDate(d.getDate() - 6);
            return { date_from: toDateStr(d), date_to: to };
        }
        if (r === 'last30') {
            const d = new Date(now); d.setDate(d.getDate() - 29);
            return { date_from: toDateStr(d), date_to: to };
        }
        if (r === 'thisMonth') {
            return { date_from: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-01`, date_to: to };
        }
        if (r === 'thisYear') {
            return { date_from: `${now.getFullYear()}-01-01`, date_to: to };
        }
        return {}; // allTime
    }

    let range = 'last30';
    let productId = '';
    let loading = false;
    let error = '';
    let stats = null;

    function load() {
        loading = true;
        error = '';
        const params = new URLSearchParams(rangeToDates(range));
        if (productId) params.set('product_id', productId);
        fetch(config.restUrl + 'stats?' + params.toString(), {
            headers: { 'X-WP-Nonce': config.restNonce },
        }).then((r) => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then((res) => {
            stats = res;
            loading = false;
        }).catch((e) => {
            error = e.message || config.i18n.errorGeneric;
            loading = false;
        });
    }

    $: money = (n) => (config.currency || '') + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    $: avgBookingValue = stats && stats.total_bookings > 0 ? stats.revenue / stats.total_bookings : 0;
    $: maxDayRevenue = stats && stats.by_day && stats.by_day.length
        ? Math.max(...stats.by_day.map((d) => d.revenue), 0.01) : 0.01;
    $: maxProductRevenue = stats && stats.by_product && stats.by_product.length
        ? Math.max(...stats.by_product.map((p) => p.revenue), 0.01) : 0.01;
    $: statusEntries = stats ? Object.entries(stats.by_status).filter(([, count]) => count > 0) : [];
    $: maxStatusCount = statusEntries.length ? Math.max(...statusEntries.map(([, c]) => c)) : 1;

    onMount(load);
</script>

<div id="bookflow-admin-reports" class="max-w-6xl">
    <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
        <p class="text-sm text-gray-500">{config.i18n.reportsDesc}</p>
        <div class="flex flex-wrap items-center gap-2">
            {#each RANGES as r}
            <button type="button" on:click={() => { range = r; load(); }}
                    class="appearance-none rounded-md border px-3 py-1.5 text-sm font-medium transition-colors {range === r ? 'border-bf-admin-accent bg-bf-admin-accent text-white' : 'border-gray-300 bg-white text-gray-600 hover:border-bf-admin-accent hover:text-bf-admin-accent'}">
                {rangeLabel(r)}
            </button>
            {/each}
            <select bind:value={productId} on:change={load}
                    class="appearance-none rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-800 outline-none focus:border-bf-admin-accent">
                <option value="">{config.i18n.allProducts}</option>
                {#each (config.products || []) as p (p.id)}
                <option value={p.id}>{p.name}</option>
                {/each}
            </select>
        </div>
    </div>

    {#if loading}
    <div class="flex items-center gap-2 py-12 text-gray-500"><Loader2 size={18} class="animate-spin" />{config.i18n.loading}</div>
    {:else if error}
    <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
    {:else if stats}

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-2 flex items-center gap-2 text-gray-400"><Wallet size={16} /><span class="text-xs font-semibold uppercase tracking-wide">{config.i18n.totalRevenue}</span></div>
            <div class="text-2xl font-semibold text-gray-900">{money(stats.revenue)}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-2 flex items-center gap-2 text-gray-400"><CalendarCheck size={16} /><span class="text-xs font-semibold uppercase tracking-wide">{config.i18n.totalBookings}</span></div>
            <div class="text-2xl font-semibold text-gray-900">{stats.total_bookings}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-2 flex items-center gap-2 text-gray-400"><UsersIcon size={16} /><span class="text-xs font-semibold uppercase tracking-wide">{config.i18n.totalPersons}</span></div>
            <div class="text-2xl font-semibold text-gray-900">{stats.total_persons}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="mb-2 flex items-center gap-2 text-gray-400"><TrendingUp size={16} /><span class="text-xs font-semibold uppercase tracking-wide">{config.i18n.avgBookingValue}</span></div>
            <div class="text-2xl font-semibold text-gray-900">{money(avgBookingValue)}</div>
        </div>
    </div>

    <div class="mb-6 rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{config.i18n.revenueByDay}</h3>
        {#if !stats.by_day.length}
        <p class="text-sm text-gray-400">{config.i18n.noData}</p>
        {:else}
        <div class="flex items-end gap-1 overflow-x-auto pb-2" style="min-height:120px;">
            {#each stats.by_day as d (d.date)}
            <div class="group relative flex flex-1 min-w-[6px] flex-col items-center justify-end" style="height:120px;">
                <div class="w-full rounded-t bg-bf-admin-accent transition-colors group-hover:bg-bf-admin-accent-dark"
                     style="height:{Math.max(2, (d.revenue / maxDayRevenue) * 100)}%;"></div>
                <div class="pointer-events-none absolute bottom-full mb-1 hidden whitespace-nowrap rounded bg-gray-800 px-2 py-1 text-xs text-white group-hover:block">
                    {d.date}: {money(d.revenue)} ({d.bookings})
                </div>
            </div>
            {/each}
        </div>
        {/if}
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{config.i18n.topProducts}</h3>
            {#if !stats.by_product.length}
            <p class="text-sm text-gray-400">{config.i18n.noData}</p>
            {:else}
            <div class="space-y-3">
                {#each stats.by_product as p (p.product_id)}
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="text-gray-700">{p.name}</span>
                        <span class="font-semibold text-gray-900">{money(p.revenue)}</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full bg-bf-admin-accent" style="width:{Math.max(2, (p.revenue / maxProductRevenue) * 100)}%;"></div>
                    </div>
                </div>
                {/each}
            </div>
            {/if}
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">{config.i18n.bookingsByStatus}</h3>
            {#if !statusEntries.length}
            <p class="text-sm text-gray-400">{config.i18n.noData}</p>
            {:else}
            <div class="space-y-3">
                {#each statusEntries as [status, count] (status)}
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2 text-gray-700">
                            <span class="h-2 w-2 rounded-full" style="background:{statusColor(status)};"></span>
                            {statusLabel(status)}
                        </span>
                        <span class="font-semibold text-gray-900">{count}</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full" style="width:{Math.max(2, (count / maxStatusCount) * 100)}%;background:{statusColor(status)};"></div>
                    </div>
                </div>
                {/each}
            </div>
            {/if}
        </div>
    </div>

    {/if}
</div>
