<script>
    import { onMount } from 'svelte';
    import { fade, fly } from 'svelte/transition';
    import { cubicOut } from 'svelte/easing';
    import { Plus, Pencil, Trash2, X, Loader2, ImagePlus } from '@lucide/svelte';

    export let config; // { ajaxUrl, nonce, i18n }
    export let listAction;
    export let saveAction;
    export let deleteAction;
    export let fields; // [{ key, label, type: 'text'|'textarea'|'number'|'select'|'media'|'days'|'datelist', options?, min?, max?, step? }]
    export let columns; // [{ key, label, render?: (item) => string }]
    export let emptyItem; // factory fn () => object with defaults for a new item

    let items = [];
    let loading = false;
    let error = '';
    let panelItem = null; // object being added/edited, or null when closed
    let saving = false;
    let deletingId = null;

    function ajax(action, data) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', config.nonce);
        for (const k in data) {
            if (data[k] === undefined || data[k] === null) continue;
            if (Array.isArray(data[k])) {
                data[k].forEach((v) => body.append(k + '[]', v));
            } else {
                body.append(k, data[k]);
            }
        }
        return fetch(config.ajaxUrl, { method: 'POST', body })
            .then((r) => r.json())
            .then((res) => {
                if (res && res.success) return res.data;
                throw new Error((res && res.data && res.data.message) || config.i18n.errorGeneric);
            });
    }

    function load() {
        loading = true;
        error = '';
        ajax(listAction, {})
            .then((data) => { items = data.items || []; loading = false; })
            .catch((e) => { error = e.message; loading = false; });
    }

    function openAdd() {
        panelItem = emptyItem();
        error = '';
        document.body.style.overflow = 'hidden';
    }
    function openEdit(item) {
        panelItem = { ...item };
        error = '';
        document.body.style.overflow = 'hidden';
    }
    function closePanel() {
        panelItem = null;
        document.body.style.overflow = '';
    }

    function buildPayload() {
        const payload = { ...panelItem };
        // A `days` field stores an array of day-name strings, but not
        // every backend wants the same wire shape: Locations' classic form
        // handler expects individual `day_monday=1` ... flags, while
        // Schedules expects a real `available_days[]` array — opt into the
        // array shape per-field via `dayFormat: 'array'` rather than
        // teaching every backend the other's format.
        fields.forEach((f) => {
            if (f.type === 'days') {
                const selected = payload[f.key] || [];
                if (f.dayFormat === 'array') {
                    payload[f.key] = selected;
                    return;
                }
                delete payload[f.key];
                (f.dayNames || []).forEach((name) => {
                    if (selected.includes(name)) payload['day_' + name] = 1;
                });
            }
        });
        return payload;
    }

    function save() {
        if (saving) return;
        saving = true;
        error = '';
        ajax(saveAction, buildPayload())
            .then(() => {
                saving = false;
                closePanel();
                load();
            })
            .catch((e) => { error = e.message; saving = false; });
    }

    function remove(item) {
        if (!window.confirm(config.i18n.confirmDelete)) return;
        deletingId = item.id;
        ajax(deleteAction, { id: item.id })
            .then(() => { deletingId = null; load(); })
            .catch((e) => { error = e.message; deletingId = null; });
    }

    function pickMedia(field) {
        if (!window.wp || !window.wp.media) return;
        const frame = window.wp.media({ title: config.i18n.chooseImage, multiple: false, library: { type: 'image' } });
        frame.on('select', () => {
            const att = frame.state().get('selection').first().toJSON();
            panelItem = { ...panelItem, [field.key]: att.id, [field.key + '_url']: att.url };
        });
        frame.open();
    }
    function clearMedia(field) {
        panelItem = { ...panelItem, [field.key]: 0, [field.key + '_url']: '' };
    }

    function toggleDay(field, d) {
        const current = panelItem[field.key] || [];
        const next = current.includes(d) ? current.filter((x) => x !== d) : [...current, d].sort();
        panelItem = { ...panelItem, [field.key]: next };
    }

    onMount(load);
</script>

