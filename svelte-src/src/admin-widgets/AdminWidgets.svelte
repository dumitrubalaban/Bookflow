<script>
    import { onMount } from 'svelte';
    import {
        Plus, Trash2, Loader2, ChevronUp, ChevronDown, Check,
        ExternalLink, RotateCcw, Palette, Frame, Type, ListOrdered, Plug,
    } from '@lucide/svelte';

    export let config; // window.bookflowAdminWidgets

    let items = []; // fetched once, just to know the default-widget count / find the one being edited
    let loading = true;
    let notFound = false;
    let error = '';
    let nameError = '';
    let panel = null; // the single widget this page instance edits, or null while loading
    let saving = false;
    let autosaving = false;
    let deletingId = null;
    let unlinkedProducts = [];
    let linkProductId = ''; // '' = auto-create a new product on first save
    let copiedShortcode = false;
    let testingWebhook = false;
    let webhookTestResult = '';
    let activeTab = 'style';
    let activeLocale = (config.locales && config.locales[0]) || 'en_US';
    let previewNonce = 0;
    let autosaveTimer;

    const TABS = [
        { key: 'style', icon: Palette },
        { key: 'container', icon: Frame },
        { key: 'text', icon: Type },
        { key: 'steps', icon: ListOrdered },
        { key: 'integrations', icon: Plug },
    ];
    function tabLabel(key) {
        return { style: config.i18n.tabStyle, container: config.i18n.tabContainer, text: config.i18n.tabText,
                 steps: config.i18n.tabSteps, integrations: config.i18n.tabIntegrations }[key];
    }

    function ajax(action, data) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', config.nonce);
        for (const k in data) {
            if (data[k] === undefined || data[k] === null) continue;
            body.append(k, data[k]);
        }
        return fetch(config.ajaxUrl, { method: 'POST', body })
            .then((r) => r.json())
            .then((res) => {
                if (res && res.success) return res.data;
                throw new Error((res && res.data && res.data.message) || config.i18n.errorGeneric);
            });
    }

    function load(quiet) {
        if (!quiet) loading = true;
        error = '';
        return ajax('bookflow_list_widgets', {})
            .then((data) => { items = data.items || []; loading = false; })
            .catch((e) => { if (!quiet) error = e.message; loading = false; });
    }

    // This page instance edits exactly one widget — which one is decided
    // server-side (?view=<id> or ?view=new) and handed down as
    // config.mode/config.widgetId, matching the same list/detail URL split
    // Bookings uses. list_widgets is still fetched once, just to find that
    // one row (or, for a new widget, to know whether this is the site's
    // first — so it can default to "set as default").
    function init() {
        // wp_localize_script stringifies every scalar it passes down, so
        // config.widgetId arrives as "7", not 7 — comparing that directly
        // against items[].id (a real number from the AJAX JSON response)
        // always failed, which is why an existing widget's own edit URL
        // showed "not found".
        const widgetId = parseInt(config.widgetId, 10);
        load().then(() => {
            if (config.mode === 'new') {
                openNew();
            } else {
                const found = items.find((w) => w.id === widgetId);
                if (found) {
                    openEdit(found);
                } else {
                    notFound = true;
                }
            }
        });
    }

    function emptyWidget() {
        return {
            id: 0, name: '',
            style: { ...config.defaultStyle },
            steps: [...config.customizableSteps],
            text: {},
            is_default: items.length === 0,
            webhook_url: '',
            product_permalink: null,
        };
    }

    function loadUnlinkedProducts() {
        ajax('bookflow_widget_unlinked_products', {})
            .then((data) => { unlinkedProducts = data.items || []; })
            .catch(() => { unlinkedProducts = []; });
    }

    function resetPanelUiState() {
        activeTab = 'style';
        testingWebhook = false;
        webhookTestResult = '';
        copiedShortcode = false;
        error = '';
    }

    function openNew() {
        panel = emptyWidget();
        linkProductId = '';
        resetPanelUiState();
        loadUnlinkedProducts();
    }
    function openEdit(item) {
        resetPanelUiState();
        // item.steps (from the server) always includes the two fixed tail
        // steps appended — strip them back out here since this list only
        // ever holds the reorderable/toggleable subset; they're rendered
        // separately below and the server re-appends them on save.
        panel = {
            ...item,
            style: { ...item.style },
            steps: item.steps.filter((s) => config.customizableSteps.includes(s)),
            // A widget with no text overrides yet comes back from PHP as an
            // empty array (`[]`, not `{}` — PHP can't tell an empty map from
            // an empty list), and JSON.stringify on a JS array silently
            // drops any non-numeric keys set on it later. Force a real
            // object so per-locale keys (setTextValue below) actually
            // survive the next save.
            text: Array.isArray(item.text) ? {} : JSON.parse(JSON.stringify(item.text || {})),
        };
        previewNonce++;
    }
    function closePanel() {
        clearTimeout(autosaveTimer);
        window.location.href = config.listUrl;
    }

    function copyShortcode() {
        if (!panel) return;
        navigator.clipboard.writeText(panel.shortcode).then(() => {
            copiedShortcode = true;
            setTimeout(() => { copiedShortcode = false; }, 1500);
        });
    }

    function moveStep(index, dir) {
        const next = [...panel.steps];
        const target = index + dir;
        if (target < 0 || target >= next.length) return;
        [next[index], next[target]] = [next[target], next[index]];
        panel.steps = next;
        scheduleAutosave();
    }
    function toggleStep(step) {
        panel.steps = panel.steps.includes(step)
            ? panel.steps.filter((s) => s !== step)
            : [...panel.steps, step];
        scheduleAutosave();
    }
    $: disabledSteps = panel ? config.customizableSteps.filter((s) => !panel.steps.includes(s)) : [];

    function textValue(locale, key) {
        return (panel.text[locale] && panel.text[locale][key]) || '';
    }
    function localeHasOverrides(locale) {
        return !!(panel.text[locale] && Object.keys(panel.text[locale]).length);
    }
    function setTextValue(locale, key, value) {
        if (!panel.text[locale]) panel.text[locale] = {};
        if (value) {
            panel.text[locale][key] = value;
        } else {
            delete panel.text[locale][key];
        }
        // Nested mutation — reassign panel (not just `panel = panel`, which
        // a minifier can treat as a no-op and drop) so the "Reset" button's
        // visibility actually updates.
        panel = { ...panel };
        scheduleAutosave();
    }

    function buildPayload() {
        return {
            id: panel.id || '',
            name: panel.name.trim(),
            style: JSON.stringify(panel.style),
            steps: JSON.stringify(panel.steps),
            text: JSON.stringify(panel.text),
            is_default: panel.is_default ? 1 : '',
            webhook_url: panel.webhook_url ? panel.webhook_url.trim() : '',
            link_product_id: linkProductId || '',
        };
    }

    function save() {
        if (saving || !panel) return;
        if (!panel.name.trim()) {
            nameError = config.i18n.widgetNameRequired;
            return;
        }
        saving = true;
        error = '';
        nameError = '';
        ajax('bookflow_save_widget', buildPayload()).then(() => {
            window.location.href = config.listUrl;
        }).catch((e) => { error = e.message; saving = false; });
    }

    // Autosave: page-builder-style — edits to an already-created widget are
    // persisted a moment after the user stops interacting, and the live
    // preview iframe reloads against that just-saved state. There's no
    // separate "draft" concept on the server, so this is what makes the
    // preview genuinely reflect in-progress edits without a manual Save
    // click every time — the explicit Save button below still exists for
    // brand-new widgets (which need a name before anything can persist)
    // and as a deliberate "I'm done" action.
    function scheduleAutosave() {
        if (!panel || !panel.id || !panel.name.trim()) return;
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(() => {
            autosaving = true;
            ajax('bookflow_save_widget', buildPayload()).then(() => {
                autosaving = false;
                previewNonce++;
                load(true);
            }).catch(() => { autosaving = false; });
        }, 700);
    }

    function testWebhook() {
        if (!panel || !panel.webhook_url || testingWebhook) return;
        testingWebhook = true;
        webhookTestResult = '';
        ajax('bookflow_test_webhook', { webhook_url: panel.webhook_url.trim() })
            .then(() => { webhookTestResult = 'success'; testingWebhook = false; })
            .catch((e) => { webhookTestResult = e.message; testingWebhook = false; });
    }

    function remove(item) {
        if (!window.confirm(config.i18n.deleteConfirm)) return;
        deletingId = item.id;
        ajax('bookflow_delete_widget', { id: item.id })
            .then(() => { window.location.href = config.listUrl; })
            .catch((e) => { error = e.message; deletingId = null; });
    }

    $: previewSrc = panel && panel.product_permalink
        ? panel.product_permalink + (panel.product_permalink.includes('?') ? '&' : '?') + 'bf_preview=' + previewNonce
        : null;

    onMount(init);
