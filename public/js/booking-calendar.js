/**
 * Bookflow - Wizard Booking Form
 * Steps: language -> location -> day -> staff -> time -> persons -> contact -> confirm
 */
(function () {
    'use strict';

    if (typeof bookflowBooking === 'undefined') return;

    var WIZARD_STEPS = ['language', 'location', 'day'];
    if (bookflowBooking.hasResources) WIZARD_STEPS.push('staff');
    WIZARD_STEPS.push('time', 'persons', 'contact', 'confirm');

    var state = {
        currentStep: WIZARD_STEPS[0],
        year: new Date().getFullYear(),
        month: new Date().getMonth() + 1,
        selectedDate: null,
        selectedSlot: null,
        selectedResource: null,
        selectedSchedule: null,
        selectedLocationTag: null,
        slotAvailable: 0,
        persons: parseInt(bookflowBooking.minPersons) || 1,
        personTypes: {},
        monthData: {},
        selectedScheduleIds: [],
        slotScheduleMap: {},
    };

    var els = {};

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/"/g, '&quot;');
    }

    function init() {
        els.calendar = document.getElementById('bookflow-calendar');
        els.calMonth = document.getElementById('bookflow-cal-month');
        els.calDays = document.getElementById('bookflow-cal-days');
        els.calWeekdays = document.getElementById('bookflow-cal-weekdays');
        els.prevBtn = document.getElementById('bookflow-cal-prev');
        els.nextBtn = document.getElementById('bookflow-cal-next');
        els.dateInput = document.getElementById('bookflow-booking-date');
        els.scheduleInput = document.getElementById('bookflow-schedule-id');
        els.slotsGrid = document.getElementById('bookflow-slots-grid');
        els.timeInput = document.getElementById('bookflow-start-time');
        els.resourcesGrid = document.getElementById('bookflow-resources-grid');
        els.resourceInput = document.getElementById('bookflow-resource-id');
        els.locationsGrid = document.getElementById('bookflow-locations-grid');
        els.locationInput = document.getElementById('bookflow-location-tag');
        els.personsSection = document.getElementById('bookflow-persons-section');
        els.personTypesSection = document.getElementById('bookflow-person-types-section');
        els.personsInput = document.getElementById('bookflow-persons');
        els.personsTotalInput = document.getElementById('bookflow-persons-total');
        els.personsMinus = document.getElementById('bookflow-persons-minus');
        els.personsPlus = document.getElementById('bookflow-persons-plus');
        els.pricePerPerson = document.getElementById('bookflow-price-per-person');
        els.summaryPersons = document.getElementById('bookflow-summary-persons');
        els.totalPrice = document.getElementById('bookflow-total-price');
        els.depositRow = document.getElementById('bookflow-deposit-row');
        els.depositAmount = document.getElementById('bookflow-deposit-amount');
        els.balanceRow = document.getElementById('bookflow-balance-row');
        els.balanceAmount = document.getElementById('bookflow-balance-amount');
        els.termsBox = document.getElementById('bookflow-terms-accepted');
        if (els.termsBox) {
            els.termsBox.addEventListener('change', function () {
                updateWizardNav();
                checkFormReady();
            });
        }
        document.querySelectorAll('.bookflow-extra-check').forEach(function (cb) {
            cb.addEventListener('change', function () {
                if (state.selectedSlot) updatePrice();
            });
        });
        els.stepper = document.getElementById('bookflow-wizard-stepper');
        els.backBtn = document.getElementById('bookflow-wizard-back');
        els.nextBtn2 = document.getElementById('bookflow-wizard-next');

        if (!els.calendar) return;

        renderWeekdays();
        bindEvents();
        bindWizardNav();

        // Enter the wizard at its first applicable step — skips "language"
        // if the product has no schedule/language variants to choose from.
        var firstStep = WIZARD_STEPS[0];
        for (var si = 0; si < WIZARD_STEPS.length; si++) {
            if (stepApplicable(WIZARD_STEPS[si])) { firstStep = WIZARD_STEPS[si]; break; }
        }
        goToStep(firstStep);
    }

    // === Wizard step machine ===

    function stepEl(step) {
        return document.getElementById('bookflow-step-' + step);
    }

    function isStepComplete(step) {
        switch (step) {
            case 'language':
                return !bookflowBooking.hasSchedules || !!state.selectedSchedule;
            case 'location':
                return true; // optional informational step
            case 'day':
                return !!state.selectedDate;
            case 'staff':
                return !bookflowBooking.hasResources || !!state.selectedResource;
            case 'time':
                return !!state.selectedSlot;
            case 'persons':
                return state.persons >= (parseInt(bookflowBooking.minPersons) || 1);
            case 'contact':
                var name = document.getElementById('bookflow-customer-name');
                var phone = document.getElementById('bookflow-customer-phone');
                return !!(name && name.value.trim().length >= 3 && phone && phone.value.trim().length >= 6);
            case 'confirm':
                var termsBox = document.getElementById('bookflow-terms-accepted');
                return !termsBox || termsBox.checked;
            default:
                return true;
        }
    }

    function renderStepper() {
        if (!els.stepper) return;
        var labels = {
            language: bookflowBooking.i18n.stepLanguage,
            location: bookflowBooking.i18n.stepLocation,
            day: bookflowBooking.i18n.stepDay,
            staff: bookflowBooking.i18n.stepStaff,
            time: bookflowBooking.i18n.stepTime,
            persons: bookflowBooking.i18n.stepPersons,
            contact: bookflowBooking.i18n.stepContact,
            confirm: bookflowBooking.i18n.stepConfirm,
        };
        var visibleSteps = WIZARD_STEPS.filter(stepApplicable);
        var currentIndex = visibleSteps.indexOf(state.currentStep);
        var html = '';
        visibleSteps.forEach(function (step, i) {
            var cls = 'bookflow-stepper-item';
            if (i < currentIndex) cls += ' is-done';
            else if (i === currentIndex) cls += ' is-active';
            else cls += ' is-pending';
            html += '<div class="' + cls + '" data-step="' + step + '">' +
                '<span class="bookflow-stepper-num">' + (i + 1) + '</span>' +
                '<span class="bookflow-stepper-label">' + escapeHtml(labels[step] || step) + '</span>' +
                '</div>';
        });
        els.stepper.innerHTML = html;

        els.stepper.querySelectorAll('.bookflow-stepper-item.is-done').forEach(function (el) {
            el.addEventListener('click', function () {
                goToStep(el.dataset.step);
            });
        });
    }

    function updateWizardNav() {
        var idx = WIZARD_STEPS.indexOf(state.currentStep);
        if (els.backBtn) els.backBtn.style.visibility = idx > 0 ? 'visible' : 'hidden';
        if (els.nextBtn2) {
            var isLast = idx === WIZARD_STEPS.length - 1;
            els.nextBtn2.textContent = isLast
                ? (bookflowBooking.i18n.total || 'Total')
                : (bookflowBooking.i18n.wizardNext || 'Next');
            els.nextBtn2.style.display = isLast ? 'none' : '';
            els.nextBtn2.disabled = !isStepComplete(state.currentStep);
        }
    }

    function goToStep(step) {
        if (WIZARD_STEPS.indexOf(step) === -1) return;

        WIZARD_STEPS.forEach(function (s) {
            var el = stepEl(s);
            if (el) el.classList.toggle('bookflow-step-active', s === step);
        });

        state.currentStep = step;
        renderStepper();
        updateWizardNav();

        if (els.calendar) {
            els.calendar.closest('.bookflow-wizard').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // Lazy data loads on entry
        if (step === 'language') {
            initLanguageStep();
        } else if (step === 'location') {
            initLocationStep();
        } else if (step === 'day') {
            renderWeekdays();
            loadMonth();
        } else if (step === 'staff') {
            if (state.selectedDate) loadStaffForDate(state.selectedDate);
        } else if (step === 'time') {
            if (state.selectedDate) loadSlotsForDate(state.selectedDate);
        } else if (step === 'persons' || step === 'contact' || step === 'confirm') {
            updatePersonsVisibility();
            updatePrice();
        }

        checkFormReady();
    }

    // A step with nothing to choose (no language variants configured) is
    // skipped over entirely rather than shown empty.
    function stepApplicable(step) {
        if (step === 'language') return !!bookflowBooking.hasSchedules;
        return true;
    }

    function nextStep() {
        var idx = WIZARD_STEPS.indexOf(state.currentStep);
        while (idx < WIZARD_STEPS.length - 1) {
            idx++;
            if (stepApplicable(WIZARD_STEPS[idx])) {
                goToStep(WIZARD_STEPS[idx]);
                return;
            }
        }
    }

    function prevStep() {
        var idx = WIZARD_STEPS.indexOf(state.currentStep);
        while (idx > 0) {
            idx--;
            if (stepApplicable(WIZARD_STEPS[idx])) {
                goToStep(WIZARD_STEPS[idx]);
                return;
            }
        }
    }

    function bindWizardNav() {
        if (els.backBtn) els.backBtn.addEventListener('click', prevStep);
        if (els.nextBtn2) els.nextBtn2.addEventListener('click', function () {
            if (isStepComplete(state.currentStep)) nextStep();
        });
    }

    // === Language step ===

    function initLanguageStep() {
        if (!bookflowBooking.hasSchedules || !bookflowBooking.schedules || !bookflowBooking.schedules.length) {
            return;
        }

        var langInput = document.getElementById('bookflow-language');
        var optionsWrap = document.getElementById('bookflow-lang-options');
        var langDropdown = document.getElementById('bookflow-lang-select');
        if (!langInput || !optionsWrap || !langDropdown) return;

        if (!optionsWrap.dataset.built) {
            var seen = {};
            var html = '';
            bookflowBooking.schedules.forEach(function (s) {
                if (seen[s.option_value]) return;
                seen[s.option_value] = true;
                html += '<div class="bookflow-custom-select__option" data-value="' + escapeAttr(s.option_value) + '">' +
                    '<span class="bookflow-opt-time">' + escapeHtml(s.option_label) + '</span></div>';
            });
            optionsWrap.innerHTML = html;
            optionsWrap.dataset.built = '1';

            var trigger = langDropdown.querySelector('.bookflow-custom-select__trigger');
            trigger.addEventListener('click', function () {
                langDropdown.classList.toggle('bookflow-open');
            });

            optionsWrap.querySelectorAll('.bookflow-custom-select__option').forEach(function (opt) {
                opt.addEventListener('click', function () {
                    optionsWrap.querySelectorAll('.bookflow-custom-select__option').forEach(function (o) {
                        o.classList.remove('bookflow-selected');
                    });
                    opt.classList.add('bookflow-selected');
                    trigger.querySelector('span').textContent = opt.querySelector('.bookflow-opt-time').textContent;
                    langDropdown.classList.remove('bookflow-open');

                    var val = opt.dataset.value;
                    langInput.value = val;
                    setScheduleFromLang(val);

                    resetAfterLanguageChange();
                    updateWizardNav();
                });
            });

            document.addEventListener('click', function (e) {
                if (!langDropdown.contains(e.target)) {
                    langDropdown.classList.remove('bookflow-open');
                }
            });
        }

        // Auto-select if only one language variant
        if (!state.selectedSchedule) {
            var firstOpt = optionsWrap.querySelector('.bookflow-custom-select__option');
            var uniqueValues = {};
            bookflowBooking.schedules.forEach(function (s) { uniqueValues[s.option_value] = true; });
            if (firstOpt && Object.keys(uniqueValues).length === 1) {
                firstOpt.click();
            }
        }
    }

    function resetAfterLanguageChange() {
        state.selectedDate = null;
        state.selectedSlot = null;
        state.selectedResource = null;
        state.slotAvailable = 0;
        state.persons = parseInt(bookflowBooking.minPersons) || 1;
        state.monthData = {};
        if (els.dateInput) els.dateInput.value = '';
        if (els.timeInput) els.timeInput.value = '';
        if (els.resourceInput) els.resourceInput.value = '';
        if (els.personsInput) els.personsInput.value = state.persons;
        updateSpotsLeft();
    }

    function setScheduleFromLang(langValue) {
        if (!bookflowBooking.schedules) return;
        state.selectedSchedule = null;
        state.selectedScheduleIds = [];
        for (var i = 0; i < bookflowBooking.schedules.length; i++) {
            if (bookflowBooking.schedules[i].option_value === langValue) {
                state.selectedScheduleIds.push(bookflowBooking.schedules[i].id);
                if (!state.selectedSchedule) {
                    state.selectedSchedule = bookflowBooking.schedules[i].id;
                }
            }
        }
        if (els.scheduleInput) els.scheduleInput.value = state.selectedSchedule || '';
    }

    // === Location step ===

    function initLocationStep() {
        if (!els.locationsGrid) return;
        if (els.locationsGrid.dataset.loaded) return;

        els.locationsGrid.innerHTML = '<div class="bookflow-loading">' + bookflowBooking.i18n.loading + '</div>';

        restFetch('locations').then(function (locations) {
            els.locationsGrid.dataset.loaded = '1';
            renderLocations(locations || []);
        }).catch(function () {
            els.locationsGrid.innerHTML = '';
        });
    }

    function renderLocations(locations) {
        if (!locations.length) {
            els.locationsGrid.innerHTML = '';
            return;
        }

        var html = '';
        locations.forEach(function (loc) {
            var isCurrent = bookflowBooking.currentLocationId && loc.id === bookflowBooking.currentLocationId;
            html += '<button type="button" class="bookflow-location' + (isCurrent ? ' bookflow-location-selected' : '') + '" data-id="' + loc.id + '" data-slug="' + escapeAttr(loc.slug) + '">';
            html += '<span class="bookflow-location-name">' + escapeHtml(loc.name) + '</span>';
            if (loc.address) {
                html += '<span class="bookflow-location-address">' + escapeHtml(loc.address) + '</span>';
            }
            html += '</button>';
        });
        els.locationsGrid.innerHTML = html;

        if (bookflowBooking.currentLocation) {
            state.selectedLocationTag = bookflowBooking.currentLocation;
            if (els.locationInput) els.locationInput.value = state.selectedLocationTag;
        }

        els.locationsGrid.querySelectorAll('.bookflow-location').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.dataset.id, 10);
                var slug = btn.dataset.slug;
                els.locationsGrid.querySelectorAll('.bookflow-location').forEach(function (b) {
                    b.classList.remove('bookflow-location-selected');
                });
                btn.classList.add('bookflow-location-selected');
                state.selectedLocationTag = slug;
                if (els.locationInput) els.locationInput.value = slug;

                if (bookflowBooking.currentLocationId && id === bookflowBooking.currentLocationId) {
                    nextStep();
                    return;
                }
                swapToLocation(id);
            });
        });
    }

    function swapToLocation(locationId) {
        els.locationsGrid.classList.add('bookflow-loading-overlay');
        restFetch('services?location_id=' + encodeURIComponent(locationId)).then(function (services) {
            var svc = (services || [])[0];
            if (!svc) throw new Error('no_service_for_location');
            return restFetch('booking-data/' + svc.id);
        }).then(function (data) {
            bookflowBooking.productId = data.productId;
            bookflowBooking.minPersons = data.minPersons;
            bookflowBooking.maxPersons = data.maxPersons;
            bookflowBooking.hasPersonTypes = data.hasPersonTypes;
            bookflowBooking.hasResources = data.hasResources;
            bookflowBooking.hasSchedules = data.hasSchedules;
            bookflowBooking.schedules = data.schedules;
            bookflowBooking.currentLocation = data.currentLocation;
            bookflowBooking.currentLocationId = data.currentLocationId;

            // Reset dependent state for the new product
            state.selectedSchedule = null;
            state.selectedScheduleIds = [];
            state.monthData = {};
            resetAfterLanguageChange();

            var langOptions = document.getElementById('bookflow-lang-options');
            if (langOptions) { langOptions.innerHTML = ''; delete langOptions.dataset.built; }

            els.locationsGrid.classList.remove('bookflow-loading-overlay');
            nextStep();
        }).catch(function () {
            els.locationsGrid.classList.remove('bookflow-loading-overlay');
            showError();
        });
    }

    function restFetch(path) {
        return fetch(bookflowBooking.restUrl + path, {
            headers: { 'X-WP-Nonce': bookflowBooking.restNonce },
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    function renderWeekdays() {
        var days = [
            bookflowBooking.i18n.mon, bookflowBooking.i18n.tue, bookflowBooking.i18n.wed,
            bookflowBooking.i18n.thu, bookflowBooking.i18n.fri, bookflowBooking.i18n.sat,
            bookflowBooking.i18n.sun
        ];
        if (els.calWeekdays) {
            els.calWeekdays.innerHTML = days.map(function (d) {
                return '<span class="bookflow-weekday">' + d + '</span>';
            }).join('');
        }
    }

    function bindEvents() {
        els.prevBtn.addEventListener('click', function () {
            state.month--;
            if (state.month < 1) { state.month = 12; state.year--; }
            loadMonth();
        });

        els.nextBtn.addEventListener('click', function () {
            state.month++;
            if (state.month > 12) { state.month = 1; state.year++; }
            loadMonth();
        });

        // Persons stepper: -, +, and direct input
        var personsMin = parseInt(bookflowBooking.minPersons) || 1;
        var personsMax = parseInt(bookflowBooking.maxPersons) || 20;

        function getEffectiveMax() {
            return state.slotAvailable > 0 ? Math.min(personsMax, state.slotAvailable) : personsMax;
        }

        function setPersons(n) {
            var max = getEffectiveMax();
            n = Math.max(personsMin, Math.min(max, n));
            if (n === state.persons) return;
            state.persons = n;
            if (els.personsInput) els.personsInput.value = n;
            updateSpotsLeft();
            updatePrice();
            updateWizardNav();
        }

        if (els.personsMinus) {
            els.personsMinus.addEventListener('click', function () {
                setPersons(state.persons - 1);
            });
        }

        if (els.personsPlus) {
            els.personsPlus.addEventListener('click', function () {
                setPersons(state.persons + 1);
            });
        }

        if (els.personsInput) {
            els.personsInput.addEventListener('input', function () {
                var val = parseInt(this.value) || 0;
                if (val >= personsMin && val <= getEffectiveMax()) {
                    state.persons = val;
                    updateSpotsLeft();
                    updatePrice();
                    updateWizardNav();
                }
            });
            els.personsInput.addEventListener('blur', function () {
                setPersons(parseInt(this.value) || personsMin);
            });
        }

        // === Contact field validation ===
        var i18nErr = (bookflowBooking.i18n || {});
        var fieldRules = {
            name: {
                required: true,
                test: function (v) { return v.trim().length >= 3 && v.trim().indexOf(' ') !== -1; },
                error: i18nErr.errorName || 'Introduceți numele și prenumele'
            },
            email: {
                required: false,
                test: function (v) { return !v || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); },
                error: i18nErr.errorEmail || 'Adresa de email nu este validă'
            },
            phone: {
                required: true,
                test: function (v) { return /^[\+]?[0-9\s\-\(\)]{6,20}$/.test(v.trim()); },
                error: i18nErr.errorPhone || 'Introduceți un număr de telefon valid'
            }
        };

        function validateField(fieldEl, opts) {
            opts = opts || {};
            var wrapper = fieldEl.closest('.bookflow-field');
            if (!wrapper) return true;
            var rule = fieldRules[wrapper.dataset.validate];
            if (!rule) return true;

            var val = fieldEl.value;
            var trimmed = val.trim();
            var errorEl = wrapper.querySelector('.bookflow-field-error');

            var clearUI = function () {
                wrapper.classList.remove('field-invalid', 'field-valid');
                fieldEl.removeAttribute('aria-invalid');
                if (errorEl) { errorEl.textContent = ''; errorEl.removeAttribute('role'); }
            };

            if (!trimmed) {
                if (rule.required) {
                    fieldEl.setCustomValidity(rule.error);
                    if (opts.showRequired) {
                        wrapper.classList.remove('field-valid');
                        wrapper.classList.add('field-invalid');
                        fieldEl.setAttribute('aria-invalid', 'true');
                        if (errorEl) {
                            errorEl.textContent = rule.error;
                            errorEl.setAttribute('role', 'alert');
                        }
                    } else {
                        clearUI();
                    }
                    return false;
                }
                fieldEl.setCustomValidity('');
                clearUI();
                return true;
            }

            if (rule.test(val)) {
                fieldEl.setCustomValidity('');
                wrapper.classList.remove('field-invalid');
                wrapper.classList.add('field-valid');
                fieldEl.setAttribute('aria-invalid', 'false');
                if (errorEl) { errorEl.textContent = ''; errorEl.removeAttribute('role'); }
                return true;
            }

            fieldEl.setCustomValidity(rule.error);
            wrapper.classList.remove('field-valid');
            wrapper.classList.add('field-invalid');
            fieldEl.setAttribute('aria-invalid', 'true');
            if (errorEl) {
                errorEl.textContent = rule.error;
                errorEl.setAttribute('role', 'alert');
            }
            return false;
        }

        document.querySelectorAll('.bookflow-field input').forEach(function (input) {
            var wrapper = input.closest('.bookflow-field');
            var errorEl = wrapper && wrapper.querySelector('.bookflow-field-error');
            if (errorEl && !errorEl.id) {
                errorEl.id = 'err-' + (input.id || Math.random().toString(36).slice(2, 8));
            }
            if (errorEl) input.setAttribute('aria-describedby', errorEl.id);

            input.addEventListener('blur', function () {
                if (wrapper) wrapper.classList.add('was-touched');
                validateField(this);
                updateWizardNav();
                maybeSavePartial();
            });
            input.addEventListener('input', function () {
                if (wrapper && wrapper.classList.contains('was-touched')) {
                    validateField(this);
                }
                updateWizardNav();
                checkFormReady();
            });
        });
        window.bookflowValidateField = validateField;

        // === Form submit validation (final add-to-cart click) ===
        var cartForm = document.querySelector('form.cart');
        if (cartForm) {
            cartForm.addEventListener('submit', function (e) {
                document.querySelectorAll('.bookflow-error').forEach(function (el) {
                    el.classList.remove('bookflow-error');
                });

                var errorStep = null;

                if (!els.dateInput || !els.dateInput.value) {
                    errorStep = 'day';
                } else if (!els.timeInput || !els.timeInput.value) {
                    errorStep = 'time';
                } else if (bookflowBooking.hasResources && !state.selectedResource) {
                    errorStep = 'staff';
                } else if (bookflowBooking.hasPersonTypes && els.personTypesSection) {
                    var total = 0;
                    document.querySelectorAll('.bookflow-pt-qty').forEach(function (input) {
                        total += parseInt(input.value) || 0;
                    });
                    if (total < 1) errorStep = 'persons';
                } else if (els.personsInput) {
                    var min = parseInt(bookflowBooking.minPersons) || 1;
                    var persons = parseInt(els.personsInput.value) || 0;
                    if (persons < min) errorStep = 'persons';
                }

                if (!errorStep) {
                    var allValid = true;
                    var firstInvalid = null;
                    document.querySelectorAll('.bookflow-field input').forEach(function (input) {
                        if (!validateField(input, { showRequired: true }) && allValid) {
                            allValid = false;
                            firstInvalid = input.closest('.bookflow-field');
                        }
                    });
                    if (!allValid) {
                        errorStep = 'contact';
                        if (firstInvalid) firstInvalid.querySelector('input').focus();
                    }
                }

                if (!errorStep) {
                    var termsBox = document.getElementById('bookflow-terms-accepted');
                    if (termsBox && !termsBox.checked) {
                        errorStep = 'confirm';
                    }
                }

                // Payment method — only enforced if the theme/skin actually
                // renders payment radios; the default wizard template doesn't,
                // so this must never block a submit when none exist.
                if (!errorStep) {
                    var paymentRadios = document.querySelectorAll('input[name="bookflow_payment"]');
                    if (paymentRadios.length && !document.querySelector('input[name="bookflow_payment"]:checked')) {
                        errorStep = 'confirm';
                    }
                }

                if (errorStep) {
                    e.preventDefault();
                    goToStep(errorStep);
                    var target = stepEl(errorStep);
                    if (target) {
                        target.classList.add('bookflow-error');
                        setTimeout(function () { target.classList.remove('bookflow-error'); }, 1500);
                    }
                    return;
                }

                // Re-check availability before submit (prevent double-booking)
                var submitBtn = document.getElementById('bookflow-submit');
                if (submitBtn && !submitBtn.classList.contains('is-loading')) {
                    e.preventDefault();
                    submitBtn.classList.add('is-loading');
                    submitBtn.disabled = true;

                    var checkData = {
                        product_id: bookflowBooking.productId,
                        date: els.dateInput.value,
                        start_time: els.timeInput.value,
                    };
                    if (state.selectedSchedule) checkData.schedule_id = state.selectedSchedule;
                    if (state.selectedResource) checkData.resource_id = state.selectedResource;

                    ajax('bookflow_get_available_slots', checkData, function (res) {
                        var slotStillAvailable = false;
                        var selectedTime = els.timeInput.value;
                        if (res.slots && res.slots.length > 0) {
                            res.slots.forEach(function (s) {
                                if (s.time === selectedTime && !s.is_full && s.available >= state.persons) {
                                    slotStillAvailable = true;
                                }
                            });
                        }

                        if (slotStillAvailable) {
                            submitBtn.classList.remove('is-loading');
                            cartForm.submit();
                        } else {
                            submitBtn.classList.remove('is-loading');
                            submitBtn.disabled = false;
                            state.monthData = {};
                            goToStep('time');
                            loadSlotsForDate(state.selectedDate);

                            var timeStep = stepEl('time');
                            timeStep.classList.add('bookflow-error');
                            setTimeout(function () { timeStep.classList.remove('bookflow-error'); }, 2000);
                            showError(bookflowBooking.i18n.noSlots || 'This slot is no longer available');
                        }
                    }, function () {
                        submitBtn.classList.remove('is-loading');
                        submitBtn.disabled = false;
                        showError();
                    });
                }
            });
        }

        // Person type +/- buttons
        document.querySelectorAll('.bookflow-pt-minus').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('.bookflow-person-type-row');
                var input = row.querySelector('.bookflow-pt-qty');
                var min = parseInt(input.min) || 0;
                var val = parseInt(input.value) || 0;
                if (val > min) {
                    input.value = val - 1;
                    updatePersonTypesTotal();
                    updatePrice();
                    updateWizardNav();
                }
            });
        });

        document.querySelectorAll('.bookflow-pt-plus').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('.bookflow-person-type-row');
                var input = row.querySelector('.bookflow-pt-qty');
                var max = parseInt(input.max) || 20;
                var val = parseInt(input.value) || 0;
                if (val < max) {
                    input.value = val + 1;
                    updatePersonTypesTotal();
                    updatePrice();
                    updateWizardNav();
                }
            });
        });
    }

    function updatePersonTypesTotal() {
        var total = 0;
        document.querySelectorAll('.bookflow-pt-qty').forEach(function (input) {
            total += parseInt(input.value) || 0;
        });
        if (els.personsTotalInput) {
            els.personsTotalInput.value = total;
        }
        state.persons = total;
    }

    function updatePersonsVisibility() {
        if (bookflowBooking.hasPersonTypes && els.personTypesSection) {
            updatePersonTypesTotal();
        }
    }

    function updateSpotsLeft() {
        var spotsEl = document.getElementById(bookflowBooking.spotsElement || 'bookflow-spots-left');
        if (!spotsEl) return;

        if (state.slotAvailable > 0) {
            var remaining = Math.max(0, state.slotAvailable - state.persons);
            var template = bookflowBooking.i18n.spotsRemaining || 'Spots remaining: %d';
            spotsEl.textContent = template.replace('%d', remaining);
            spotsEl.style.opacity = '1';
        } else {
            spotsEl.textContent = '';
            spotsEl.style.opacity = '0';
        }
    }

    function loadMonth() {
        var ids = state.selectedScheduleIds || (state.selectedSchedule ? [state.selectedSchedule] : []);
        var schedKey = ids.length > 0 ? ids.join('_') : '0';
        var key = state.year + '-' + state.month + '-s' + schedKey;
        els.calMonth.textContent = getMonthName(state.month) + ' ' + state.year;

        if (state.monthData[key]) {
            renderDays(state.monthData[key]);
            return;
        }

        var skeleton = '';
        for (var sk = 0; sk < 42; sk++) {
            skeleton += '<span class="bookflow-day bookflow-day-skeleton"></span>';
        }
        els.calDays.innerHTML = skeleton;

        if (ids.length <= 1) {
            var params = { product_id: bookflowBooking.productId, year: state.year, month: state.month };
            if (ids.length === 1) params.schedule_id = ids[0];
            ajax('bookflow_get_month_availability', params, function (data) {
                state.monthData[key] = data.calendar;
                renderDays(data.calendar);
            }, function () {
                els.calDays.innerHTML = '<div class="bookflow-cal-message">' + (bookflowBooking.i18n.errorGeneric || 'Could not load') + '</div>';
            });
        } else {
            var pending = ids.length;
            var merged = {};

            ids.forEach(function (sid) {
                ajax('bookflow_get_month_availability', {
                    product_id: bookflowBooking.productId,
                    year: state.year,
                    month: state.month,
                    schedule_id: sid,
                }, function (data) {
                    for (var dateStr in data.calendar) {
                        var day = data.calendar[dateStr];
                        if (!merged[dateStr]) {
                            merged[dateStr] = { date: dateStr, available: false, slots: 0 };
                        }
                        if (day.available) {
                            merged[dateStr].available = true;
                            merged[dateStr].slots += day.slots;
                        }
                    }
                    pending--;
                    if (pending === 0) {
                        state.monthData[key] = merged;
                        renderDays(merged);
                    }
                });
            });
        }
    }

    function renderDays(calendar) {
        var firstDay = new Date(state.year, state.month - 1, 1).getDay();
        firstDay = firstDay === 0 ? 6 : firstDay - 1;

        var html = '';
        for (var i = 0; i < firstDay; i++) {
            html += '<span class="bookflow-day bookflow-day-empty"></span>';
        }

        var daysInMonth = new Date(state.year, state.month, 0).getDate();
        var anyAvailable = false;

        for (var d = 1; d <= daysInMonth; d++) {
            var dateStr = state.year + '-' + pad(state.month) + '-' + pad(d);
            var dayData = calendar[dateStr];
            var available = dayData && dayData.available && dayData.slots > 0;
            var isSelected = state.selectedDate === dateStr;
            if (available) anyAvailable = true;

            var cls = 'bookflow-day';
            if (!available) cls += ' bookflow-day-unavailable';
            if (isSelected) cls += ' bookflow-day-selected';

            if (available) {
                var label = formatDateLabel(dateStr) + ', ' + (bookflowBooking.i18n.available || 'available');
                html += '<span class="' + cls + '" data-date="' + dateStr + '" role="button" tabindex="0"' +
                    ' aria-label="' + label + '" aria-pressed="' + (isSelected ? 'true' : 'false') + '">' + d + '</span>';
            } else {
                html += '<span class="' + cls + '" aria-disabled="true">' + d + '</span>';
            }
        }

        var totalCells = firstDay + daysInMonth;
        while (totalCells < 42) {
            html += '<span class="bookflow-day bookflow-day-empty"></span>';
            totalCells++;
        }

        if (!anyAvailable) {
            html += '<div class="bookflow-cal-message">' + (bookflowBooking.i18n.noAvailability || 'No availability this month.') + '</div>';
        }

        els.calDays.innerHTML = html;

        els.calDays.querySelectorAll('.bookflow-day[data-date]').forEach(function (el) {
            el.addEventListener('click', function () {
                selectDate(el.dataset.date);
            });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                    e.preventDefault();
                    selectDate(el.dataset.date);
                }
            });
        });
    }

    function formatDateLabel(dateStr) {
        var parts = dateStr.split('-');
        var day = parseInt(parts[2], 10);
        var month = getMonthName(parseInt(parts[1], 10));
        return day + ' ' + month + ' ' + parts[0];
    }

    function selectDate(date) {
        state.selectedDate = date;
        state.selectedSlot = null;
        state.selectedResource = null;
        state.slotScheduleMap = {};
        if (els.dateInput) els.dateInput.value = date;
        if (els.timeInput) els.timeInput.value = '';
        if (els.resourceInput) els.resourceInput.value = '';

        var ids = state.selectedScheduleIds || (state.selectedSchedule ? [state.selectedSchedule] : []);
        var schedKey = ids.length > 0 ? ids.join('_') : '0';
        var key = state.year + '-' + state.month + '-s' + schedKey;
        if (state.monthData[key]) {
            renderDays(state.monthData[key]);
        }

        updateWizardNav();
        checkFormReady();
        nextStep();
    }

    // === Staff (guide/person who performs the trip) step — runs before time ===

    function loadStaffForDate(date) {
        els.resourcesGrid.innerHTML = '<div class="bookflow-loading">' + bookflowBooking.i18n.loading + '</div>';

        var params = { product_id: bookflowBooking.productId, date: date };
        if (state.selectedSchedule) params.schedule_id = state.selectedSchedule;

        ajax('bookflow_get_resources_for_date', params, function (data) {
            renderStaff(data.resources);
        }, function () {
            els.resourcesGrid.innerHTML = '<p class="bookflow-no-slots">' + (bookflowBooking.i18n.errorGeneric || 'Could not load') + '</p>';
        });
    }

    function renderStaff(resources) {
        if (!resources || resources.length === 0) {
            els.resourcesGrid.innerHTML = '<p class="bookflow-no-slots">' + (bookflowBooking.i18n.noAvailability || 'No guides available this day.') + '</p>';
            return;
        }

        var html = '';
        resources.forEach(function (r) {
            html += '<button type="button" class="bookflow-resource" data-id="' + r.id + '">';
            if (r.photoUrl) {
                html += '<img class="bookflow-resource-photo" src="' + escapeAttr(r.photoUrl) + '" alt="" loading="lazy">';
            }
            html += '<span class="bookflow-resource-name">' + escapeHtml(r.title) + '</span>';
            if (r.ratingCount > 0) {
                html += '<span class="bookflow-resource-rating">&#9733; ' + r.avgRating.toFixed(1) + ' &middot; ' + r.ratingCount + '</span>';
            }
            if (r.description) {
                html += '<span class="bookflow-resource-description">' + escapeHtml(r.description) + '</span>';
            }
            html += '<span class="bookflow-resource-detail">' + r.capacity + ' pax';
            if (r.cost > 0) {
                html += ' &middot; +' + bookflowBooking.currency + r.cost;
            }
            html += '</span>';
            html += '</button>';
        });

        els.resourcesGrid.innerHTML = html;

        els.resourcesGrid.querySelectorAll('.bookflow-resource').forEach(function (el) {
            el.addEventListener('click', function () {
                els.resourcesGrid.querySelectorAll('.bookflow-resource').forEach(function (s) {
                    s.classList.remove('bookflow-resource-selected');
                });
                el.classList.add('bookflow-resource-selected');

                state.selectedResource = el.dataset.id;
                if (els.resourceInput) els.resourceInput.value = el.dataset.id;

                updateWizardNav();
                nextStep();
            });
        });
    }

    // === Time / Slots step ===

    function loadSlotsForDate(date) {
        els.slotsGrid.innerHTML = '<div class="bookflow-loading">' + bookflowBooking.i18n.loading + '</div>';

        var ids = state.selectedScheduleIds || (state.selectedSchedule ? [state.selectedSchedule] : []);

        if (ids.length <= 1) {
            var params = { product_id: bookflowBooking.productId, date: date };
            if (ids.length === 1) params.schedule_id = ids[0];
            if (state.selectedResource) params.resource_id = state.selectedResource;
            ajax('bookflow_get_available_slots', params, function (data) {
                if (data.slots) data.slots.forEach(function (s) { state.slotScheduleMap[s.time] = ids[0]; });
                renderSlots(data.slots);
            }, function () {
                els.slotsGrid.innerHTML = '<p class="bookflow-no-slots">' + (bookflowBooking.i18n.errorGeneric || 'Could not load') + '</p>';
            });
        } else {
            var pending = ids.length;
            var allSlots = {};

            ids.forEach(function (sid) {
                var p = { product_id: bookflowBooking.productId, date: date, schedule_id: sid };
                if (state.selectedResource) p.resource_id = state.selectedResource;
                ajax('bookflow_get_available_slots', p, function (data) {
                    if (data.slots) {
                        data.slots.forEach(function (s) {
                            if (!allSlots[s.time]) {
                                allSlots[s.time] = s;
                                state.slotScheduleMap[s.time] = sid;
                            } else if (s.available > allSlots[s.time].available) {
                                allSlots[s.time] = s;
                                state.slotScheduleMap[s.time] = sid;
                            }
                        });
                    }
                    pending--;
                    if (pending === 0) {
                        var merged = Object.values(allSlots).sort(function (a, b) {
                            return a.time.localeCompare(b.time);
                        });
                        renderSlots(merged);
                    }
                });
            });
        }
    }

    function renderSlots(slots) {
        if (!slots || slots.length === 0) {
            els.slotsGrid.innerHTML = '<p class="bookflow-no-slots">' + bookflowBooking.i18n.noSlots + '</p>';
            return;
        }

        var html = '<div class="bookflow-slots-list">';
        slots.forEach(function (slot) {
            var full = !!slot.is_full;
            var cls = 'bookflow-slot-option' + (full ? ' bookflow-slot-full' : '');
            var caption = full
                ? (bookflowBooking.i18n.soldOut || 'Sold out')
                : (bookflowBooking.i18n.spotsOfMax || '%d of %d available')
                    .replace('%d', slot.available).replace('%d', slot.max_persons);

            html += '<button type="button" class="' + cls + '" data-time="' + escapeAttr(slot.time) + '"' +
                ' data-price="' + slot.price + '" data-available="' + slot.available + '"' +
                (full ? ' aria-disabled="true" disabled' : '') + '>';
            html += '<span class="bookflow-slot-time">' + escapeHtml(slot.time) + '</span>';
            html += '<span class="bookflow-slot-caption">' + escapeHtml(caption) + '</span>';
            html += '</button>';
        });
        html += '</div>';

        els.slotsGrid.innerHTML = html;

        els.slotsGrid.querySelectorAll('.bookflow-slot-option:not(.bookflow-slot-full)').forEach(function (opt) {
            opt.addEventListener('click', function () {
                els.slotsGrid.querySelectorAll('.bookflow-slot-option').forEach(function (o) {
                    o.classList.remove('bookflow-slot-selected');
                });
                opt.classList.add('bookflow-slot-selected');

                state.slotAvailable = parseInt(opt.dataset.available) || 0;
                updateSpotsLeft();
                selectSlot(opt.dataset.time);
            });
        });

        // Auto-select if only one open slot
        var openSlots = slots.filter(function (s) { return !s.is_full; });
        if (openSlots.length === 1) {
            var onlyOpt = els.slotsGrid.querySelector('.bookflow-slot-option[data-time="' + openSlots[0].time + '"]');
            if (onlyOpt) {
                onlyOpt.classList.add('bookflow-slot-selected');
                state.slotAvailable = parseInt(onlyOpt.dataset.available) || 0;
                updateSpotsLeft();
                selectSlot(onlyOpt.dataset.time);
            }
        }
    }

    function selectSlot(time) {
        state.selectedSlot = time;
        if (els.timeInput) els.timeInput.value = time;

        if (state.slotScheduleMap && state.slotScheduleMap[time]) {
            state.selectedSchedule = state.slotScheduleMap[time];
            if (els.scheduleInput) els.scheduleInput.value = state.selectedSchedule;
        }

        updateWizardNav();
        checkFormReady();
        nextStep();
    }

    // Capture partial contact info on blur (name/phone/email) so an
    // abandoned booking can get a "still interested?" follow-up later.
    // Debounced + only fires once there's an email or phone worth having.
    var savePartialTimer = null;
    function maybeSavePartial() {
        clearTimeout(savePartialTimer);
        savePartialTimer = setTimeout(function () {
            var nameEl = document.getElementById('bookflow-customer-name');
            var phoneEl = document.getElementById('bookflow-customer-phone');
            var emailEl = document.getElementById('bookflow-customer-email');
            var phone = phoneEl ? phoneEl.value.trim() : '';
            var email = emailEl ? emailEl.value.trim() : '';
            if (phone.length < 6 && !email) return;

            ajax('bookflow_save_partial', {
                product_id: bookflowBooking.productId,
                name: nameEl ? nameEl.value.trim() : '',
                phone: phone,
                email: email,
                step: state.currentStep,
            }, function () {}, function () {});
        }, 800);
    }

    function updatePrice() {
        if (!state.selectedDate || !state.selectedSlot) return;

        var data = {
            product_id: bookflowBooking.productId,
            date: state.selectedDate,
            start_time: state.selectedSlot,
        };

        if (state.selectedSchedule) data.schedule_id = state.selectedSchedule;
        if (state.selectedResource) data.resource_id = state.selectedResource;

        if (bookflowBooking.hasPersonTypes) {
            var ptRows = document.querySelectorAll('.bookflow-person-type-row');
            ptRows.forEach(function (row, i) {
                var typeId = row.dataset.typeId;
                var qty = parseInt(row.querySelector('.bookflow-pt-qty').value) || 0;
                data['person_types[' + i + '][person_type_id]'] = typeId;
                data['person_types[' + i + '][quantity]'] = qty;
            });
        } else {
            data.persons = state.persons;
        }

        document.querySelectorAll('.bookflow-extra-check:checked').forEach(function (cb, i) {
            data['extras[' + i + ']'] = cb.value;
        });

        ajax('bookflow_calculate_price', data, function (res) {
            if (els.pricePerPerson) fadeUpdate(els.pricePerPerson, function () {
                els.pricePerPerson.innerHTML = res.price_per_person_formatted;
            });
            if (els.summaryPersons) els.summaryPersons.textContent = res.persons;
            if (els.totalPrice) fadeUpdate(els.totalPrice, function () {
                els.totalPrice.innerHTML = res.total_formatted;
            });
            if (els.depositRow && els.balanceRow) {
                if (res.deposit_amount_formatted) {
                    els.depositRow.classList.remove('bookflow-hidden');
                    els.balanceRow.classList.remove('bookflow-hidden');
                    if (els.depositAmount) els.depositAmount.innerHTML = res.deposit_amount_formatted;
                    if (els.balanceAmount) els.balanceAmount.innerHTML = res.balance_due_formatted;
                } else {
                    els.depositRow.classList.add('bookflow-hidden');
                    els.balanceRow.classList.add('bookflow-hidden');
                }
            }
        });
    }

    // Helpers
    function ajax(action, data, callback, onError) {
        data.action = action;
        data.nonce = bookflowBooking.nonce;

        var formData = new FormData();
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                formData.append(key, data[key]);
            }
        }

        fetch(bookflowBooking.ajaxUrl, {
            method: 'POST',
            body: formData,
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (res) {
            if (res && res.success) {
                if (callback) callback(res.data);
            } else {
                throw new Error((res && res.data && res.data.message) || 'request_failed');
            }
        })
        .catch(function (err) {
            if (window.console) console.error('[bookflow] ' + action, err);
            if (typeof onError === 'function') {
                onError(err);
            } else {
                showError();
            }
        });
    }

    function showError(msg) {
        var text = msg || (bookflowBooking.i18n && bookflowBooking.i18n.errorGeneric) || 'Something went wrong. Please try again.';
        var t = document.getElementById('bookflow-toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'bookflow-toast';
            t.className = 'bookflow-toast';
            t.setAttribute('role', 'alert');
            document.body.appendChild(t);
        }
        t.textContent = text;
        t.classList.add('bookflow-toast--show');
        if (t._timer) clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.classList.remove('bookflow-toast--show'); }, 5000);
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function fadeUpdate(el, updateFn) {
        if (!el) return updateFn();
        el.style.opacity = '0';
        el.style.transition = 'opacity 0.12s ease';
        setTimeout(function () {
            updateFn();
            el.style.opacity = '1';
            el.style.transition = 'opacity 0.2s ease';
        }, 120);
    }

    function getMonthName(m) {
        if (bookflowBooking.i18n.months && bookflowBooking.i18n.months.length === 12) {
            return bookflowBooking.i18n.months[m - 1];
        }
        var months = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ];
        return months[m - 1];
    }

    // === Smart submit button: disabled until form is complete ===
    function checkFormReady() {
        var submitBtn = document.getElementById('bookflow-submit');
        if (submitBtn) {
            var ready = WIZARD_STEPS.every(isStepComplete);
            submitBtn.disabled = !ready;
        }
        updateWizardNav();
    }

    document.querySelectorAll('input[name="bookflow_payment"]').forEach(function (radio) {
        radio.addEventListener('change', checkFormReady);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