<div class="max-w-5xl">
    <div class="mb-4 flex items-center justify-between">
        {#if loading}<Loader2 size={18} class="animate-spin text-bf-admin-accent" />{:else}<span></span>{/if}
        <button type="button" on:click={openAdd}
                class="flex appearance-none items-center gap-1.5 rounded-md border-0 bg-bf-admin-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-bf-admin-accent-dark">
            <Plus size={16} />{config.i18n.addNew}
        </button>
    </div>

    {#if error && !panelItem}
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
    {/if}

    <div class="overflow-hidden rounded-lg border border-gray-200 shadow-sm">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    {#each columns as col}<th class="px-4 py-3">{col.label}</th>{/each}
                    <th class="px-4 py-3 text-right">{config.i18n.edit}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                {#if !loading && items.length === 0}
                <tr><td colspan={columns.length + 1} class="px-4 py-8 text-center text-gray-400">{config.i18n.noItems}</td></tr>
                {/if}
                {#each items as item (item.id)}
                <tr class="hover:bg-gray-50">
                    {#each columns as col}
                    <td class="px-4 py-3 text-gray-700">{col.render ? col.render(item) : item[col.key]}</td>
                    {/each}
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            <button type="button" on:click={() => openEdit(item)}
                                    class="flex h-8 w-8 appearance-none items-center justify-center rounded-md border-0 bg-transparent text-gray-500 transition-colors hover:bg-gray-100 hover:text-bf-admin-accent">
                                <Pencil size={15} />
                            </button>
                            <button type="button" on:click={() => remove(item)} disabled={deletingId === item.id}
                                    class="flex h-8 w-8 appearance-none items-center justify-center rounded-md border-0 bg-transparent text-gray-500 transition-colors hover:bg-red-50 hover:text-red-600 disabled:opacity-40">
                                {#if deletingId === item.id}<Loader2 size={15} class="animate-spin" />{:else}<Trash2 size={15} />{/if}
                            </button>
                        </div>
                    </td>
                </tr>
                {/each}
            </tbody>
        </table>
    </div>
</div>

{#if panelItem}
<div class="fixed inset-0 z-[100000] bg-black/40" on:click={closePanel} on:keydown={(e) => e.key === 'Escape' && closePanel()} role="presentation" transition:fade={{ duration: 180 }}></div>
<div class="fixed inset-y-0 right-0 z-[100001] flex w-full max-w-md flex-col bg-white shadow-2xl" transition:fly={{ x: 40, duration: 280, easing: cubicOut }}>
    <div class="flex shrink-0 items-center justify-between border-b border-gray-100 px-6 py-5">
        <h3 class="text-lg font-semibold text-gray-900">{panelItem.id ? config.i18n.edit : config.i18n.addNew}</h3>
        <button type="button" on:click={closePanel}
                class="flex h-9 w-9 shrink-0 appearance-none items-center justify-center rounded-full border-0 bg-gray-100 text-gray-500 outline-none transition-colors hover:bg-gray-200 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-bf-admin-accent/40 active:scale-95">
            <X size={16} />
        </button>
    </div>

    <div class="flex-1 overflow-y-auto px-6 py-5">
        <div class="flex flex-col gap-4">
            {#each fields as field}
            <div>
                <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500" for="bf-field-{field.key}">{field.label}</label>

                {#if field.type === 'text'}
                <input id="bf-field-{field.key}" type="text" bind:value={panelItem[field.key]}
                       class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20">

                {:else if field.type === 'textarea'}
                <textarea id="bf-field-{field.key}" rows={field.rows || 3} bind:value={panelItem[field.key]}
                          class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20"></textarea>

                {:else if field.type === 'number'}
                <input id="bf-field-{field.key}" type="number" min={field.min} max={field.max} step={field.step || 1} bind:value={panelItem[field.key]}
                       class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20">

                {:else if field.type === 'select'}
                <select id="bf-field-{field.key}" bind:value={panelItem[field.key]}
                        class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20">
                    {#each field.options as opt}<option value={opt.value}>{opt.label}</option>{/each}
                </select>

                {:else if field.type === 'media'}
                <div class="flex items-center gap-3">
                    {#if panelItem[field.key + '_url']}
                        <img src={panelItem[field.key + '_url']} alt="" class="h-14 w-14 rounded-md border border-gray-200 object-cover">
                    {:else}
                        <span class="flex h-14 w-14 items-center justify-center rounded-md border border-dashed border-gray-300 text-gray-300"><ImagePlus size={20} /></span>
                    {/if}
                    <button type="button" on:click={() => pickMedia(field)}
                            class="appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:border-bf-admin-accent hover:text-bf-admin-accent">
                        {config.i18n.chooseImage}
                    </button>
                    {#if panelItem[field.key + '_url']}
                    <button type="button" on:click={() => clearMedia(field)}
                            class="appearance-none rounded-md border-0 bg-transparent px-2 py-2 text-sm text-gray-400 transition-colors hover:text-red-600">
                        {config.i18n.removeImage}
                    </button>
                    {/if}
                </div>

                {:else if field.type === 'days'}
                <div class="flex flex-wrap gap-1.5">
                    {#each field.dayNames as d, i}
                    <button type="button" on:click={() => toggleDay(field, d)}
                            class="flex h-8 w-8 appearance-none items-center justify-center rounded-full border-0 text-xs font-semibold transition-colors
                                {(panelItem[field.key] || []).includes(d) ? 'bg-bf-admin-accent text-white' : 'bg-gray-100 text-gray-500 hover:bg-gray-200'}">
                        {(field.dayLabels && field.dayLabels[i]) || d}
                    </button>
                    {/each}
                </div>

                {:else if field.type === 'datelist'}
                <textarea id="bf-field-{field.key}" rows={field.rows || 3} placeholder="YYYY-MM-DD" bind:value={panelItem[field.key]}
                          class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-bf-admin-accent focus:ring-2 focus:ring-bf-admin-accent/20"></textarea>
                {/if}

                {#if field.description}<p class="mt-1 text-xs text-gray-400">{field.description}</p>{/if}
            </div>
            {/each}
        </div>
        {#if error}<p class="mt-4 text-xs text-red-600">{error}</p>{/if}
    </div>

    <div class="flex shrink-0 items-center justify-end gap-2 border-t border-gray-100 px-6 py-4">
        <button type="button" on:click={closePanel}
                class="appearance-none rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:border-gray-400">
            {config.i18n.cancel}
        </button>
        <button type="button" on:click={save} disabled={saving}
                class="flex appearance-none items-center gap-1.5 rounded-md border-0 bg-bf-admin-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-bf-admin-accent-dark disabled:opacity-60">
            {#if saving}<Loader2 size={15} class="animate-spin" />{/if}
            {config.i18n.save}
        </button>
    </div>
</div>
{/if}
