/**
 * Bookflow - Calendar & Booking Form
 */
(function () {
    'use strict';

    if (typeof bookflowBooking === 'undefined') return;

    var state = {
        year: new Date().getFullYear(),
        month: new Date().getMonth() + 1,
        selectedDate: null,
        selectedSlot: null,
        selectedResource: null,
        selectedSchedule: null,
        slotAvailable: 0,
        persons: parseInt(bookflowBooking.minPersons) || 1,
        personTypes: {},
        monthData: {},
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
        els.slotsSection = document.getElementById('bookflow-slots-section');
        els.slotsGrid = document.getElementById('bookflow-slots-grid');
        els.timeInput = document.getElementById('bookflow-start-time');
        els.resourcesSection = document.getElementById('bookflow-resources-section');
        els.resourcesGrid = document.getElementById('bookflow-resources-grid');
        els.resourceInput = document.getElementById('bookflow-resource-id');
        els.personsSection = document.getElementById('bookflow-persons-section');
        els.personTypesSection = document.getElementById('bookflow-person-types-section');
        els.personsInput = document.getElementById('bookflow-persons');
        els.personsTotalInput = document.getElementById('bookflow-persons-total');
        els.personsMinus = document.getElementById('bookflow-persons-minus');
        els.personsPlus = document.getElementById('bookflow-persons-plus');
        els.contactSection = document.getElementById('bookflow-contact-section');
        els.summarySection = document.getElementById('bookflow-summary-section');
        els.pricePerPerson = document.getElementById('bookflow-price-per-person');
        els.summaryPersons = document.getElementById('bookflow-summary-persons');
        els.totalPrice = document.getElementById('bookflow-total-price');

        if (!els.calendar) return;

        // Detect schedule from language selector
        initSchedule();
        renderWeekdays();
        loadMonth();
        bindEvents();

    }

    function initSchedule() {
        if (!bookflowBooking.hasSchedules || !bookflowBooking.schedules) return;

        var langSelectorId = bookflowBooking.languageSelector || 'bookflow-language';
        var langDropdownId = bookflowBooking.langDropdown || 'bookflow-lang-select';

        var langInput = document.getElementById(langSelectorId);
        if (!langInput) return;

        // Set initial schedule
        setScheduleFromLang(langInput.value);

        // Bind custom language dropdown
        var langDropdown = document.getElementById(langDropdownId);
        if (langDropdown) {
            var trigger = langDropdown.querySelector('.bookflow-custom-select__trigger');
            trigger.addEventListener('click', function () {
                langDropdown.classList.toggle('bookflow-open');
            });

            langDropdown.querySelectorAll('.bookflow-custom-select__option').forEach(function (opt) {
                opt.addEventListener('click', function () {
                    langDropdown.querySelectorAll('.bookflow-custom-select__option').forEach(function (o) {
                        o.classList.remove('bookflow-selected');
                    });
                    opt.classList.add('bookflow-selected');
                    trigger.querySelector('span').textContent = opt.querySelector('.bookflow-opt-time').textContent;
                    langDropdown.classList.remove('bookflow-open');

                    var val = opt.dataset.value;
                    langInput.value = val;
                    setScheduleFromLang(val);

                    // Reset and reload calendar with smooth transition
                    state.selectedDate = null;
                    state.selectedSlot = null;
                    state.slotAvailable = 0;
                    state.persons = parseInt(bookflowBooking.minPersons) || 1;
                    state.monthData = {};
                    els.dateInput.value = '';
                    els.timeInput.value = '';
                    if (els.personsInput) els.personsInput.value = state.persons;
                    updateSpotsLeft();
                    hide(els.slotsSection);
                    hide(els.personsSection);
                    hide(els.personTypesSection);
                    hide(els.summarySection);

                    // Fade calendar out, reload, fade in
                    els.calDays.style.opacity = '0';
                    els.calDays.style.transition = 'opacity 0.15s ease';
                    setTimeout(function () {
                        loadMonth();
                        setTimeout(function () {
                            els.calDays.style.opacity = '1';
                            els.calDays.style.transition = 'opacity 0.3s ease';
                        }, 100);
                    }, 150);
                });
            });

            document.addEventListener('click', function (e) {
                if (!langDropdown.contains(e.target)) {
                    langDropdown.classList.remove('bookflow-open');
                }
            });
        }
    }

    function setScheduleFromLang(langValue) {
        if (!bookflowBooking.schedules) return;
        // Collect ALL schedule IDs for this language
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
        var schedInput = document.getElementById('bookflow-schedule-id');
        if (schedInput) schedInput.value = state.selectedSchedule || '';
    }

    function renderWeekdays() {
        var days = [
            bookflowBooking.i18n.mon, bookflowBooking.i18n.tue, bookflowBooking.i18n.wed,
            bookflowBooking.i18n.thu, bookflowBooking.i18n.fri, bookflowBooking.i18n.sat,
            bookflowBooking.i18n.sun
        ];
        els.calWeekdays.innerHTML = days.map(function (d) {
            return '<span class="bookflow-weekday">' + d + '</span>';
        }).join('');
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
            els.personsInput.value = n;
            updateSpotsLeft();
            if (typeof clampSouvenirQty === 'function') clampSouvenirQty();
            updatePrice();
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

        // Allow direct typing in the input
        if (els.personsInput) {
            els.personsInput.addEventListener('input', function () {
                var val = parseInt(this.value) || 0;
                if (val >= personsMin && val <= getEffectiveMax()) {
                    state.persons = val;
                    updateSpotsLeft();
                    updatePrice();
                }
            });
            els.personsInput.addEventListener('blur', function () {
                // Clamp on blur
                setPersons(parseInt(this.value) || personsMin);
            });
        }

        // === Contact field validation ===
        // Uses the native Constraint Validation API for accessibility (aria-invalid,
        // role=alert) and pairs it with progressive UX: validate on blur the first
        // time, then re-validate on every keystroke so errors clear as the user fixes them.
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

            // Empty field: required-empty errors are SILENT until submit
            // (matches polished forms like Stripe/GitHub — don't shout at users
            // for fields they haven't filled yet, only for malformed input).
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
                // Optional + empty = neutral
                fieldEl.setCustomValidity('');
                clearUI();
                return true;
            }

            // Has content — run format check
            if (rule.test(val)) {
                fieldEl.setCustomValidity('');
                wrapper.classList.remove('field-invalid');
                wrapper.classList.add('field-valid');
                fieldEl.setAttribute('aria-invalid', 'false');
                if (errorEl) { errorEl.textContent = ''; errorEl.removeAttribute('role'); }
                return true;
            }

            // Has content but malformed — always show error (this is the case
            // users care about on blur: "I typed something wrong, tell me now").
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

        // Wire each input: blur marks as touched + validates; subsequent input events re-validate live.
        document.querySelectorAll('.bookflow-field input').forEach(function (input) {
            var wrapper = input.closest('.bookflow-field');
            // Link the error span via aria-describedby for screen readers.
            var errorEl = wrapper && wrapper.querySelector('.bookflow-field-error');
            if (errorEl && !errorEl.id) {
                errorEl.id = 'err-' + (input.id || Math.random().toString(36).slice(2, 8));
            }
            if (errorEl) input.setAttribute('aria-describedby', errorEl.id);

            input.addEventListener('blur', function () {
                if (wrapper) wrapper.classList.add('was-touched');
                validateField(this);
            });
            input.addEventListener('input', function () {
                // Live revalidation only after first blur, so we don't yell at users mid-typing.
                if (wrapper && wrapper.classList.contains('was-touched')) {
                    validateField(this);
                }
            });
        });

        // === Form submit validation ===
        var cartForm = document.querySelector('form.cart');
        if (cartForm) {
            cartForm.addEventListener('submit', function (e) {
                // Clear previous section errors
                document.querySelectorAll('.bookflow-error').forEach(function (el) {
                    el.classList.remove('bookflow-error');
                });

                var errorTarget = null;

                // 1. Check date selected
                if (!els.dateInput || !els.dateInput.value) {
                    errorTarget = els.calendar;
                }
                // 2. Check time selected
                else if (!els.timeInput || !els.timeInput.value) {
                    errorTarget = els.slotsSection;
                }
                // 3. Check persons
                else if (bookflowBooking.hasPersonTypes && els.personTypesSection) {
                    var total = 0;
                    document.querySelectorAll('.bookflow-pt-qty').forEach(function (input) {
                        total += parseInt(input.value) || 0;
                    });
                    if (total < 1) {
                        errorTarget = els.personTypesSection;
                    }
                } else if (els.personsInput) {
                    var min = parseInt(bookflowBooking.minPersons) || 1;
                    var persons = parseInt(els.personsInput.value) || 0;
                    if (persons < min) {
                        errorTarget = els.personsSection;
                    }
                }

                // 4. Validate contact fields — at submit we DO show required-empty errors
                if (!errorTarget) {
                    var allValid = true;
                    var firstInvalid = null;
                    document.querySelectorAll('.bookflow-field input').forEach(function (input) {
                        if (!validateField(input, { showRequired: true }) && allValid) {
                            allValid = false;
                            firstInvalid = input.closest('.bookflow-field');
                        }
                    });
                    if (!allValid) {
                        errorTarget = document.getElementById('bookflow-contact-section');
                        if (firstInvalid) {
                            firstInvalid.querySelector('input').focus();
                        }
                    }
                }

                // 5. Validate payment method
                if (!errorTarget) {
                    var paymentSelected = document.querySelector('input[name="bookflow_payment"]:checked');
                    if (!paymentSelected) {
                        errorTarget = document.querySelector('.bookflow-payment');
                    }
                }

                if (errorTarget) {
                    e.preventDefault();
                    errorTarget.classList.add('bookflow-error');
                    errorTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(function () {
                        errorTarget.classList.remove('bookflow-error');
                    }, 1500);
                    return;
                }

                // 6. Re-check availability before submit (prevent double-booking)
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

                    ajax('bookflow_get_available_slots', checkData, function (res) {
                        var slotStillAvailable = false;
                        var selectedTime = els.timeInput.value;
                        if (res.slots && res.slots.length > 0) {
                            res.slots.forEach(function (s) {
                                if (s.time === selectedTime && s.available >= state.persons) {
                                    slotStillAvailable = true;
                                }
                            });
                        }

                        if (slotStillAvailable) {
                            // All good — submit
                            submitBtn.classList.remove('is-loading');
                            cartForm.submit();
                        } else {
                            // Slot taken — show error, reload calendar
                            submitBtn.classList.remove('is-loading');
                            submitBtn.disabled = false;
                            state.monthData = {};
                            loadMonth();

                            var slotsSection = els.slotsSection || els.calendar;
                            slotsSection.classList.add('bookflow-error');
                            slotsSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            setTimeout(function () {
                                slotsSection.classList.remove('bookflow-error');
                            }, 2000);

                            // Show a temporary message
                            var msg = document.createElement('div');
                            msg.className = 'bookflow-slot-taken-msg';
                            msg.textContent = bookflowBooking.i18n.noSlots || 'This slot is no longer available';
                            msg.style.cssText = 'text-align:center;color:#e74c3c;font-size:13px;padding:12px;border:1px solid rgba(231,76,60,0.3);margin:10px 0;';
                            els.slotsGrid.innerHTML = '';
                            els.slotsGrid.appendChild(msg);
                            setTimeout(function () { if (msg.parentNode) msg.remove(); }, 5000);
                        }
                    }, function () {
                        // Availability re-check failed (network/server) — never leave the button stuck
                        submitBtn.classList.remove('is-loading');
                        submitBtn.disabled = false;
                        showError();
                    });
                }
            });
        }

        // Souvenir checkbox + qty picker
        var souvenirCheckbox = document.getElementById('bookflow-souvenir-check');
        var souvenirQtyWrap = document.getElementById('bookflow-souvenir-qty');
        var souvenirQtyInput = document.getElementById('souvenir-qty-input');
        var souvenirSubtotal = document.getElementById('souvenir-subtotal');
        var souvenirMinus = document.getElementById('souvenir-qty-minus');
        var souvenirPlus = document.getElementById('souvenir-qty-plus');
        var souvenirUnitPrice = parseFloat(bookflowBooking.souvenirPrice) || 0;

        function updateSouvenirSubtotal() {
            if (!souvenirSubtotal || !souvenirQtyInput) return;
            var qty = parseInt(souvenirQtyInput.value) || 1;
            souvenirSubtotal.textContent = (souvenirUnitPrice * qty).toLocaleString('ro-RO') + ' MDL';
        }

        function getSouvenirMaxQty() {
            return Math.max(1, state.persons);
        }

        function clampSouvenirQty() {
            if (!souvenirQtyInput) return;
            var max = getSouvenirMaxQty();
            var val = parseInt(souvenirQtyInput.value) || 1;
            if (val > max) {
                souvenirQtyInput.value = max;
            }
            souvenirQtyInput.max = max;
            updateSouvenirSubtotal();
        }

        var souvenirSection = document.getElementById('bookflow-souvenir-section');

        if (souvenirCheckbox && souvenirSection) {
            souvenirCheckbox.addEventListener('change', function () {
                if (this.checked) {
                    souvenirSection.classList.add('souvenir-active');
                    clampSouvenirQty();
                } else {
                    souvenirSection.classList.remove('souvenir-active');
                }
                if (state.selectedSlot) updatePrice();
            });
        }

        if (souvenirMinus) {
            souvenirMinus.addEventListener('click', function () {
                var val = parseInt(souvenirQtyInput.value) || 1;
                if (val > 1) {
                    souvenirQtyInput.value = val - 1;
                    updateSouvenirSubtotal();
                    if (state.selectedSlot) updatePrice();
                }
            });
        }

        if (souvenirPlus) {
            souvenirPlus.addEventListener('click', function () {
                var val = parseInt(souvenirQtyInput.value) || 1;
                var max = getSouvenirMaxQty();
                if (val < max) {
                    souvenirQtyInput.value = val + 1;
                    updateSouvenirSubtotal();
                    if (state.selectedSlot) updatePrice();
                }
            });
        }

        if (souvenirQtyInput) {
            souvenirQtyInput.addEventListener('input', function () {
                var val = parseInt(this.value) || 1;
                var max = getSouvenirMaxQty();
                if (val > max) this.value = max;
                if (val < 1) this.value = 1;
                updateSouvenirSubtotal();
                if (state.selectedSlot) updatePrice();
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

        // Skeleton
        var skeleton = '';
        for (var sk = 0; sk < 42; sk++) {
            skeleton += '<span class="bookflow-day bookflow-day-skeleton"></span>';
        }
        els.calDays.innerHTML = skeleton;

        if (ids.length <= 1) {
            // Single schedule or none — simple request
            var params = { product_id: bookflowBooking.productId, year: state.year, month: state.month };
            if (ids.length === 1) params.schedule_id = ids[0];
            ajax('bookflow_get_month_availability', params, function (data) {
                state.monthData[key] = data.calendar;
                renderDays(data.calendar);
            }, function () {
                els.calDays.innerHTML = '<div class="bookflow-cal-message">' + (bookflowBooking.i18n.errorGeneric || 'Could not load') + '</div>';
            });
        } else {
            // Multiple schedules for this language — load all and merge
            var pending = ids.length;
            var merged = {};

            ids.forEach(function (sid) {
                ajax('bookflow_get_month_availability', {
                    product_id: bookflowBooking.productId,
                    year: state.year,
                    month: state.month,
                    schedule_id: sid,
                }, function (data) {
                    // Merge: date is available if ANY schedule says it is
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

        // Pad to 42 cells (6 rows) so height never changes
        var totalCells = firstDay + daysInMonth;
        while (totalCells < 42) {
            html += '<span class="bookflow-day bookflow-day-empty"></span>';
            totalCells++;
        }

        // Empty-month message when nothing is bookable
        if (!anyAvailable) {
            html += '<div class="bookflow-cal-message">' + (bookflowBooking.i18n.noAvailability || 'No availability this month.') + '</div>';
        }

        els.calDays.innerHTML = html;

        els.calDays.querySelectorAll('.bookflow-day[data-date]').forEach(function (el) {
            el.addEventListener('click', function () {
                selectDate(el.dataset.date);
            });
            // Keyboard: Enter/Space selects the day
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                    e.preventDefault();
                    selectDate(el.dataset.date);
                }
            });
        });
    }

    // Human-readable date label for screen readers, localized where possible
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
        state.slotScheduleMap = {}; // maps time -> schedule_id
        els.dateInput.value = date;
        els.timeInput.value = '';
        if (els.resourceInput) els.resourceInput.value = '';

        var ids = state.selectedScheduleIds || (state.selectedSchedule ? [state.selectedSchedule] : []);
        var schedKey = ids.length > 0 ? ids.join('_') : '0';
        var key = state.year + '-' + state.month + '-s' + schedKey;
        if (state.monthData[key]) {
            renderDays(state.monthData[key]);
        }

        els.slotsGrid.innerHTML = '<div class="bookflow-loading">' + bookflowBooking.i18n.loading + '</div>';
        show(els.slotsSection);
        hide(els.resourcesSection);
        hide(els.personsSection);
        hide(els.personTypesSection);
        hide(els.summarySection);

        if (ids.length <= 1) {
            var params = { product_id: bookflowBooking.productId, date: date };
            if (ids.length === 1) params.schedule_id = ids[0];
            ajax('bookflow_get_available_slots', params, function (data) {
                // Map all slots to this schedule_id
                if (data.slots) data.slots.forEach(function (s) { state.slotScheduleMap[s.time] = ids[0]; });
                renderSlots(data.slots);
            }, function () {
                els.slotsGrid.innerHTML = '<p class="bookflow-no-slots">' + (bookflowBooking.i18n.errorGeneric || 'Could not load') + '</p>';
            });
        } else {
            // Multiple schedules — query each and merge slots
            var pending = ids.length;
            var allSlots = {};

            ids.forEach(function (sid) {
                ajax('bookflow_get_available_slots', {
                    product_id: bookflowBooking.productId,
                    date: date,
                    schedule_id: sid,
                }, function (data) {
                    if (data.slots) {
                        data.slots.forEach(function (s) {
                            if (!allSlots[s.time]) {
                                allSlots[s.time] = s;
                                state.slotScheduleMap[s.time] = sid;
                            } else {
                                // Merge: take the higher availability
                                if (s.available > allSlots[s.time].available) {
                                    allSlots[s.time] = s;
                                    state.slotScheduleMap[s.time] = sid;
                                }
                            }
                        });
                    }
                    pending--;
                    if (pending === 0) {
                        // Sort by time and render
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

        var placeholder = bookflowBooking.i18n.selectTime || 'Selectați ora';
        var html = '<div class="bookflow-custom-select" id="bookflow-slot-select">';
        html += '<div class="bookflow-custom-select__trigger"><span>' + placeholder + '</span>';
        html += '<svg width="12" height="7" viewBox="0 0 12 7" fill="none"><path d="M1 1l5 5 5-5" stroke="currentColor" stroke-width="1.5"/></svg>';
        html += '</div>';
        html += '<div class="bookflow-custom-select__options">';
        slots.forEach(function (slot) {
            html += '<div class="bookflow-custom-select__option" data-time="' + slot.time + '" data-price="' + slot.price + '" data-available="' + slot.available + '">';
            html += '<span class="bookflow-opt-time">' + slot.time + '</span>';
            html += '</div>';
        });
        html += '</div></div>';

        els.slotsGrid.innerHTML = html;

        var customSelect = document.getElementById('bookflow-slot-select');
        var trigger = customSelect.querySelector('.bookflow-custom-select__trigger');
        var optionsWrap = customSelect.querySelector('.bookflow-custom-select__options');

        trigger.addEventListener('click', function () {
            customSelect.classList.toggle('bookflow-open');
        });

        customSelect.querySelectorAll('.bookflow-custom-select__option').forEach(function (opt) {
            opt.addEventListener('click', function () {
                customSelect.querySelectorAll('.bookflow-custom-select__option').forEach(function (o) {
                    o.classList.remove('bookflow-selected');
                });
                opt.classList.add('bookflow-selected');
                trigger.querySelector('span').textContent = opt.querySelector('.bookflow-opt-time').textContent;
                customSelect.classList.remove('bookflow-open');

                state.slotAvailable = parseInt(opt.dataset.available) || 0;
                updateSpotsLeft();
                selectSlot(opt.dataset.time);
            });
        });

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!customSelect.contains(e.target)) {
                customSelect.classList.remove('bookflow-open');
            }
        });

        // Auto-select if only one slot available
        if (slots.length === 1) {
            var onlyOpt = customSelect.querySelector('.bookflow-custom-select__option');
            onlyOpt.classList.add('bookflow-selected');
            trigger.querySelector('span').textContent = onlyOpt.querySelector('.bookflow-opt-time').textContent;
            state.slotAvailable = parseInt(onlyOpt.dataset.available) || 0;
            updateSpotsLeft();
            selectSlot(onlyOpt.dataset.time);
        }
    }

    function selectSlot(time) {
        state.selectedSlot = time;
        els.timeInput.value = time;

        // Set the correct schedule_id for this specific slot
        if (state.slotScheduleMap && state.slotScheduleMap[time]) {
            state.selectedSchedule = state.slotScheduleMap[time];
            var schedInput = document.getElementById('bookflow-schedule-id');
            if (schedInput) schedInput.value = state.selectedSchedule;
        }

        // If product has resources, load them
        if (bookflowBooking.hasResources && els.resourcesSection) {
            state.selectedResource = null;
            if (els.resourceInput) els.resourceInput.value = '';

            els.resourcesGrid.innerHTML = '<div class="bookflow-loading">' + bookflowBooking.i18n.loading + '</div>';
            show(els.resourcesSection);
            hide(els.personsSection);
            hide(els.personTypesSection);
            // contactSection always visible
            hide(els.summarySection);

            ajax('bookflow_get_resources_for_slot', {
                product_id: bookflowBooking.productId,
                date: state.selectedDate,
                start_time: state.selectedSlot,
            }, function (data) {
                renderResources(data.resources);
            });
        } else {
            showPersonsAndSummary();
        }
    }

    function renderResources(resources) {
        if (!resources || resources.length === 0) {
            // No resources available, skip resource selection
            showPersonsAndSummary();
            hide(els.resourcesSection);
            return;
        }

        var html = '';
        resources.forEach(function (r) {
            html += '<button type="button" class="bookflow-resource" data-id="' + r.id + '">';
            if (r.photoUrl) {
                html += '<img class="bookflow-resource-photo" src="' + escapeAttr(r.photoUrl) + '" alt="" loading="lazy">';
            }
            html += '<span class="bookflow-resource-name">' + escapeHtml(r.title) + '</span>';
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

                showPersonsAndSummary();
            });
        });
    }

    function showPersonsAndSummary() {
        if (bookflowBooking.hasPersonTypes && els.personTypesSection) {
            show(els.personTypesSection);
            updatePersonTypesTotal();
        } else {
            show(els.personsSection);
        }
        // contactSection always visible
        show(els.summarySection);
        updatePrice();
    }

    function updatePrice() {
        var data = {
            product_id: bookflowBooking.productId,
            date: state.selectedDate,
            start_time: state.selectedSlot,
        };

        if (state.selectedSchedule) {
            data.schedule_id = state.selectedSchedule;
        }

        if (state.selectedResource) {
            data.resource_id = state.selectedResource;
        }

        if (bookflowBooking.hasPersonTypes) {
            // Collect person type quantities
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

        ajax('bookflow_calculate_price', data, function (res) {
            // Animate price updates with a brief fade
            fadeUpdate(els.pricePerPerson, function () {
                els.pricePerPerson.innerHTML = res.price_per_person_formatted;
            });
            els.summaryPersons.textContent = res.persons;

            // Update header price
            var headerPrice = document.getElementById('bookflow-header-price');
            if (headerPrice) {
                fadeUpdate(headerPrice, function () {
                    headerPrice.textContent = Number(res.price_per_person).toLocaleString('ro-RO') + '.MDL';
                });
            }

            // Update participants "(X MDL fiecare)" label
            var perPersonLabel = document.getElementById('bookflow-per-person-label');
            if (perPersonLabel) {
                perPersonLabel.textContent = Number(res.price_per_person).toLocaleString('ro-RO');
            }

            // Add souvenir price if checkbox is checked (qty × unit price)
            var svCheckbox = document.getElementById('bookflow-souvenir-check');
            var svQtyInput = document.getElementById('souvenir-qty-input');
            var svUnitPrice = parseFloat(bookflowBooking.souvenirPrice) || 0;
            var hasSouvenir = svCheckbox && svCheckbox.checked && svUnitPrice > 0;
            var svQty = hasSouvenir && svQtyInput ? (parseInt(svQtyInput.value) || 1) : 0;
            var finalTotal = hasSouvenir ? res.total + (svUnitPrice * svQty) : res.total;

            fadeUpdate(els.totalPrice, function () {
                els.totalPrice.textContent = finalTotal.toLocaleString('ro-RO') + ' MDL';
            });
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

    // Dismissible toast for network/availability errors (no more silent stuck-loading)
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

    function show(el) { if (el) el.classList.remove('bookflow-hidden'); }
    function hide(el) { if (el) el.classList.add('bookflow-hidden'); }
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
        if (!submitBtn) return;

        var ready = true;

        // 1. Date selected?
        if (!els.dateInput || !els.dateInput.value) ready = false;

        // 2. Time selected?
        if (ready && (!els.timeInput || !els.timeInput.value)) ready = false;

        // 3. Name filled (3+ chars with space)?
        if (ready) {
            var nameEl = document.getElementById('bookflow-customer-name');
            if (nameEl) {
                var name = nameEl.value.trim();
                if (name.length < 3) ready = false;
            }
        }

        // 4. Phone filled (6+ chars)?
        if (ready) {
            var phoneEl = document.getElementById('bookflow-customer-phone');
            if (phoneEl && phoneEl.value.trim().length < 6) ready = false;
        }

        // 5. Payment method selected?
        if (ready) {
            var paymentSelected = document.querySelector('input[name="bookflow_payment"]:checked');
            if (!paymentSelected) ready = false;
        }

        submitBtn.disabled = !ready;
    }

    // Run checkFormReady on every relevant change
    ['bookflow-customer-name', 'bookflow-customer-phone', 'bookflow-customer-email'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', checkFormReady);
            el.addEventListener('blur', checkFormReady);
        }
    });
    document.querySelectorAll('input[name="bookflow_payment"]').forEach(function (radio) {
        radio.addEventListener('change', checkFormReady);
    });

    // Also hook into existing events that change date/time/persons
    // Patch selectDate to also check form
    var origSelectDate = selectDate;
    selectDate = function (date) {
        origSelectDate(date);
        checkFormReady();
    };

    // Patch selectSlot
    var origSelectSlot = selectSlot;
    selectSlot = function (time) {
        origSelectSlot(time);
        checkFormReady();
    };

    // Initial check
    checkFormReady();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
