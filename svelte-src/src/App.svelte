<script>
    import { onMount, tick } from 'svelte';
    import { fly } from 'svelte/transition';
    import { Switch, Checkbox } from 'bits-ui';
    import {
        ChevronLeft, ChevronRight, Check, Star, Loader2, AlertCircle,
        Minus, Plus, MapPinned,
    } from '@lucide/svelte';
    import { ajax, restFetch } from './lib/api.js';
    import { makeFieldRules } from './lib/validate.js';
    import Stepper from './Stepper.svelte';

    export let config; // window.bookflowBooking, same shape as legacy

    let cfg = { ...config };
    $: i18n = cfg.i18n || {};
    $: fieldRules = makeFieldRules(i18n);

    const MONTHS_FALLBACK = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function monthName(m) {
        return (i18n.months && i18n.months.length === 12) ? i18n.months[m - 1] : MONTHS_FALLBACK[m - 1];
    }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function formatDateLabel(dateStr) {
        const [y, m, d] = dateStr.split('-');
        return parseInt(d, 10) + ' ' + monthName(parseInt(m, 10)) + ' ' + y;
    }

    // === Wizard step machine ===
    // A plain function (not a reactive `$:` binding) so it can be called
    // synchronously below, before Svelte's reactive scheduler has run —
    // relying on the `allSteps` reactive value here would read undefined
    // on first render since $: statements resolve after plain `let` inits.
    function buildAllSteps(c) {
        return ['language', 'location', 'day', ...(c.hasResources ? ['staff'] : []), 'time', 'persons', 'contact', 'confirm'];
    }
    function stepApplicable(step) {
        return step !== 'language' || !!cfg.hasSchedules;
    }
    $: allSteps = buildAllSteps(cfg);
    $: visibleSteps = allSteps.filter(stepApplicable);
    $: stepLabels = {
        language: i18n.stepLanguage, location: i18n.stepLocation, day: i18n.stepDay,
        staff: i18n.stepStaff, time: i18n.stepTime, persons: i18n.stepPersons,
        contact: i18n.stepContact, confirm: i18n.stepConfirm,
    };
    $: stepperItems = visibleSteps.map((key) => ({ key, label: stepLabels[key] || key }));

    let currentStep = buildAllSteps(cfg).find(stepApplicable) || 'location';
    $: currentIndexInAll = allSteps.indexOf(currentStep);
    $: currentIndexVisible = visibleSteps.indexOf(currentStep);
    $: isFirstStep = currentIndexInAll <= 0;
    $: isLastStep = currentIndexInAll === allSteps.length - 1;

    function nextStep() {
        let idx = currentIndexInAll;
        while (idx < allSteps.length - 1) {
            idx++;
            if (stepApplicable(allSteps[idx])) { goToStep(allSteps[idx]); return; }
        }
    }
    function prevStep() {
        let idx = currentIndexInAll;
        while (idx > 0) {
            idx--;
            if (stepApplicable(allSteps[idx])) { goToStep(allSteps[idx]); return; }
        }
    }

    let root;
    async function goToStep(step) {
        if (allSteps.indexOf(step) === -1) return;
        currentStep = step;
        await tick();
        if (root) root.closest('.bookflow-wizard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });

        if (step === 'location') {
            initLocationStep();
        } else if (step === 'day') {
            loadMonth();
        } else if (step === 'staff') {
            if (selectedDate) loadStaffForDate(selectedDate);
        } else if (step === 'time') {
            if (selectedDate) loadSlotsForDate(selectedDate);
        } else if (step === 'persons' || step === 'contact' || step === 'confirm') {
            if (cfg.hasPersonTypes) updatePersonTypesTotal();
            updatePrice();
        }
    }

    function isStepComplete(step) {
        switch (step) {
            case 'language': return !cfg.hasSchedules || !!selectedSchedule;
            case 'location': return true;
            case 'day': return !!selectedDate;
            case 'staff': return !cfg.hasResources || !!selectedResource;
            case 'time': return !!selectedSlot;
            case 'persons': return persons >= (parseInt(cfg.minPersons) || 1);
            case 'contact': return customerName.trim().length >= 3 && customerPhone.trim().length >= 6;
            case 'confirm': return !hasTerms || termsAccepted;
            default: return true;
        }
    }
    // Svelte's `$:` only re-runs when the identifiers it can see textually
    // change — isStepComplete() reads its inputs through a switch/closure,
    // which the compiler can't see into, so every completion input it
    // touches must also be named here or these two go stale (e.g. "Next"
    // staying disabled after typing a valid name/phone).
    $: formReady = (selectedSchedule, selectedDate, selectedResource, selectedSlot, persons,
        personTypeQtys, customerName, customerPhone, termsAccepted, hasTerms, cfg,
        allSteps.every(isStepComplete));
    $: currentStepComplete = (selectedSchedule, selectedDate, selectedResource, selectedSlot, persons,
        personTypeQtys, customerName, customerPhone, termsAccepted, hasTerms, cfg,
        isStepComplete(currentStep));

    // === Core selection state ===
    const now = new Date();
    let year = now.getFullYear();
    let month = now.getMonth() + 1;
    let selectedDate = null;
    let selectedSlot = null;
    let selectedResource = null;
    let selectedResourceName = '';
    let selectedSchedule = null;
    let selectedScheduleIds = [];
    let selectedLocationTag = null;
    let selectedLocationName = '';
    let slotAvailable = 0;
    let persons = parseInt(config.minPersons) || 1;
    let monthData = {}; // cache keyed by year-month-schedules
    let slotScheduleMap = {};

    const personsMin = () => parseInt(cfg.minPersons) || 1;
    const personsMax = () => parseInt(cfg.maxPersons) || 20;
    function effectiveMax() {
        return slotAvailable > 0 ? Math.min(personsMax(), slotAvailable) : personsMax();
    }
    function setPersons(n) {
        const max = effectiveMax();
        n = Math.max(personsMin(), Math.min(max, n));
        persons = n;
        updatePrice();
    }
    $: spotsRemainingText = slotAvailable > 0
        ? (i18n.spotsRemaining || 'Spots remaining: %d').replace('%d', Math.max(0, slotAvailable - persons))
        : '';

    // === Language step ===
    let langOpen = false;
    let langSelectedValue = '';
    let langSelectedLabel = '';
    $: uniqueLangOptions = (() => {
        const seen = {}; const out = [];
        (cfg.schedules || []).forEach((s) => {
            if (seen[s.option_value]) return;
            seen[s.option_value] = true;
            out.push({ value: s.option_value, label: s.option_label });
        });
        return out;
    })();

    function pickLanguage(opt) {
        langSelectedValue = opt.value;
        langSelectedLabel = opt.label;
        langOpen = false;
        setScheduleFromLang(opt.value);
        resetAfterLanguageChange();
    }
    function setScheduleFromLang(langValue) {
        selectedSchedule = null;
        selectedScheduleIds = [];
        (cfg.schedules || []).forEach((s) => {
            if (s.option_value === langValue) {
                selectedScheduleIds.push(s.id);
                if (!selectedSchedule) selectedSchedule = s.id;
            }
        });
    }
    function resetAfterLanguageChange() {
        selectedDate = null;
        selectedSlot = null;
        selectedResource = null;
        selectedResourceName = '';
        slotAvailable = 0;
        persons = parseInt(cfg.minPersons) || 1;
        monthData = {};
    }
    let langAutoTried = false;
    $: if (!langAutoTried && cfg.hasSchedules && uniqueLangOptions.length === 1) {
        langAutoTried = true;
        pickLanguage(uniqueLangOptions[0]);
    }

    // === Location step ===
    let locations = [];
    let locationsLoaded = false;
    let locationsLoading = false;
    function initLocationStep() {
        if (locationsLoaded || locationsLoading) return;
        locationsLoading = true;
        restFetch(cfg, 'locations').then((res) => {
            locations = res || [];
            locationsLoaded = true;
            locationsLoading = false;
            if (cfg.currentLocation) selectedLocationTag = cfg.currentLocation;
            const cur = locations.find((l) => cfg.currentLocationId && l.id === cfg.currentLocationId);
            if (cur) selectedLocationName = cur.name;
        }).catch(() => { locations = []; locationsLoading = false; });
    }
    let locationSwapping = false;
    function pickLocation(loc) {
        selectedLocationTag = loc.slug;
        selectedLocationName = loc.name;
        if (cfg.currentLocationId && loc.id === cfg.currentLocationId) { nextStep(); return; }
        swapToLocation(loc.id);
    }
    function swapToLocation(locationId) {
        locationSwapping = true;
        restFetch(cfg, 'services?location_id=' + encodeURIComponent(locationId)).then((services) => {
            const svc = (services || [])[0];
            if (!svc) throw new Error('no_service_for_location');
            return restFetch(cfg, 'booking-data/' + svc.id);
        }).then((data) => {
            cfg = {
                ...cfg,
                productId: data.productId, minPersons: data.minPersons, maxPersons: data.maxPersons,
                hasPersonTypes: data.hasPersonTypes, hasResources: data.hasResources, hasSchedules: data.hasSchedules,
                schedules: data.schedules, currentLocation: data.currentLocation, currentLocationId: data.currentLocationId,
            };
            selectedSchedule = null;
            selectedScheduleIds = [];
            monthData = {};
            resetAfterLanguageChange();
            langAutoTried = false;
            langSelectedValue = '';
            langSelectedLabel = '';
            locationSwapping = false;
            nextStep();
        }).catch(() => { locationSwapping = false; showError(); });
    }

    // === Day / calendar step ===
    let calDays = []; // render model: {type:'empty'|'day', date, day, available, selected}
    let calLoading = false;
    let calMessage = '';
    const WEEKDAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $: weekdayLabels = WEEKDAY_KEYS.map((k) => i18n[k] || k);

    function scheduleKey() {
        const ids = selectedScheduleIds.length ? selectedScheduleIds : (selectedSchedule ? [selectedSchedule] : []);
        return ids.length ? ids.join('_') : '0';
    }
    function loadMonth() {
        const ids = selectedScheduleIds.length ? selectedScheduleIds : (selectedSchedule ? [selectedSchedule] : []);
        const key = year + '-' + month + '-s' + scheduleKey();

        if (monthData[key]) { renderDays(monthData[key]); return; }

        calLoading = true;
        calMessage = '';
        calDays = Array.from({ length: 42 }, () => ({ type: 'skeleton' }));

        if (ids.length <= 1) {
            const params = { product_id: cfg.productId, year, month };
            if (ids.length === 1) params.schedule_id = ids[0];
            ajax(cfg, 'bookflow_get_month_availability', params).then((data) => {
                monthData = { ...monthData, [key]: data.calendar };
                calLoading = false;
                renderDays(data.calendar);
            }).catch(() => {
                calLoading = false;
                calMessage = i18n.errorGeneric || 'Could not load';
                calDays = [];
            });
        } else {
            let pending = ids.length;
            const merged = {};
            ids.forEach((sid) => {
                ajax(cfg, 'bookflow_get_month_availability', { product_id: cfg.productId, year, month, schedule_id: sid }).then((data) => {
                    for (const dateStr in data.calendar) {
                        const day = data.calendar[dateStr];
                        if (!merged[dateStr]) merged[dateStr] = { date: dateStr, available: false, slots: 0 };
                        if (day.available) { merged[dateStr].available = true; merged[dateStr].slots += day.slots; }
                    }
                    pending--;
                    if (pending === 0) {
                        monthData = { ...monthData, [key]: merged };
                        calLoading = false;
                        renderDays(merged);
                    }
                });
            });
        }
    }
    function renderDays(calendar) {
        let firstDay = new Date(year, month - 1, 1).getDay();
        firstDay = firstDay === 0 ? 6 : firstDay - 1;
        const daysInMonth = new Date(year, month, 0).getDate();
        const out = [];
        for (let i = 0; i < firstDay; i++) out.push({ type: 'empty' });
        let anyAvailable = false;
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = year + '-' + pad(month) + '-' + pad(d);
            const dayData = calendar[dateStr];
            const available = !!(dayData && dayData.available && dayData.slots > 0);
            if (available) anyAvailable = true;
            out.push({ type: 'day', date: dateStr, day: d, available, selected: selectedDate === dateStr });
        }
        let total = firstDay + daysInMonth;
        while (total < 42) { out.push({ type: 'empty' }); total++; }
        calDays = out;
        calMessage = anyAvailable ? '' : (i18n.noAvailability || 'No availability this month.');
    }
    function changeMonth(delta) {
        month += delta;
        if (month < 1) { month = 12; year--; }
        if (month > 12) { month = 1; year++; }
        loadMonth();
    }
    function selectDate(dateStr) {
        selectedDate = dateStr;
        selectedSlot = null;
        selectedResource = null;
        selectedResourceName = '';
        slotScheduleMap = {};
        const key = year + '-' + month + '-s' + scheduleKey();
        if (monthData[key]) renderDays(monthData[key]);
        nextStep();
    }

    // === Staff / resources step ===
    let resources = [];
    let resourcesLoading = false;
    let resourcesError = '';
    function loadStaffForDate(date) {
        resourcesLoading = true;
        resourcesError = '';
        const params = { product_id: cfg.productId, date };
        if (selectedSchedule) params.schedule_id = selectedSchedule;
        ajax(cfg, 'bookflow_get_resources_for_date', params).then((data) => {
            resources = data.resources || [];
            resourcesLoading = false;
        }).catch(() => {
            resourcesLoading = false;
            resourcesError = i18n.errorGeneric || 'Could not load';
        });
    }
    function pickResource(r) {
        selectedResource = String(r.id);
        selectedResourceName = r.title;
        nextStep();
    }

    // === Time / slots step ===
    let slots = [];
    let slotsLoading = false;
    let slotsError = '';
    function loadSlotsForDate(date) {
        slotsLoading = true;
        slotsError = '';
        const ids = selectedScheduleIds.length ? selectedScheduleIds : (selectedSchedule ? [selectedSchedule] : []);

        if (ids.length <= 1) {
            const params = { product_id: cfg.productId, date };
            if (ids.length === 1) params.schedule_id = ids[0];
            if (selectedResource) params.resource_id = selectedResource;
            ajax(cfg, 'bookflow_get_available_slots', params).then((data) => {
                (data.slots || []).forEach((s) => { slotScheduleMap[s.time] = ids[0]; });
                slotsLoading = false;
                applySlots(data.slots || []);
            }).catch(() => { slotsLoading = false; slotsError = i18n.errorGeneric || 'Could not load'; });
        } else {
            let pending = ids.length;
            const allSlots = {};
            ids.forEach((sid) => {
                const p = { product_id: cfg.productId, date, schedule_id: sid };
                if (selectedResource) p.resource_id = selectedResource;
                ajax(cfg, 'bookflow_get_available_slots', p).then((data) => {
                    (data.slots || []).forEach((s) => {
                        if (!allSlots[s.time] || s.available > allSlots[s.time].available) {
                            allSlots[s.time] = s;
                            slotScheduleMap[s.time] = sid;
                        }
                    });
                    pending--;
                    if (pending === 0) {
                        slotsLoading = false;
                        applySlots(Object.values(allSlots).sort((a, b) => a.time.localeCompare(b.time)));
                    }
                });
            });
        }
    }
    function applySlots(list) {
        slots = list;
        const open = list.filter((s) => !s.is_full);
        if (open.length === 1) selectSlot(open[0]);
    }
    function selectSlot(slot) {
        if (slot.is_full) return;
        selectedSlot = slot.time;
        slotAvailable = parseInt(slot.available) || 0;
        if (slotScheduleMap[slot.time]) selectedSchedule = slotScheduleMap[slot.time];
        nextStep();
    }

    // === Persons (types) step ===
    let personTypeQtys = []; // parallel to cfg.personTypes
    $: if (cfg.hasPersonTypes && personTypeQtys.length !== (cfg.personTypes || []).length) {
        personTypeQtys = (cfg.personTypes || []).map((pt) => pt.min_qty);
    }
    function updatePersonTypesTotal() {
        persons = personTypeQtys.reduce((a, b) => a + (parseInt(b) || 0), 0);
    }
    function changePersonType(i, delta) {
        const pt = cfg.personTypes[i];
        let v = (parseInt(personTypeQtys[i]) || 0) + delta;
        v = Math.max(pt.min_qty, Math.min(pt.max_qty, v));
        personTypeQtys[i] = v;
        personTypeQtys = [...personTypeQtys];
        updatePersonTypesTotal();
        updatePrice();
    }

    // === Contact step ===
    let customerName = '';
    let customerPhone = '';
    let notes = '';
    let touched = { name: false, phone: false };
    function fieldState(key, value) {
        const rule = fieldRules[key];
        const trimmed = value.trim();
        if (!trimmed) return rule.required && touched[key] ? 'invalid' : 'neutral';
        return rule.test(value) ? 'valid' : 'invalid';
    }
    function fieldError(key, value) {
        const rule = fieldRules[key];
        const trimmed = value.trim();
        if (!trimmed) return rule.required ? rule.error : '';
        return rule.test(value) ? '' : rule.error;
    }
    let savePartialTimer;
    function maybeSavePartial() {
        clearTimeout(savePartialTimer);
        savePartialTimer = setTimeout(() => {
            if (customerPhone.trim().length < 6) return;
            ajax(cfg, 'bookflow_save_partial', {
                product_id: cfg.productId, name: customerName.trim(), phone: customerPhone.trim(), email: '', step: currentStep,
            }).catch(() => {});
        }, 800);
    }

    // === Extras & terms ===
    let extrasChecked = {}; // id -> bool
    $: hasExtras = !!(cfg.extras && cfg.extras.length);
    $: hasTerms = !!cfg.termsText;
    let termsAccepted = false;

    // === Price ===
    let priceData = null; // { price_per_person_formatted, persons, total_formatted, deposit_amount_formatted, balance_due_formatted }
    function updatePrice() {
        if (!selectedDate || !selectedSlot) return;
        const data = { product_id: cfg.productId, date: selectedDate, start_time: selectedSlot };
        if (selectedSchedule) data.schedule_id = selectedSchedule;
        if (selectedResource) data.resource_id = selectedResource;

        if (cfg.hasPersonTypes) {
            (cfg.personTypes || []).forEach((pt, i) => {
                data['person_types[' + i + '][person_type_id]'] = pt.id;
                data['person_types[' + i + '][quantity]'] = personTypeQtys[i] || 0;
            });
        } else {
            data.persons = persons;
        }
        Object.keys(extrasChecked).filter((id) => extrasChecked[id]).forEach((id, i) => {
            data['extras[' + i + ']'] = id;
        });

        ajax(cfg, 'bookflow_calculate_price', data).then((res) => { priceData = res; }).catch(() => {});
    }

    // === Recap (Confirm step) ===
    $: recapRows = (() => {
        const rows = [];
        if (selectedLocationName) rows.push([i18n.stepLocation, selectedLocationName]);
        if (cfg.hasSchedules && langSelectedLabel) rows.push([i18n.stepLanguage, langSelectedLabel]);
        if (selectedDate) rows.push([i18n.stepDay, formatDateLabel(selectedDate)]);
        if (selectedResourceName) rows.push([i18n.stepStaff, selectedResourceName]);
        if (selectedSlot) rows.push([i18n.stepTime, selectedSlot]);
        const personsVal = cfg.hasPersonTypes ? persons : persons;
        if (personsVal) rows.push([i18n.stepPersons, String(personsVal)]);
        if (customerName.trim()) rows.push([i18n.stepContact, customerName.trim() + (customerPhone.trim() ? ' · ' + customerPhone.trim() : '')]);
        return rows;
    })();

    // === Toast ===
    let toastMsg = '';
    let toastVisible = false;
    let toastTimer;
    function showError(msg) {
        toastMsg = msg || i18n.errorGeneric || 'Something went wrong. Please try again.';
        toastVisible = true;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toastVisible = false; }, 5000);
    }

    // === Final submit: Svelte renders its own Book Now button/hidden
    // add-to-cart input (see markup below) instead of relying on the
    // PHP-rendered #bookflow-final-nav the legacy widget used — that div
    // lived as a sibling *after* this component's root, which broke the
    // "Book Now inside the card" layout once Svelte started closing
    // .bookflow-booking-form itself. ===
    let cartForm;
    let submitLoading = false;
    let errorStep = '';

    function validateBeforeSubmit() {
        if (!selectedDate) return 'day';
        if (!selectedSlot) return 'time';
        if (cfg.hasResources && !selectedResource) return 'staff';
        if (cfg.hasPersonTypes) {
            if (persons < 1) return 'persons';
        } else if (persons < personsMin()) {
            return 'persons';
        }
        touched = { name: true, phone: true };
        if (fieldState('name', customerName) !== 'valid' || fieldState('phone', customerPhone) !== 'valid') return 'contact';
        if (hasTerms && !termsAccepted) return 'confirm';
        return '';
    }

    function handleSubmit(e) {
        const bad = validateBeforeSubmit();
        if (bad) {
            e.preventDefault();
            errorStep = bad;
            goToStep(bad);
            setTimeout(() => { errorStep = ''; }, 1500);
            return;
        }

        if (submitLoading) return;
        e.preventDefault();
        submitLoading = true;

        const checkData = { product_id: cfg.productId, date: selectedDate, start_time: selectedSlot };
        if (selectedSchedule) checkData.schedule_id = selectedSchedule;
        if (selectedResource) checkData.resource_id = selectedResource;

        ajax(cfg, 'bookflow_get_available_slots', checkData).then((res) => {
            const stillAvailable = (res.slots || []).some((s) => s.time === selectedSlot && !s.is_full && s.available >= persons);
            submitLoading = false;
            if (stillAvailable) {
                cartForm.submit();
            } else {
                monthData = {};
                goToStep('time');
                loadSlotsForDate(selectedDate);
                errorStep = 'time';
                setTimeout(() => { errorStep = ''; }, 2000);
                showError(i18n.noSlots || 'This slot is no longer available');
            }
        }).catch(() => { submitLoading = false; showError(); });
    }

    onMount(() => {
        cartForm = root.closest('form.cart');
        if (cartForm) cartForm.addEventListener('submit', handleSubmit);
        // Legacy init() ends with goToStep(firstStep) so the initial step's
        // lazy data (locations/calendar/etc.) loads without waiting for a
        // user-triggered navigation; replicate that here.
        goToStep(currentStep);
        return () => { if (cartForm) cartForm.removeEventListener('submit', handleSubmit); };
    });
