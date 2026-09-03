<script>
    import { onMount, onDestroy } from 'svelte';
    import { fade, fly } from 'svelte/transition';
    import { cubicOut } from 'svelte/easing';
    import { ChevronLeft, ChevronRight, ChevronDown, Check, X, Loader2, User, Clock, Users as UsersIcon, Package, Plus, CalendarDays, Grid3x3 } from '@lucide/svelte';
    import { Select } from 'bits-ui';

    export let config; // window.bookflowAdminCalendar

    // wp_localize_script stringifies every scalar value (a long-standing
    // WP behavior — PHP's `(int)` cast doesn't survive the trip), so
    // config.year/config.month arrive as strings. Left un-parsed, `month
    // += delta` silently did string concatenation ("9" + 1 = "91") instead
    // of arithmetic, which is why navigating just one month forward from
    // September actually jumped to January — the reported "booking
    // disappears after navigating" bug.
    let year = parseInt(config.year, 10);
    let month = parseInt(config.month, 10); // 1-12
    let bookings = [];
    let loading = false;
    let error = '';
    let selected = null; // booking object shown in the side panel
    let statusUpdating = false;

    // === View mode: Month (overview) / Week (Google-Calendar-style grid) ===
    let viewMode = 'month'; // 'month' | 'week'
    let weekAnchor = new Date(); // any date inside the currently shown week

    const STATUS_COLORS = {
        pending: '#f0ad4e', confirmed: '#5cb85c', paid: '#337ab7', 'partially-paid': '#8e6fd6',
        'in-progress': '#5bc0de', completed: '#6c757d', 'no-show': '#d9534f',
        cancelled: '#9b9b9b', refunded: '#c0392b',
    };
    function statusColor(s) { return STATUS_COLORS[s] || '#646970'; }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function monthLabel(m) { return (config.months && config.months[m - 1]) || m; }

    $: byDate = (() => {
        const map = {};
        bookings.forEach((b) => {
            (map[b.booking_date] ||= []).push(b);
        });
        return map;
    })();

    $: daysInMonth = new Date(year, month, 0).getDate();
    $: firstWeekday = (() => {
        const d = new Date(year, month - 1, 1).getDay();
        return d === 0 ? 6 : d - 1; // Monday-first
    })();
    $: cells = (() => {
        const out = [];
        for (let i = 0; i < firstWeekday; i++) out.push({ type: 'empty' });
        const todayStr = new Date().toISOString().slice(0, 10);
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${pad(month)}-${pad(d)}`;
            out.push({ type: 'day', day: d, date: dateStr, isToday: dateStr === todayStr, items: byDate[dateStr] || [] });
        }
        while (out.length % 7 !== 0) out.push({ type: 'empty' });
        return out;
    })();

    // Monday-first week containing `d`.
    function weekStartOf(d) {
        const out = new Date(d);
        const dow = out.getDay(); // 0=Sun..6=Sat
        const diff = dow === 0 ? -6 : 1 - dow;
        out.setDate(out.getDate() + diff);
        out.setHours(0, 0, 0, 0);
        return out;
    }
    function toDateStr(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; }

    $: weekStart = weekStartOf(weekAnchor);
    $: weekDayDates = Array.from({ length: 7 }, (_, i) => {
        const d = new Date(weekStart);
        d.setDate(d.getDate() + i);
        return d;
    });

    function restFetch(path, options) {
        return fetch(config.restUrl + path, {
            ...options,
            headers: { 'X-WP-Nonce': config.restNonce, 'Content-Type': 'application/json', ...(options && options.headers) },
        }).then((r) => {
            if (!r.ok) return r.json().then((e) => { throw new Error((e && e.message) || ('HTTP ' + r.status)); });
            return r.json();
        });
    }

    // Two bugs fixed together here:
    // 1. `load()` used to read the reactive `$: daysInMonth`, which is only
    //    guaranteed to reflect the just-changed `year`/`month` after
    //    Svelte's next microtask flush — calling `load()` synchronously
    //    right after mutating `month` could still read the *previous*
    //    month's day count (e.g. requesting date_to=2026-09-31, an invalid
    //    date, right after leaving 31-day October). Computed locally here
    //    instead, from the exact year/month this call is for.
    // 2. Rapid navigation (arrow-arrow-arrow-back) fires overlapping
    //    fetches; whichever response happens to *arrive* last used to win
    //    and overwrite `bookings`, even for a month you'd already
    //    navigated away from — reported as "the Sept 5 booking disappears
    //    after navigating forward and back". A monotonically increasing
    //    request token makes a stale response's `.then` a no-op.
    let loadToken = 0;
    function load() {
        const token = ++loadToken;
        loading = true;
        error = '';

        let dateFrom, dateTo;
        if (viewMode === 'week') {
            const start = weekStartOf(weekAnchor);
            const end = new Date(start);
            end.setDate(end.getDate() + 6);
            dateFrom = toDateStr(start);
            dateTo = toDateStr(end);
        } else {
            const requestYear = year;
            const requestMonth = month;
            const lastDay = new Date(requestYear, requestMonth, 0).getDate();
            dateFrom = `${requestYear}-${pad(requestMonth)}-01`;
            dateTo = `${requestYear}-${pad(requestMonth)}-${pad(lastDay)}`;
        }
        restFetch(`bookings?date_from=${dateFrom}&date_to=${dateTo}&per_page=100&orderby=start_time&order=ASC`)
            .then((res) => {
                if (token !== loadToken) return; // a newer request has since been issued
                bookings = res || [];
                loading = false;
            })
            .catch((e) => {
                if (token !== loadToken) return;
                error = e.message;
                loading = false;
            });
    }

    function changeMonth(delta) {
        month += delta;
        if (month < 1) { month = 12; year--; }
        if (month > 12) { month = 1; year++; }
        load();
    }
    function changeWeek(delta) {
        const d = new Date(weekAnchor);
        d.setDate(d.getDate() + delta * 7);
        weekAnchor = d;
        year = d.getFullYear();
        month = d.getMonth() + 1;
        load();
    }
    function goToday() {
        const now = new Date();
        year = now.getFullYear();
        month = now.getMonth() + 1;
        weekAnchor = now;
        load();
    }
    function setViewMode(mode) {
        if (mode === viewMode) return;
        viewMode = mode;
        load();
    }

    // Bound separately from `selected.status` (rather than a plain
    // `value={selected.status}` on the <select>) so a rejected transition
    // can reliably force the dropdown back to the real status: a native
    // <select>'s one-way `value=` attribute isn't guaranteed to be
    // re-applied by Svelte when the bound expression evaluates to the same
    // string as before, which left the dropdown stuck showing the user's
    // failed pick instead of reverting.
    let selectValue = '';
    $: if (selected) selectValue = selected.status;

    // The slide-over panel is `position:fixed; right:0`, but the wp-admin
    // page behind it keeps its own scrollbar since it's independently
    // scrollable — that scrollbar then renders in the gap between the
    // panel's right edge and the actual browser viewport edge, reading as
    // a stray scroll control floating outside the panel. Locking body
    // scroll while the panel is open removes that second scrollbar
    // entirely, same as any standard modal/drawer.
    function openBooking(b) {
        selected = b;
        error = '';
        document.body.style.overflow = 'hidden';
    }
    function closePanel() {
        selected = null;
        document.body.style.overflow = '';
    }

    function updateStatus(newStatus) {
        if (!selected || statusUpdating) return;
        statusUpdating = true;
        restFetch(`bookings/${selected.id}/status`, { method: 'PATCH', body: JSON.stringify({ status: newStatus }) })
            .then((updated) => {
                bookings = bookings.map((b) => (b.id === updated.id ? updated : b));
                selected = updated;
                selectValue = updated.status;
                statusUpdating = false;
                error = '';
            })
            .catch((e) => {
                error = e.message;
                statusUpdating = false;
                selectValue = selected.status;
            });
    }

    // === Manual admin-side booking creation ===
    let creating = null; // form object while the "New Booking" panel is open
    let createSaving = false;
    let createError = '';

    function emptyNewBooking(dateStr, timeStr) {
        const now = new Date();
        return {
            product_id: config.products && config.products[0] ? config.products[0].id : '',
            booking_date: dateStr || `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`,
            start_time: timeStr || '10:00',
            persons_total: 1,
            customer_name: '', customer_phone: '', customer_email: '', notes: '',
            status: 'confirmed',
        };
    }
    function openCreate(dateStr, timeStr) {
        creating = emptyNewBooking(dateStr, timeStr);
        createError = '';
        document.body.style.overflow = 'hidden';
    }
    function closeCreate() {
        creating = null;
        document.body.style.overflow = '';
    }
    function submitCreate() {
        if (createSaving) return;
        createSaving = true;
        createError = '';
        restFetch('bookings', { method: 'POST', body: JSON.stringify(creating) })
            .then(() => {
                createSaving = false;
                closeCreate();
                load();
            })
            .catch((e) => { createError = e.message; createSaving = false; });
    }

    // === Week view: hourly time grid with Google-Calendar-style
    // click/drag-to-create. Bookings are slot-based (start_time comes from
    // the product's configured time slots, end_time is computed server-side
    // from its duration) — there's no draggable "end", so a drag only ever
    // sets the *start* time; the visual drag itself is what gives the
    // Google Calendar feel, functionally it's equivalent to a click at the
    // drag's starting point, snapped to the nearest 15 minutes.
    const GRID_START_HOUR = 7;
    const GRID_END_HOUR = 21;
    const HOUR_PX = 48;
    const GRID_HEIGHT = (GRID_END_HOUR - GRID_START_HOUR) * HOUR_PX;
    const HOURS = Array.from({ length: GRID_END_HOUR - GRID_START_HOUR }, (_, i) => GRID_START_HOUR + i);

    function timeToOffset(timeStr) {
        const [h, m] = timeStr.split(':').map(Number);
        return Math.max(0, (h - GRID_START_HOUR) * HOUR_PX + (m / 60) * HOUR_PX);
    }
    function offsetToTime(offsetPx) {
        const totalMin = Math.round(((offsetPx / HOUR_PX) * 60) / 15) * 15; // snap to 15 min
        const clamped = Math.max(0, Math.min((GRID_END_HOUR - GRID_START_HOUR) * 60 - 15, totalMin));
        const h = GRID_START_HOUR + Math.floor(clamped / 60);
        const m = clamped % 60;
        return `${pad(h)}:${pad(m)}`;
    }
    function durationMinutes(b) {
        if (!b.end_time) return 60;
        const [sh, sm] = b.start_time.split(':').map(Number);
        const [eh, em] = b.end_time.split(':').map(Number);
        return Math.max(15, (eh * 60 + em) - (sh * 60 + sm));
    }

    let dragDay = null; // date string of the column being dragged in
    let dragStartY = null;
    let dragCurrentY = null;
    $: dragActive = dragDay !== null && dragStartY !== null && dragCurrentY !== null;
    $: dragTop = dragActive ? Math.min(dragStartY, dragCurrentY) : 0;
    $: dragHeight = dragActive ? Math.max(4, Math.abs(dragCurrentY - dragStartY)) : 0;

    function dayColumnMouseDown(e, dateStr) {
        const rect = e.currentTarget.getBoundingClientRect();
        dragDay = dateStr;
        dragStartY = e.clientY - rect.top;
        dragCurrentY = dragStartY;
        const col = e.currentTarget;

        function onMove(ev) {
            const r = col.getBoundingClientRect();
            dragCurrentY = Math.max(0, Math.min(GRID_HEIGHT, ev.clientY - r.top));
        }
        function onUp() {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
            const startTime = offsetToTime(dragTop);
            dragDay = null;
            dragStartY = null;
            dragCurrentY = null;
            openCreate(dateStr, startTime);
        }
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
    }

    $: weekByDate = (() => {
        const map = {};
        bookings.forEach((b) => { (map[b.booking_date] ||= []).push(b); });
        return map;
    })();

    onMount(load);
    onDestroy(() => { document.body.style.overflow = ''; });
</script>

<div id="bookflow-admin-calendar-root" class="max-w-5xl">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <button type="button" on:click={() => viewMode === 'week' ? changeWeek(-1) : changeMonth(-1)} class="flex h-9 w-9 appearance-none items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 transition-colors hover:border-bf-admin-accent hover:text-bf-admin-accent">
            <ChevronLeft size={18} />
        </button>
        <button type="button" on:click={() => viewMode === 'week' ? changeWeek(1) : changeMonth(1)} class="flex h-9 w-9 appearance-none items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 transition-colors hover:border-bf-admin-accent hover:text-bf-admin-accent">
            <ChevronRight size={18} />
        </button>
        <button type="button" on:click={goToday} class="appearance-none rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:border-bf-admin-accent hover:text-bf-admin-accent">
            {config.i18n.today}
        </button>
        {#if viewMode === 'week'}
        <h2 class="ml-2 text-xl font-semibold text-gray-800">
            {monthLabel(weekDayDates[0].getMonth() + 1)} {weekDayDates[0].getDate()}–{weekDayDates[6].getDate()}, {weekDayDates[6].getFullYear()}
        </h2>
        {:else}
        <h2 class="ml-2 text-xl font-semibold text-gray-800">{monthLabel(month)} {year}</h2>
        {/if}
        {#if loading}<Loader2 size={18} class="animate-spin text-bf-admin-accent" />{/if}

        <div class="ml-auto flex items-center gap-2">
            <div class="flex rounded-md border border-gray-300 bg-white p-0.5">
                <button type="button" on:click={() => setViewMode('month')} title={config.i18n.monthView}
                        class="flex appearance-none items-center gap-1 rounded border-0 px-2.5 py-1.5 text-xs font-medium transition-colors {viewMode === 'month' ? 'bg-bf-admin-accent text-white' : 'bg-transparent text-gray-500 hover:text-gray-800'}">
                    <Grid3x3 size={14} />{config.i18n.monthView}
                </button>
                <button type="button" on:click={() => setViewMode('week')} title={config.i18n.weekView}
                        class="flex appearance-none items-center gap-1 rounded border-0 px-2.5 py-1.5 text-xs font-medium transition-colors {viewMode === 'week' ? 'bg-bf-admin-accent text-white' : 'bg-transparent text-gray-500 hover:text-gray-800'}">
                    <CalendarDays size={14} />{config.i18n.weekView}
                </button>
            </div>
            <button type="button" on:click={() => openCreate()}
                    class="flex appearance-none items-center gap-1.5 rounded-md border-0 bg-bf-admin-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-bf-admin-accent-dark">
                <Plus size={16} />{config.i18n.newBooking}
            </button>
        </div>
    </div>

    {#if error}
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
    {/if}

    {#if viewMode === 'week'}
    <p class="mb-3 text-xs text-gray-400">{config.i18n.dragToCreateHelp}</p>
    <div class="w-full overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
        <div class="grid min-w-[820px] select-none" style="grid-template-columns: 56px repeat(7, 1fr);">
            <!-- Header row -->
            <div class="border-b border-gray-200 bg-gray-50"></div>
            {#each weekDayDates as d (toDateStr(d))}
            <div class="border-b border-l border-gray-200 bg-gray-50 px-2 py-2 text-center" class:bg-blue-50={toDateStr(d) === toDateStr(new Date())}>
                <div class="text-[10px] font-semibold uppercase tracking-wide text-gray-500">{config.weekdays[(d.getDay() + 6) % 7]}</div>
                <div class="text-sm font-semibold text-gray-800">{d.getDate()}</div>
            </div>
            {/each}

            <!-- Hour labels column -->
            <div class="relative bg-white" style="height:{GRID_HEIGHT}px;">
                {#each HOURS as h}
                <div class="absolute right-2 -translate-y-1/2 text-[10px] text-gray-400" style="top:{(h - GRID_START_HOUR) * HOUR_PX}px;">{pad(h)}:00</div>
                {/each}
            </div>

            <!-- Day columns -->
            {#each weekDayDates as d (toDateStr(d))}
            {@const dateStr = toDateStr(d)}
            <div class="relative border-l border-gray-100 bg-white" role="button" tabindex="-1" style="height:{GRID_HEIGHT}px;" on:mousedown={(e) => dayColumnMouseDown(e, dateStr)}>
                {#each HOURS as h, i}
                <div class="absolute inset-x-0 border-t border-gray-100" style="top:{i * HOUR_PX}px;"></div>
                {/each}

                {#if dragActive && dragDay === dateStr}
                <div class="pointer-events-none absolute inset-x-0.5 rounded-md border border-bf-admin-accent bg-bf-admin-accent/15" style="top:{dragTop}px;height:{dragHeight}px;"></div>
                {/if}

                {#each (weekByDate[dateStr] || []) as b (b.id)}
                <button type="button" on:mousedown|stopPropagation on:click={() => openBooking(b)}
                        title={`${b.start_time} · ${b.customer_name || '#' + b.id} · ${b.persons_total} pax`}
                        class="absolute inset-x-0.5 overflow-hidden appearance-none rounded border-0 px-1.5 py-0.5 text-left text-[11px] font-medium text-white shadow-sm transition-opacity hover:opacity-90"
                        style="top:{timeToOffset(b.start_time)}px;height:{Math.max(18, durationMinutes(b) / 60 * HOUR_PX - 2)}px;background-color:{statusColor(b.status)};">
                    <span class="block truncate">{b.start_time} {b.customer_name || (b.product ? b.product.name : '#' + b.id)}</span>
                </button>
                {/each}
            </div>
            {/each}
        </div>
    </div>
    {:else}
    <div class="w-full overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
        <div class="grid min-w-[700px] grid-cols-7 gap-px bg-gray-200">
            {#each config.weekdays as w}
            <div class="bg-gray-50 px-2 py-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">{w}</div>
            {/each}

            {#each cells as cell}
                {#if cell.type === 'empty'}
                    <div class="min-h-[110px] bg-gray-50"></div>
                {:else}
                    <div class="min-h-[110px] bg-white p-1.5" class:bg-blue-50={cell.isToday}>
                        <div class="mb-1 text-xs font-semibold" class:text-bf-admin-accent-dark={cell.isToday} class:text-gray-700={!cell.isToday}>{cell.day}</div>
                        <div class="flex flex-col gap-1">
                            {#each cell.items.slice(0, 3) as b (b.id)}
                            <button type="button" on:click={() => openBooking(b)}
                                    title={`${b.start_time} · ${b.customer_name || '#' + b.id} · ${b.persons_total} pax`}
                                    class="truncate appearance-none rounded border-0 px-1.5 py-0.5 text-left text-[11px] font-medium text-white transition-opacity hover:opacity-80"
                                    style:background-color={statusColor(b.status)}>
                                {b.start_time} {b.customer_name || (b.product ? b.product.name : '#' + b.id)}
                            </button>
                            {/each}
                            {#if cell.items.length > 3}
                            <span class="px-1.5 text-[10px] text-gray-500">+{cell.items.length - 3} {config.i18n.more}</span>
                            {/if}
                        </div>
                    </div>
                {/if}
            {/each}
        </div>
    </div>
    {/if}

    <div class="mt-4 flex flex-wrap gap-4">
        {#each Object.entries(config.statusLabels) as [key, label]}
        <span class="flex items-center gap-1.5 text-sm text-gray-700">
            <span class="inline-block h-3 w-3 rounded-sm" style:background-color={statusColor(key)}></span>{label}
        </span>
        {/each}
    </div>
</div>

{#if selected}
<div class="fixed inset-0 z-[100000] bg-black/40" on:click={closePanel} on:keydown={(e) => e.key === 'Escape' && closePanel()} role="presentation" transition:fade={{ duration: 180 }}></div>
<div class="fixed inset-y-0 right-0 z-[100001] flex w-full max-w-sm flex-col bg-white shadow-2xl" transition:fly={{ x: 40, duration: 280, easing: cubicOut }}>

    <!-- Header: status color as a left accent + top strip ties this panel
         back to the calendar pill the user just clicked, instead of a flat
         white box with no visual link to what was clicked. -->
    <div class="relative shrink-0 border-b border-gray-100 px-6 pb-5 pt-6">
        <div class="absolute inset-x-0 top-0 h-1" style:background-color={statusColor(selected.status)}></div>
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">#{selected.id}</p>
                <h3 class="mt-0.5 truncate text-lg font-semibold text-gray-900">{selected.product ? selected.product.name : 'Booking #' + selected.id}</h3>
            </div>
            <button type="button" on:click={closePanel}
                    class="flex h-9 w-9 shrink-0 appearance-none items-center justify-center rounded-full border-0 bg-gray-100 text-gray-500 outline-none transition-colors hover:bg-gray-200 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-bf-admin-accent/40 active:scale-95">
                <X size={16} />
            </button>
        </div>
        <span class="mt-3 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium text-white" style:background-color={statusColor(selected.status)}>
            {config.statusLabels[selected.status] || selected.status}
        </span>
    </div>

    <div class="flex-1 overflow-y-auto px-6 py-5">
        <div class="mb-6 flex flex-col gap-3.5 text-sm">
            <div class="flex items-center gap-3 text-gray-700">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-50 text-gray-400"><Clock size={15} /></span>
                {selected.booking_date} &middot; {selected.start_time}
            </div>
            <div class="flex items-center gap-3 text-gray-700">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-50 text-gray-400"><User size={15} /></span>
                {selected.customer_name || '—'}{selected.customer_phone ? ' · ' + selected.customer_phone : ''}
            </div>
            <div class="flex items-center gap-3 text-gray-700">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-50 text-gray-400"><UsersIcon size={15} /></span>
                {selected.persons_total} {selected.persons_total === 1 ? config.i18n.person : config.i18n.persons}
            </div>
            {#if selected.resource}
            <div class="flex items-center gap-3 text-gray-700">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-50 text-gray-400"><Package size={15} /></span>
                {selected.resource.title}
            </div>
            {/if}
        </div>

        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500" for="bf-admin-cal-status">{config.i18n.status}</label>
        <Select.Root type="single" value={selectValue} disabled={statusUpdating}
                     onValueChange={(v) => updateStatus(v)}>
            <Select.Trigger id="bf-admin-cal-status"
                    class="flex w-full appearance-none items-center justify-between rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20 data-[disabled]:opacity-50">
                <span class="flex items-center gap-2">
                    <span class="h-2 w-2 rounded-full" style:background-color={statusColor(selectValue)}></span>
                    {config.statusLabels[selectValue] || selectValue}
                </span>
                {#if statusUpdating}<Loader2 size={15} class="animate-spin text-gray-400" />{:else}<ChevronDown size={16} class="text-gray-400" />{/if}
            </Select.Trigger>
            <Select.Portal>
                <Select.Content class="z-[100002] w-[var(--bits-select-anchor-width)] overflow-hidden rounded-md border border-gray-200 bg-white py-1 shadow-lg" sideOffset={4}>
                    {#each Object.entries(config.statusLabels) as [key, label]}
                    <Select.Item value={key} label={label}
                            class="flex cursor-pointer items-center justify-between bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors data-[highlighted]:bg-bf-admin-accent/10">
                        {#snippet children({ selected })}
                            <span class="flex items-center gap-2">
                                <span class="h-2 w-2 rounded-full" style:background-color={statusColor(key)}></span>
                                {label}
                            </span>
                            {#if selected}<Check size={14} class="text-bf-admin-accent" />{/if}
                        {/snippet}
                    </Select.Item>
                    {/each}
                </Select.Content>
            </Select.Portal>
        </Select.Root>
        {#if error}<p class="mt-2 text-xs text-red-600">{error}</p>{/if}

        {#if selected.notes}
        <div class="mt-6">
            <div class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">{config.i18n.notes}</div>
            <p class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">{selected.notes}</p>
        </div>
        {/if}
    </div>

    <div class="shrink-0 border-t border-gray-100 px-6 py-4">
        <a href={config.bookingsListUrl + '&view=' + selected.id}
           class="flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:border-bf-admin-accent hover:text-bf-admin-accent">
            {config.i18n.viewFull}
        </a>
    </div>
</div>
{/if}

{#if creating}
<div class="fixed inset-0 z-[100000] bg-black/40" on:click={closeCreate} on:keydown={(e) => e.key === 'Escape' && closeCreate()} role="presentation" transition:fade={{ duration: 180 }}></div>
<div class="fixed inset-y-0 right-0 z-[100001] flex w-full max-w-md flex-col bg-white shadow-2xl" transition:fly={{ x: 40, duration: 280, easing: cubicOut }}>
    <div class="flex shrink-0 items-center justify-between border-b border-gray-100 px-6 py-5">
        <h3 class="text-lg font-semibold text-gray-900">{config.i18n.newBooking}</h3>
        <button type="button" on:click={closeCreate}
                class="flex h-9 w-9 shrink-0 appearance-none items-center justify-center rounded-full border-0 bg-gray-100 text-gray-500 outline-none transition-colors hover:bg-gray-200 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-bf-admin-accent/40 active:scale-95">
            <X size={16} />
        </button>
    </div>

    <div class="flex-1 overflow-y-auto px-6 py-5">
        <div class="flex flex-col gap-4">
            <div>
                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500" for="bf-new-product">{config.i18n.product}</label>
                <select id="bf-new-product" bind:value={creating.product_id}
                        class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20">
                    {#if !config.products || !config.products.length}
                    <option value="">{config.i18n.selectProduct}</option>
                    {/if}
                    {#each (config.products || []) as p}
                    <option value={p.id}>{p.name}</option>
                    {/each}
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500" for="bf-new-date">{config.i18n.date}</label>
                    <input id="bf-new-date" type="date" bind:value={creating.booking_date}
                           class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500" for="bf-new-time">{config.i18n.time}</label>
                    <input id="bf-new-time" type="time" bind:value={creating.start_time}
                           class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20">
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500" for="bf-new-persons">{config.i18n.persons}</label>
                <input id="bf-new-persons" type="number" min="1" bind:value={creating.persons_total}
                       class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20">
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500" for="bf-new-name">{config.i18n.fullName}</label>
                <input id="bf-new-name" type="text" bind:value={creating.customer_name}
                       class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500" for="bf-new-phone">{config.i18n.phone}</label>
                    <input id="bf-new-phone" type="tel" bind:value={creating.customer_phone}
                           class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20">
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500" for="bf-new-email">{config.i18n.email}</label>
                    <input id="bf-new-email" type="email" bind:value={creating.customer_email}
                           class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20">
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500" for="bf-new-notes">{config.i18n.notes}</label>
                <textarea id="bf-new-notes" rows="2" bind:value={creating.notes}
                          class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20"></textarea>
            </div>
            <div>
                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500" for="bf-new-status">{config.i18n.status}</label>
                <select id="bf-new-status" bind:value={creating.status}
                        class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20">
                    {#each Object.entries(config.statusLabels) as [key, label]}
                    <option value={key}>{label}</option>
                    {/each}
                </select>
            </div>
        </div>
        {#if createError}<p class="mt-4 text-xs text-red-600">{createError}</p>{/if}
    </div>

    <div class="flex shrink-0 items-center justify-end gap-2 border-t border-gray-100 px-6 py-4">
        <button type="button" on:click={closeCreate}
                class="appearance-none rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:border-gray-400">
            {config.i18n.cancel}
        </button>
        <button type="button" on:click={submitCreate} disabled={createSaving}
                class="flex appearance-none items-center gap-1.5 rounded-md border-0 bg-bf-admin-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-bf-admin-accent-dark disabled:opacity-60">
            {#if createSaving}<Loader2 size={15} class="animate-spin" />{/if}
            {config.i18n.save}
        </button>
    </div>
</div>
{/if}