</script>

<div id="bookflow-admin-widgets" class="flex h-full flex-col gap-4">
    {#if loading}
    <div class="flex items-center gap-2 py-16 text-sm text-gray-400"><Loader2 size={16} class="animate-spin" />{config.i18n.loading}</div>
    {:else if notFound}
    <div class="rounded-lg border border-dashed border-gray-300 bg-white p-16 text-center text-sm text-gray-400">{config.i18n.noWidgets}</div>
    {:else}
    <div class="flex min-h-0 flex-1 flex-col gap-4 lg:flex-row lg:items-stretch">
        {#if panel}
        <!-- Inspector -->
        <div class="flex w-full min-h-0 shrink-0 flex-col rounded-lg border border-gray-200 bg-white shadow-sm lg:w-[400px]">
            <div class="border-b border-gray-100 px-4 py-3">
                <div class="flex items-center justify-between gap-2">
                    <input type="text" bind:value={panel.name}
                           on:input={() => { nameError = ''; scheduleAutosave(); }}
                           placeholder={config.i18n.widgetNamePlaceholder}
                           class="w-full appearance-none border-0 bg-transparent p-0 text-sm font-semibold outline-none {nameError ? 'text-red-600' : 'text-gray-900'}">
                    {#if panel.id}
                    <button type="button" on:click={() => remove(panel)} disabled={deletingId === panel.id}
                            class="shrink-0 appearance-none rounded-md border-0 bg-transparent p-1 text-gray-400 transition-colors hover:text-red-600 disabled:opacity-40">
                        {#if deletingId === panel.id}<Loader2 size={14} class="animate-spin" />{:else}<Trash2 size={14} />{/if}
                    </button>
                    {/if}
                </div>
                {#if nameError}<p class="mt-1 text-xs text-red-600">{nameError}</p>{/if}
            </div>

            <div class="flex border-b border-gray-100">
                {#each TABS as t (t.key)}
                <button type="button" on:click={() => activeTab = t.key} title={tabLabel(t.key)}
                        class="flex flex-1 appearance-none flex-col items-center gap-1 border-0 border-b-2 bg-transparent py-2.5 text-[10px] font-medium uppercase tracking-wide transition-colors {activeTab === t.key ? 'border-bf-admin-accent text-bf-admin-accent' : 'border-transparent text-gray-400 hover:text-gray-600'}">
                    <svelte:component this={t.icon} size={16} />
                    {tabLabel(t.key)}
                </button>
                {/each}
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-4">
                {#if activeTab === 'style'}
                <label class="mb-4 flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" bind:checked={panel.is_default} on:change={scheduleAutosave}>
                    {config.i18n.setAsDefault}
                </label>

                {#if !panel.id}
                <div class="mb-4">
                    <label for="bf-link-product" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500">{config.i18n.linkedProduct}</label>
                    <select id="bf-link-product" bind:value={linkProductId}
                            class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none focus:border-bf-admin-accent">
                        <option value="">{config.i18n.createNew}</option>
                        {#each unlinkedProducts as p (p.id)}
                        <option value={p.id}>{p.name}</option>
                        {/each}
                    </select>
                </div>
                {:else}
                <div class="mb-4 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs">
                    <span class="text-gray-500">{config.i18n.linkedProduct}:</span>
                    <span class="font-medium text-gray-800">{panel.product_name || '#' + panel.product_id}</span>
                    {#if panel.edit_product_url}
                    <a href={panel.edit_product_url} target="_blank" rel="noopener" class="ml-1 text-bf-admin-accent hover:underline">{config.i18n.manageResources}</a>
                    {/if}
                </div>
                {/if}

                <div class="flex flex-col gap-3">
                    {#each [['accent', config.i18n.accentColor], ['accentDark', config.i18n.accentColorDark], ['bg', config.i18n.backgroundColor], ['bgAlt', config.i18n.backgroundColorAlt], ['border', config.i18n.borderColor]] as [key, label]}
                    <div class="flex items-center justify-between gap-3">
                        <label for="bf-style-{key}" class="text-sm text-gray-600">{label}</label>
                        <div class="flex items-center gap-2">
                            <input id="bf-style-{key}" type="color" bind:value={panel.style[key]} on:input={scheduleAutosave} class="h-8 w-10 cursor-pointer appearance-none rounded border border-gray-300 bg-white p-0.5">
                            <input type="text" bind:value={panel.style[key]} on:input={scheduleAutosave} class="w-24 appearance-none rounded-md border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-700 outline-none focus:border-bf-admin-accent">
                        </div>
                    </div>
                    {/each}
                    <div class="flex items-center justify-between gap-3">
                        <label for="bf-style-radius" class="text-sm text-gray-600">{config.i18n.cornerRadius}</label>
                        <div class="flex items-center gap-2">
                            <input id="bf-style-radius" type="range" min="0" max="48" bind:value={panel.style.radius} on:input={scheduleAutosave} class="w-28">
                            <span class="w-10 text-right text-xs text-gray-500">{panel.style.radius}px</span>
                        </div>
                    </div>
                </div>

                {:else if activeTab === 'container'}
                <div class="flex flex-col gap-4">
                    <div>
                        <label for="bf-max-width" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500">{config.i18n.maxWidth}</label>
                        <input id="bf-max-width" type="text" bind:value={panel.style.maxWidth} on:input={scheduleAutosave} placeholder={config.i18n.maxWidthPlaceholder}
                               class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none focus:border-bf-admin-accent">
                        <p class="mt-1 text-xs text-gray-400">{config.i18n.maxWidthHelp}</p>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <label for="bf-padding" class="text-sm text-gray-600">{config.i18n.padding}</label>
                        <div class="flex items-center gap-2">
                            <input id="bf-padding" type="range" min="0" max="120" bind:value={panel.style.padding} on:input={scheduleAutosave} class="w-28">
                            <span class="w-10 text-right text-xs text-gray-500">{panel.style.padding}px</span>
                        </div>
                    </div>
                    <div>
                        <label for="bf-font" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500">{config.i18n.fontFamily}</label>
                        <select id="bf-font" bind:value={panel.style.fontFamily} on:change={scheduleAutosave}
                                class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none focus:border-bf-admin-accent" style="font-family:{panel.style.fontFamily};">
                            {#each config.fontChoices as f (f)}
                            <option value={f} style="font-family:{f};">{f === 'inherit' ? 'inherit (host page font)' : f}</option>
                            {/each}
                        </select>
                    </div>
                    <div>
                        <label for="bf-custom-class" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500">{config.i18n.customClass}</label>
                        <input id="bf-custom-class" type="text" bind:value={panel.style.customClass} on:input={scheduleAutosave} placeholder={config.i18n.customClassPlaceholder}
                               class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none focus:border-bf-admin-accent">
                    </div>
                    <div>
                        <label for="bf-custom-css" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500">{config.i18n.customCss}</label>
                        <textarea id="bf-custom-css" rows="6" bind:value={panel.style.customCss} on:input={scheduleAutosave}
                                  class="w-full appearance-none rounded-md border border-gray-300 bg-gray-900 px-3 py-2 font-mono text-xs text-green-300 outline-none focus:border-bf-admin-accent"
                                  placeholder="#bookflow-booking-form .bookflow-wizard {'{'} ... {'}'}"></textarea>
                        <p class="mt-1 text-xs text-gray-400">{config.i18n.customCssHelp}</p>
                    </div>
                </div>

                {:else if activeTab === 'text'}
                <div class="mb-3 flex gap-1 rounded-md bg-gray-100 p-1">
                    {#each config.locales as locale (locale)}
                    <button type="button" on:click={() => activeLocale = locale}
                            class="flex flex-1 appearance-none items-center justify-center gap-1.5 rounded border-0 px-2 py-1.5 text-xs font-medium transition-colors {activeLocale === locale ? 'bg-white text-gray-900 shadow-sm' : 'bg-transparent text-gray-500 hover:text-gray-700'}">
                        {config.localeNames[locale] || locale}
                        {#if localeHasOverrides(locale)}<span class="h-1.5 w-1.5 rounded-full bg-bf-admin-accent"></span>{/if}
                    </button>
                    {/each}
                </div>
                <p class="mb-4 text-xs text-gray-400">{config.i18n.textTabHelp}</p>
                <div class="flex flex-col gap-5">
                    {#each config.textKeyGroups as group (group.key)}
                    <div>
                        <h4 class="mb-2.5 text-[11px] font-semibold uppercase tracking-wide text-bf-admin-accent">{group.label}</h4>
                        <div class="flex flex-col gap-3">
                            {#each group.keys as key (key)}
                            <div>
                                <label for="bf-text-{key}" class="mb-1 flex items-center gap-1.5 text-xs font-medium text-gray-600">
                                    {config.textKeyLabels[key] || key}
                                    {#if textValue(activeLocale, key)}
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-bf-admin-accent" title={config.i18n.customized}></span>
                                    {/if}
                                </label>
                                <div class="flex items-center gap-1.5">
                                    <input id="bf-text-{key}" type="text"
                                           value={textValue(activeLocale, key)}
                                           on:input={(e) => setTextValue(activeLocale, key, e.target.value)}
                                           placeholder={(config.textKeyDefaults[activeLocale] && config.textKeyDefaults[activeLocale][key]) || ''}
                                           class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-800 outline-none focus:border-bf-admin-accent">
                                    {#if textValue(activeLocale, key)}
                                    <button type="button" title={config.i18n.resetOverride} on:click={() => setTextValue(activeLocale, key, '')}
                                            class="shrink-0 appearance-none rounded border-0 bg-transparent p-1.5 text-gray-400 hover:text-red-600">
                                        <RotateCcw size={13} />
                                    </button>
                                    {/if}
                                </div>
                            </div>
                            {/each}
                        </div>
                    </div>
                    {/each}
                </div>

                {:else if activeTab === 'steps'}
                <p class="mb-3 text-xs text-gray-400">{config.i18n.stepsHelp}</p>
                <ul class="flex flex-col gap-1.5">
                    {#each panel.steps as step, i (step)}
                    <li class="flex items-center gap-2 rounded-md border border-gray-200 bg-gray-50 px-2.5 py-1.5">
                        <div class="flex flex-col">
                            <button type="button" on:click={() => moveStep(i, -1)} disabled={i === 0}
                                    class="flex h-4 w-4 appearance-none items-center justify-center border-0 bg-transparent text-gray-400 disabled:opacity-20 hover:text-bf-admin-accent">
                                <ChevronUp size={13} />
                            </button>
                            <button type="button" on:click={() => moveStep(i, 1)} disabled={i === panel.steps.length - 1}
                                    class="flex h-4 w-4 appearance-none items-center justify-center border-0 bg-transparent text-gray-400 disabled:opacity-20 hover:text-bf-admin-accent">
                                <ChevronDown size={13} />
                            </button>
                        </div>
                        <span class="flex-1 text-sm text-gray-800">{config.stepLabels[step]}</span>
                        <button type="button" on:click={() => toggleStep(step)}
                                class="appearance-none rounded border-0 bg-transparent px-2 py-1 text-xs text-gray-400 hover:text-red-600">
                            {config.i18n.delete}
                        </button>
                    </li>
                    {/each}
                    {#each disabledSteps as step (step)}
                    <li class="flex items-center gap-2 rounded-md border border-dashed border-gray-200 px-2.5 py-1.5 opacity-60">
                        <span class="flex-1 text-sm text-gray-500 line-through">{config.stepLabels[step]}</span>
                        <button type="button" on:click={() => toggleStep(step)}
                                class="flex appearance-none items-center gap-1 rounded border-0 bg-transparent px-2 py-1 text-xs text-bf-admin-accent">
                            <Plus size={12} />
                        </button>
                    </li>
                    {/each}
                    {#each config.fixedTailSteps as step (step)}
                    <li class="flex items-center gap-2 rounded-md border border-gray-100 bg-white px-2.5 py-1.5">
                        <Check size={13} class="text-gray-300" />
                        <span class="flex-1 text-sm text-gray-400">{config.stepLabels[step]}</span>
                        <span class="text-[9px] uppercase tracking-wide text-gray-300">{config.i18n.stepsAlwaysLastNote}</span>
                    </li>
                    {/each}
                </ul>

                {:else if activeTab === 'integrations'}
                {#if panel.id}
                <div class="flex flex-col gap-5">
                    <div>
                        <label for="bf-shortcode" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500">{config.i18n.shortcode}</label>
                        <div class="flex items-center gap-2">
                            <input id="bf-shortcode" type="text" readonly value={panel.shortcode}
                                   class="w-full appearance-none rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700 outline-none">
                            <button type="button" on:click={copyShortcode}
                                    class="shrink-0 appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-600 hover:border-bf-admin-accent hover:text-bf-admin-accent">
                                {copiedShortcode ? '✓' : config.i18n.copy}
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">{config.i18n.shortcodeHelp}</p>
                    </div>
                    <div>
                        <label for="bf-webhook" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500">{config.i18n.webhookUrl}</label>
                        <div class="flex items-center gap-2">
                            <input id="bf-webhook" type="url" bind:value={panel.webhook_url} placeholder={config.i18n.webhookUrlPlaceholder}
                                   on:input={() => { webhookTestResult = ''; scheduleAutosave(); }}
                                   class="w-full appearance-none rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 outline-none focus:border-bf-admin-accent">
                        </div>
                        <button type="button" on:click={testWebhook} disabled={!panel.webhook_url || testingWebhook}
                                class="mt-2 flex appearance-none items-center gap-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:border-bf-admin-accent hover:text-bf-admin-accent disabled:opacity-40">
                            {#if testingWebhook}<Loader2 size={13} class="animate-spin" />{/if}
                            {testingWebhook ? config.i18n.testingWebhook : config.i18n.testWebhook}
                        </button>
                        <p class="mt-2 text-xs {webhookTestResult === 'success' ? 'text-green-600' : webhookTestResult ? 'text-red-600' : 'text-gray-400'}">
                            {webhookTestResult === 'success' ? config.i18n.webhookTestSuccess : webhookTestResult || config.i18n.webhookUrlHelp}
                        </p>
                    </div>
                </div>
                {:else}
                <p class="text-sm text-gray-400">{config.i18n.noProductYet}</p>
                {/if}
                {/if}
            </div>

            {#if error}<p class="border-t border-gray-100 px-4 py-2 text-xs text-red-600">{error}</p>{/if}

            <div class="flex items-center justify-between gap-2 border-t border-gray-100 px-4 py-3">
                <span class="text-xs text-gray-400">
                    {#if autosaving}<Loader2 size={11} class="mr-1 inline animate-spin" />{/if}
                    {autosaving ? config.i18n.saving : ''}
                </span>
                <div class="flex gap-2">
                    <button type="button" on:click={closePanel}
                            class="appearance-none rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:border-gray-400">
                        {config.i18n.cancel}
                    </button>
                    <button type="button" on:click={save} disabled={saving}
                            class="flex appearance-none items-center gap-1.5 rounded-md border-0 bg-bf-admin-accent px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-bf-admin-accent-dark disabled:opacity-60">
                        {#if saving}<Loader2 size={13} class="animate-spin" />{/if}
                        {saving ? config.i18n.saving : config.i18n.save}
                    </button>
                </div>
            </div>
        </div>

        <!-- Live preview: the actual widget, on its actual product page,
             in an iframe — not a re-implemented mockup, so what you see
             here is exactly what a visitor sees. -->
        <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex shrink-0 items-center justify-between border-b border-gray-100 px-4 py-2.5">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">{config.i18n.livePreview}</span>
                {#if panel.product_permalink}
                <a href={panel.product_permalink} target="_blank" rel="noopener"
                   class="flex items-center gap-1 text-xs text-bf-admin-accent hover:underline">
                    <ExternalLink size={12} />{config.i18n.openInNewTab}
                </a>
                {/if}
            </div>
            <div class="min-h-0 flex-1 bg-gray-50">
                {#if previewSrc}
                {#key previewSrc}
                <iframe src={previewSrc} title="Widget live preview" class="h-full w-full border-0"></iframe>
                {/key}
                {:else}
                <div class="flex h-full items-center justify-center p-8 text-center text-sm text-gray-400">{config.i18n.noProductYet}</div>
                {/if}
            </div>
        </div>
        {/if}
    </div>
    {/if}
</div>