</script>

<!-- Tailwind is compiled with `important: '#bookflow-booking-form'` (see
     tailwind.config.js) so its utilities out-specificity the host theme's
     button/link styles — that compiles to a *descendant* selector
     (`#bookflow-booking-form .class`), so this outer element must stay a
     bare id-only anchor and never itself carry a utility class, or that
     class silently never matches. -->
<div bind:this={root} id="bookflow-booking-form" class="bookflow-wizard">
<div class="relative w-full rounded-3xl border border-bf-border bg-gradient-to-b from-bf-bg-alt to-bf-bg p-6 sm:p-9 text-white shadow-2xl shadow-black/40">

    <Stepper steps={stepperItems} currentIndex={currentIndexVisible} onJump={goToStep} />

    <!-- Every field the WooCommerce add-to-cart POST needs, mounted
         unconditionally for the whole component's lifetime. These must NOT
         live inside a per-step {#if currentStep === ...} block: Svelte
         destroys that block's DOM (hidden inputs included) the moment the
         wizard moves to another step, which silently dropped every earlier
         answer from the submitted form until this was hoisted out. -->
    <input type="hidden" name="bookflow_location_tag" value={selectedLocationTag || ''}>
    <input type="hidden" name="bookflow_booking_date" value={selectedDate || ''}>
    <input type="hidden" name="bookflow_schedule_id" value={selectedSchedule || ''}>
    <input type="hidden" name="bookflow_resource_id" value={selectedResource || ''}>
    <input type="hidden" name="bookflow_start_time" value={selectedSlot || ''}>
    <input type="hidden" name="bookflow_persons_total" value={persons}>
    {#if cfg.hasPersonTypes}
        {#each (cfg.personTypes || []) as pt, i (pt.id)}
        <input type="hidden" name="bookflow_person_types[{i}][person_type_id]" value={pt.id}>
        <input type="hidden" name="bookflow_person_types[{i}][quantity]" value={personTypeQtys[i] || 0}>
        {/each}
    {/if}
    <input type="hidden" name="bookflow_customer_name" value={customerName}>
    <input type="hidden" name="bookflow_customer_phone" value={customerPhone}>
    <input type="hidden" name="bookflow_notes" value={notes}>
    {#if hasTerms}
    <input type="hidden" name="bookflow_terms_accepted" value={termsAccepted ? '1' : ''}>
    {/if}
    {#each Object.keys(extrasChecked).filter((id) => extrasChecked[id]) as id (id)}
    <input type="hidden" name="bookflow_extras[]" value={id}>
    {/each}

    {#key currentStep}
    <div in:fly={{ x: 24, duration: 260, delay: 80 }} out:fly={{ x: -24, duration: 180 }} class="min-h-[220px]" class:animate-shake={!!errorStep}>

    {#if currentStep === 'language'}
    <div>
        <h3 class="mb-5 border-b border-bf-border pb-3 text-sm font-bold uppercase tracking-widest text-bf-accent">{i18n.stepLanguage}</h3>
        <input type="hidden" id="bookflow-language" value={langSelectedValue}>
        <div class="relative">
            <button type="button" on:click={() => langOpen = !langOpen}
                    class="flex w-full items-center justify-between rounded-xl border border-bf-border bg-bf-bg-alt px-5 py-4 text-left transition-colors hover:border-bf-accent/50">
                <span class={langSelectedLabel ? 'text-white' : 'text-white/40'}>{langSelectedLabel || i18n.selectOption || i18n.selectSchedule}</span>
                <ChevronRight size={18} class="transition-transform duration-200 {langOpen ? 'rotate-90' : ''} text-white/50" />
            </button>
            {#if langOpen}
            <div class="absolute z-20 mt-2 w-full overflow-hidden rounded-xl border border-bf-border bg-bf-bg-alt shadow-xl">
                {#each uniqueLangOptions as opt}
                <button type="button" on:click={() => pickLanguage(opt)}
                        class="flex w-full items-center justify-between px-5 py-3 text-left transition-colors hover:bg-bf-accent/15
                            {langSelectedValue === opt.value ? 'bg-bf-accent/10 text-bf-accent' : 'bg-transparent'}">
                    <span>{opt.label}</span>
                    {#if langSelectedValue === opt.value}<Check size={16} />{/if}
                </button>
                {/each}
            </div>
            {/if}
        </div>
    </div>
    {/if}

    {#if currentStep === 'location'}
    <div>
        <h3 class="mb-5 border-b border-bf-border pb-3 text-sm font-bold uppercase tracking-widest text-bf-accent">{i18n.selectLocation}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 transition-opacity {locationSwapping ? 'opacity-40 pointer-events-none' : ''}">
            {#if locationsLoading}
                <div class="col-span-full flex flex-col items-center justify-center gap-3 py-10 text-white/50">
                    <Loader2 size={28} class="animate-spin text-bf-accent" /><span>{i18n.loading}</span>
                </div>
            {:else}
                {#each locations as loc (loc.id)}
                {@const selected = selectedLocationTag === loc.slug}
                <button type="button" on:click={() => pickLocation(loc)}
                        class="group relative rounded-2xl border bg-bf-bg-alt p-5 text-left transition-all duration-200 hover:-translate-y-1 hover:shadow-lg
                            {selected ? 'border-bf-accent ring-2 ring-bf-accent/25' : 'border-bf-border hover:border-bf-accent/50'}">
                    {#if selected}
                    <span class="absolute -right-2 -top-2 flex h-6 w-6 items-center justify-center rounded-full bg-bf-accent text-white shadow"><Check size={14} strokeWidth={3} /></span>
                    {/if}
                    <div class="flex items-center gap-2 font-semibold"><MapPinned size={18} class="text-bf-accent" />{loc.name}</div>
                    {#if loc.address}<p class="mt-1 pl-6 text-sm text-white/50">{loc.address}</p>{/if}
                </button>
                {/each}
            {/if}
        </div>
    </div>
    {/if}

    {#if currentStep === 'day'}
    <div>
        <h3 class="mb-5 border-b border-bf-border pb-3 text-sm font-bold uppercase tracking-widest text-bf-accent">{i18n.selectDate}</h3>
        <div class="rounded-2xl border border-bf-border bg-bf-bg-alt p-4 sm:p-5">
            <div class="mb-4 flex items-center justify-between">
                <button type="button" on:click={() => changeMonth(-1)} class="flex h-9 w-9 items-center justify-center rounded-full border border-bf-border bg-transparent transition-colors hover:border-bf-accent hover:text-bf-accent"><ChevronLeft size={18} /></button>
                <span class="font-semibold tracking-wide">{monthName(month)} {year}</span>
                <button type="button" on:click={() => changeMonth(1)} class="flex h-9 w-9 items-center justify-center rounded-full border border-bf-border bg-transparent transition-colors hover:border-bf-accent hover:text-bf-accent"><ChevronRight size={18} /></button>
            </div>
            <div class="grid grid-cols-7 gap-1 text-center text-[11px] uppercase tracking-wide text-white/40">
                {#each weekdayLabels as w}<span class="py-1">{w}</span>{/each}
            </div>
            <div class="grid grid-cols-7 gap-1.5 mt-1">
                {#each calDays as cell}
                    {#if cell.type === 'skeleton'}
                        <span class="aspect-square rounded-full bg-white/5 animate-pulse"></span>
                    {:else if cell.type === 'empty'}
                        <span class="aspect-square"></span>
                    {:else if cell.available}
                        <button type="button"
                              class="aspect-square rounded-full bg-transparent text-sm font-medium transition-all duration-150 hover:bg-bf-accent/20
                                  {cell.selected ? 'bg-bf-accent text-white shadow-md shadow-bf-accent/30' : 'text-white/85'}"
                              aria-label={formatDateLabel(cell.date) + ', ' + (i18n.available || 'available')}
                              aria-pressed={cell.selected ? 'true' : 'false'}
                              on:click={() => selectDate(cell.date)}>
                            {cell.day}
                        </button>
                    {:else}
                        <span class="aspect-square flex items-center justify-center rounded-full text-sm text-white/15" aria-disabled="true">{cell.day}</span>
                    {/if}
                {/each}
            </div>
            {#if calMessage}<div class="pt-4 text-center text-sm text-white/50">{calMessage}</div>{/if}
        </div>
    </div>
    {/if}

    {#if currentStep === 'staff'}
    <div>
        <h3 class="mb-5 border-b border-bf-border pb-3 text-sm font-bold uppercase tracking-widest text-bf-accent">{i18n.selectResource}</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {#if resourcesLoading}
                <div class="col-span-full flex flex-col items-center justify-center gap-3 py-10 text-white/50">
                    <Loader2 size={28} class="animate-spin text-bf-accent" /><span>{i18n.loading}</span>
                </div>
            {:else if resourcesError}
                <p class="col-span-full py-6 text-center text-white/50">{resourcesError}</p>
            {:else if !resources.length}
                <p class="col-span-full py-6 text-center text-white/50">{i18n.noAvailability || 'No guides available this day.'}</p>
            {:else}
                {#each resources as r (r.id)}
                {@const selected = selectedResource === String(r.id)}
                <button type="button" on:click={() => pickResource(r)}
                        class="group relative flex flex-col items-center gap-2 rounded-2xl border bg-bf-bg-alt p-5 text-center transition-all duration-200 hover:-translate-y-1 hover:shadow-lg
                            {selected ? 'border-bf-accent ring-2 ring-bf-accent/25' : 'border-bf-border hover:border-bf-accent/50'}">
                    {#if selected}
                    <span class="absolute -right-2 -top-2 flex h-6 w-6 items-center justify-center rounded-full bg-bf-accent text-white shadow"><Check size={14} strokeWidth={3} /></span>
                    {/if}
                    {#if r.photoUrl}
                        <img src={r.photoUrl} alt="" loading="lazy" class="h-16 w-16 rounded-full object-cover border-2 border-bf-border">
                    {:else}
                        <span class="flex h-16 w-16 items-center justify-center rounded-full bg-bf-accent/15 text-bf-accent font-bold text-lg">{r.title.charAt(0)}</span>
                    {/if}
                    <span class="font-semibold">{r.title}</span>
                    {#if r.ratingCount > 0}
                    <span class="flex items-center gap-1 text-sm text-bf-accent"><Star size={13} fill="currentColor" />{r.avgRating.toFixed(1)} <span class="text-white/40">&middot; {r.ratingCount}</span></span>
                    {/if}
                    {#if r.description}<span class="text-sm text-white/50">{r.description}</span>{/if}
                    <span class="text-xs text-white/40">{r.capacity} pax{#if r.cost > 0} &middot; +{@html r.costFormatted}{/if}</span>
                </button>
                {/each}
            {/if}
        </div>
    </div>
    {/if}

    {#if currentStep === 'time'}
    <div>
        <h3 class="mb-5 border-b border-bf-border pb-3 text-sm font-bold uppercase tracking-widest text-bf-accent">{i18n.selectTime}</h3>
        <div>
            {#if slotsLoading}
                <div class="flex flex-col items-center justify-center gap-3 py-10 text-white/50">
                    <Loader2 size={28} class="animate-spin text-bf-accent" /><span>{i18n.loading}</span>
                </div>
            {:else if slotsError}
                <p class="py-6 text-center text-white/50">{slotsError}</p>
            {:else if !slots.length}
                <p class="py-6 text-center text-white/50">{i18n.noSlots}</p>
            {:else}
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    {#each slots as slot (slot.time)}
                    {@const selected = selectedSlot === slot.time}
                    <button type="button" disabled={slot.is_full} on:click={() => selectSlot(slot)}
                            class="rounded-xl border px-4 py-3 text-center transition-all duration-150
                                {slot.is_full ? 'border-bf-border/50 text-white/25 cursor-not-allowed' : 'hover:border-bf-accent/50 hover:-translate-y-0.5'}
                                {selected ? 'border-bf-accent bg-bf-accent/10 shadow-md shadow-bf-accent/10' : 'border-bf-border bg-bf-bg-alt'}">
                        <span class="block font-semibold {selected ? 'text-bf-accent' : ''}">{slot.time}</span>
                        <span class="block text-xs text-white/45 mt-0.5">
                            {slot.is_full ? (i18n.soldOut || 'Sold out') : (i18n.spotsOfMax || '%d of %d available').replace('%d', slot.available).replace('%d', slot.max_persons)}
                        </span>
                    </button>
                    {/each}
                </div>
            {/if}
        </div>
    </div>
    {/if}

    {#if currentStep === 'persons'}
    <div>
        {#if cfg.hasPersonTypes}
        <h3 class="mb-5 border-b border-bf-border pb-3 text-sm font-bold uppercase tracking-widest text-bf-accent">{i18n.participants}</h3>
        <div class="flex flex-col gap-3">
            {#each (cfg.personTypes || []) as pt, i (pt.id)}
            <div class="flex items-center justify-between rounded-xl border border-bf-border bg-bf-bg-alt px-5 py-4">
                <div>
                    <div class="font-semibold">{pt.name}</div>
                    <div class="text-sm text-bf-accent">{@html pt.costFormatted}</div>
                </div>
                <div class="flex items-center gap-3 rounded-full border border-bf-border bg-bf-bg px-1.5 py-1.5">
                    <button type="button" on:click={() => changePersonType(i, -1)} class="flex h-8 w-8 items-center justify-center rounded-full bg-transparent transition-colors hover:bg-bf-accent/20"><Minus size={15} /></button>
                    <span class="w-6 text-center font-semibold">{personTypeQtys[i]}</span>
                    <button type="button" on:click={() => changePersonType(i, 1)} class="flex h-8 w-8 items-center justify-center rounded-full bg-transparent transition-colors hover:bg-bf-accent/20"><Plus size={15} /></button>
                </div>
            </div>
            {/each}
        </div>
        {:else}
        <h3 class="mb-5 border-b border-bf-border pb-3 text-sm font-bold uppercase tracking-widest text-bf-accent">{i18n.numberOfPersons}</h3>
        <div class="flex items-center gap-5 rounded-full border border-bf-border bg-bf-bg-alt w-fit px-2 py-2">
            <button type="button" on:click={() => setPersons(persons - 1)} class="flex h-10 w-10 items-center justify-center rounded-full bg-transparent transition-colors hover:bg-bf-accent/20"><Minus size={16} /></button>
            <span class="w-8 text-center text-lg font-semibold">{persons}</span>
            <button type="button" on:click={() => setPersons(persons + 1)} class="flex h-10 w-10 items-center justify-center rounded-full bg-transparent transition-colors hover:bg-bf-accent/20"><Plus size={16} /></button>
        </div>
        {/if}
        {#if spotsRemainingText}
        <p class="mt-3 text-sm text-bf-accent">{spotsRemainingText}</p>
        {/if}
    </div>
    {/if}

    {#if currentStep === 'contact'}
    <div>
        <h3 class="mb-5 border-b border-bf-border pb-3 text-sm font-bold uppercase tracking-widest text-bf-accent">{i18n.contactDetails}</h3>
        <div class="flex flex-col gap-4 max-w-lg">
            <div>
                <label for="bookflow-customer-name" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-white/50">{i18n.fullName}</label>
                <input type="text" id="bookflow-customer-name" required
                       bind:value={customerName}
                       on:blur={() => { touched.name = true; maybeSavePartial(); }}
                       class="w-full rounded-xl border bg-bf-bg-alt px-4 py-3 text-white outline-none transition-colors placeholder:text-white/30 focus:ring-2 focus:ring-bf-accent/20
                           {touched.name && fieldState('name', customerName) === 'invalid' ? 'border-red-500/70' : fieldState('name', customerName) === 'valid' ? 'border-emerald-500/60' : 'border-bf-border focus:border-bf-accent'}">
                {#if touched.name && fieldError('name', customerName)}<p class="mt-1 text-xs text-red-400">{fieldError('name', customerName)}</p>{/if}
            </div>
            <div>
                <label for="bookflow-customer-phone" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-white/50">{i18n.phone}</label>
                <input type="tel" id="bookflow-customer-phone" required
                       bind:value={customerPhone}
                       on:blur={() => { touched.phone = true; maybeSavePartial(); }}
                       class="w-full rounded-xl border bg-bf-bg-alt px-4 py-3 text-white outline-none transition-colors placeholder:text-white/30 focus:ring-2 focus:ring-bf-accent/20
                           {touched.phone && fieldState('phone', customerPhone) === 'invalid' ? 'border-red-500/70' : fieldState('phone', customerPhone) === 'valid' ? 'border-emerald-500/60' : 'border-bf-border focus:border-bf-accent'}">
                {#if touched.phone && fieldError('phone', customerPhone)}<p class="mt-1 text-xs text-red-400">{fieldError('phone', customerPhone)}</p>{/if}
            </div>
            <div>
                <label for="bookflow-notes" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-white/50">{i18n.notesOptional}</label>
                <textarea id="bookflow-notes" rows="3" bind:value={notes}
                          class="w-full rounded-xl border border-bf-border bg-bf-bg-alt px-4 py-3 text-white outline-none transition-colors placeholder:text-white/30 focus:border-bf-accent focus:ring-2 focus:ring-bf-accent/20"></textarea>
            </div>
        </div>
    </div>
    {/if}

    {#if currentStep === 'confirm'}
    <div>
        <div class="rounded-2xl border border-bf-border bg-bf-bg-alt overflow-hidden">
            <h3 class="px-5 pt-4 pb-3 text-sm font-bold uppercase tracking-widest text-bf-accent">{i18n.bookingDetails}</h3>
            <div class="divide-y divide-bf-border">
                {#each recapRows as row}
                <div class="flex items-center justify-between px-5 py-3">
                    <span class="text-white/50">{row[0]}</span>
                    <span class="font-semibold text-right">{row[1]}</span>
                </div>
                {/each}
            </div>
        </div>

        {#if hasExtras}
        <div class="mt-6">
            <h3 class="mb-4 border-b border-bf-border pb-3 text-sm font-bold uppercase tracking-widest text-bf-accent">{i18n.extrasTitle}</h3>
            <div class="flex flex-col gap-3">
                {#each cfg.extras as ex (ex.id)}
                <div class="flex items-center justify-between rounded-xl border border-bf-border bg-bf-bg-alt px-5 py-3.5">
                    <div>
                        <div class="font-medium">{ex.title}</div>
                        <div class="text-sm text-bf-accent">{@html ex.priceFormatted}</div>
                    </div>
                    <Switch.Root
                        checked={!!extrasChecked[ex.id]}
                        onCheckedChange={(v) => { extrasChecked = { ...extrasChecked, [ex.id]: v }; updatePrice(); }}
                        class="relative h-6 w-11 shrink-0 rounded-full bg-white/10 transition-colors data-[state=checked]:bg-bf-accent"
                    >
                        <Switch.Thumb class="block h-5 w-5 translate-x-0.5 rounded-full bg-white shadow transition-transform duration-200 data-[state=checked]:translate-x-[22px]" />
                    </Switch.Root>
                </div>
                {/each}
            </div>
        </div>
        {/if}

        <div class="mt-6 rounded-2xl border border-bf-border bg-bf-bg-alt p-5">
            <div class="flex items-center justify-between py-1.5 text-sm text-white/60">
                <span>{i18n.pricePerPerson}</span>
                <span>{@html priceData ? priceData.price_per_person_formatted : '-'}</span>
            </div>
            <div class="flex items-center justify-between py-1.5 text-sm text-white/60">
                <span>{i18n.persons}</span>
                <span>{priceData ? priceData.persons : '-'}</span>
            </div>
            <div class="mt-2 flex items-center justify-between border-t border-bf-border pt-3 text-lg font-bold">
                <span class="text-bf-accent">{i18n.total}</span>
                <span class="text-bf-accent">{@html priceData ? priceData.total_formatted : '-'}</span>
            </div>
            {#if priceData && priceData.deposit_amount_formatted}
            <div class="mt-3 flex items-center justify-between border-t border-bf-border pt-3 text-sm text-white/60">
                <span>{i18n.depositPaidNow}</span>
                <span>{@html priceData.deposit_amount_formatted}</span>
            </div>
            <div class="flex items-center justify-between py-1 text-sm text-white/60">
                <span>{i18n.balanceDue}</span>
                <span>{@html priceData.balance_due_formatted}</span>
            </div>
            {/if}
        </div>

        {#if hasTerms}
        <div class="mt-6">
            <p class="mb-3 rounded-xl border border-bf-border bg-bf-bg-alt p-4 text-sm text-white/55">{cfg.termsText}</p>
            <label class="flex cursor-pointer items-center gap-3">
                <Checkbox.Root
                    checked={termsAccepted}
                    onCheckedChange={(v) => termsAccepted = v}
                    class="group flex h-5 w-5 shrink-0 items-center justify-center rounded-md border-2 border-bf-border transition-colors data-[state=checked]:border-bf-accent data-[state=checked]:bg-bf-accent"
                >
                    <Check size={13} strokeWidth={3.5} class="text-white opacity-0 transition-opacity group-data-[state=checked]:opacity-100" />
                </Checkbox.Root>
                <span class="text-sm text-white/90">{i18n.termsAgree}</span>
            </label>
        </div>
        {/if}
    </div>
    {/if}

    </div>
    {/key}

    <div class="mt-8 flex items-center justify-between">
        <button type="button" on:click={prevStep} style:visibility={isFirstStep ? 'hidden' : 'visible'}
                class="rounded-full border border-bf-border bg-transparent px-6 py-3 text-sm font-semibold uppercase tracking-wide text-white/70 transition-all hover:border-white/40 hover:text-white">
            {i18n.wizardBack}
        </button>
        {#if !isLastStep}
        <button type="button" disabled={!currentStepComplete} on:click={() => { if (currentStepComplete) nextStep(); }}
                class="rounded-full bg-gradient-to-r from-bf-accent to-bf-accent-dark px-7 py-3 text-sm font-semibold uppercase tracking-wide text-white transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-bf-accent/20 disabled:pointer-events-none disabled:translate-y-0 disabled:opacity-40">
            {i18n.wizardNext}
        </button>
        {/if}
        {#if isLastStep}
        <div id="bookflow-final-nav">
            <input type="hidden" name="add-to-cart" value={cfg.productId}>
            <button type="submit" id="bookflow-submit" name="add-to-cart" value={cfg.productId} disabled={!formReady || submitLoading}
                    class="flex items-center gap-2 rounded-full bg-gradient-to-r from-bf-accent to-bf-accent-dark px-7 py-3 text-sm font-semibold uppercase tracking-wide text-white transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-bf-accent/20 disabled:pointer-events-none disabled:translate-y-0 disabled:opacity-40">
                {#if submitLoading}<Loader2 size={16} class="animate-spin" />{/if}
                {cfg.addToCartText || i18n.wizardNext}
            </button>
        </div>
        {/if}
    </div>

    {#if toastVisible}
    <div role="alert" class="fixed bottom-6 left-1/2 z-50 flex -translate-x-1/2 items-center gap-2 rounded-xl bg-red-600 px-5 py-3 text-white shadow-2xl">
        <AlertCircle size={18} />{toastMsg}
    </div>
    {/if}
</div>
</div>
