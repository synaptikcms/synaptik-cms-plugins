/**
 * Booking Plugin — Public Form Widget
 * Vanilla JS, no dependencies — consistent with the CMS core's front-end
 * (js/main.js is also dependency-free).
 *
 * Flow: month-grid calendar (day picker) → click a day with availability →
 * time-slot list for that day → pick a slot → client details form.
 */
(function () {
    'use strict';

    var DAY_LABELS   = (window.BK_LANG && window.BK_LANG.day_labels)   || ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    var MONTH_LABELS = (window.BK_LANG && window.BK_LANG.month_labels) || ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    function initBookingWidget(root) {
        var endpoint    = root.getAttribute('data-bk-endpoint');
        var typeSelect  = root.querySelector('#bk-type');
        var monthGrid   = root.querySelector('.bk-month-grid');
        var weekdayRow  = root.querySelector('.bk-weekday-row');
        var monthLabel  = root.querySelector('.bk-month-label');
        var prevBtn     = root.querySelector('.bk-nav-prev');
        var nextBtn     = root.querySelector('.bk-nav-next');
        var dayTitle    = root.querySelector('.bk-day-slots-title');
        var selectHint  = root.querySelector('.bk-select-day-hint');
        var noSlotsEl   = root.querySelector('.bk-no-slots');
        var slotsList   = root.querySelector('.bk-slots-list');
        var form        = root.querySelector('.bk-form');
        var successEl   = root.querySelector('.bk-success');
        var summaryEl   = root.querySelector('.bk-selected-summary');
        var messageEl   = root.querySelector('.bk-message');
        var submitBtn   = root.querySelector('.bk-submit');
        var callbackCb  = root.querySelector('#bk-callback');
        var callbackFields = root.querySelector('.bk-callback-fields');
        var callbackDateInput = root.querySelector('#bk-callback-date');
        var callbackTimeInput = root.querySelector('#bk-callback-time');
        var callbackHint = root.querySelector('.bk-callback-hint');

        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var currentMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        var selected = { date: null, time: null };
        var monthAvailability = {}; // { 'Y-m-d': true|false }

        function formatYmd(date) {
            var y = date.getFullYear();
            var m = String(date.getMonth() + 1).padStart(2, '0');
            var d = String(date.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + d;
        }

        function formatMonth(date) {
            var y = date.getFullYear();
            var m = String(date.getMonth() + 1).padStart(2, '0');
            return y + '-' + m;
        }

        // Render the 7 weekday abbreviations once — they don't change with
        // month navigation, so this only needs to run on init.
        function renderWeekdayRow() {
            weekdayRow.innerHTML = '';
            DAY_LABELS.forEach(function (label) {
                var el = document.createElement('div');
                el.className = 'bk-weekday-cell';
                el.textContent = label;
                weekdayRow.appendChild(el);
            });
        }

        function loadMonth() {
            monthGrid.setAttribute('aria-busy', 'true');
            var type = typeSelect.value;
            var monthParam = formatMonth(currentMonth);

            monthLabel.textContent = MONTH_LABELS[currentMonth.getMonth()] + ' ' + currentMonth.getFullYear();

            fetch(endpoint + 'booking.php?action=month_availability&type=' + encodeURIComponent(type) + '&month=' + monthParam)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    monthAvailability = (data && data.availability) || {};
                    renderMonthGrid();
                })
                .catch(function () {
                    monthAvailability = {};
                    renderMonthGrid();
                })
                .finally(function () {
                    monthGrid.removeAttribute('aria-busy');
                });
        }

        function renderMonthGrid() {
            monthGrid.innerHTML = '';

            var year  = currentMonth.getFullYear();
            var month = currentMonth.getMonth();
            var firstOfMonth = new Date(year, month, 1);
            var daysInMonth  = new Date(year, month + 1, 0).getDate();
            // getDay(): 0 = Sunday .. 6 = Saturday. Convert to a Monday-first
            // offset (0 = Monday .. 6 = Sunday) to match DAY_LABELS ordering.
            var firstWeekday = (firstOfMonth.getDay() + 6) % 7;

            for (var i = 0; i < firstWeekday; i++) {
                var empty = document.createElement('div');
                empty.className = 'bk-month-cell bk-month-cell--empty';
                monthGrid.appendChild(empty);
            }

            for (var day = 1; day <= daysInMonth; day++) {
                var cellDate = new Date(year, month, day);
                var ymd = formatYmd(cellDate);
                var isPast = cellDate < today;
                var hasSlots = !!monthAvailability[ymd];

                var cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'bk-month-cell bk-day-btn';
                cell.textContent = String(day);

                if (isPast || !hasSlots) {
                    cell.disabled = true;
                    cell.className += ' bk-day-btn--disabled';
                } else {
                    cell.className += ' bk-day-btn--available';
                    cell.addEventListener('click', function (clickedDate, btnEl) {
                        return function () {
                            selectDay(clickedDate, btnEl);
                        };
                    }(ymd, cell));
                }

                if (ymd === selected.date) {
                    cell.className += ' bk-day-btn--selected';
                }

                monthGrid.appendChild(cell);
            }
        }

        function selectDay(ymd, btnEl) {
            monthGrid.querySelectorAll('.bk-day-btn--selected').forEach(function (el) {
                el.classList.remove('bk-day-btn--selected');
            });
            if (btnEl) btnEl.classList.add('bk-day-btn--selected');

            selected.date = ymd;
            selected.time = null;
            form.hidden = true;

            var d = new Date(ymd + 'T00:00:00');
            dayTitle.textContent = DAY_LABELS[(d.getDay() + 6) % 7] + ' ' + d.getDate() + ' ' + MONTH_LABELS[d.getMonth()];
            dayTitle.hidden = false;
            selectHint.hidden = true;
            noSlotsEl.hidden = true;
            slotsList.innerHTML = '';
            slotsList.setAttribute('aria-busy', 'true');

            var type = typeSelect.value;
            fetch(endpoint + 'booking.php?action=slots&type=' + encodeURIComponent(type) + '&date=' + encodeURIComponent(ymd))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    renderSlotsList((data && data.slots) || []);
                })
                .catch(function () {
                    renderSlotsList([]);
                })
                .finally(function () {
                    slotsList.removeAttribute('aria-busy');
                });
        }

        function renderSlotsList(slots) {
            slotsList.innerHTML = '';

            if (!slots.length) {
                noSlotsEl.hidden = false;
                return;
            }
            noSlotsEl.hidden = true;

            slots.forEach(function (slot) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'bk-slot-btn';
                btn.textContent = slot.time;

                if (slot.available) {
                    btn.addEventListener('click', function () {
                        selectSlot(slot.time, btn);
                    });
                } else {
                    btn.disabled = true;
                    btn.className += ' bk-slot-btn--disabled';
                }

                slotsList.appendChild(btn);
            });
        }

        function selectSlot(time, btnEl) {
            slotsList.querySelectorAll('.bk-slot-selected').forEach(function (el) {
                el.classList.remove('bk-slot-selected');
            });
            btnEl.classList.add('bk-slot-selected');

            selected.time = time;

            form.querySelector('.bk-selected-type').value = typeSelect.value;
            form.querySelector('.bk-selected-date').value = selected.date;
            form.querySelector('.bk-selected-time').value = time;

            var typeLabel = typeSelect.options[typeSelect.selectedIndex].text;
            var d = new Date(selected.date + 'T00:00:00');
            var dateLabel = d.getDate() + ' ' + MONTH_LABELS[d.getMonth()] + ' ' + d.getFullYear();
            summaryEl.textContent = typeLabel + ' — ' + dateLabel + ' ' + time;

            form.hidden = false;
        }

        typeSelect.addEventListener('change', function () {
            selected.date = null;
            selected.time = null;
            form.hidden = true;
            summaryEl.textContent = '';
            dayTitle.hidden = true;
            selectHint.hidden = false;
            noSlotsEl.hidden = true;
            slotsList.innerHTML = '';
            loadMonth();
        });

        prevBtn.addEventListener('click', function () {
            currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1, 1);
            loadMonth();
        });

        nextBtn.addEventListener('click', function () {
            currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 1);
            loadMonth();
        });

        if (callbackCb && callbackFields) {
            callbackCb.addEventListener('change', function () {
                callbackFields.hidden = !callbackCb.checked;
                if (callbackCb.checked) {
                    refreshCallbackWindow();
                }
            });
        }

        // Restricts the callback time input to the requested date's actual
        // availability window, when the admin has enabled that setting —
        // mirrors the server-side check in booking-submit.php so a visitor
        // gets immediate feedback instead of a rejection after submitting.
        function refreshCallbackWindow() {
            if (!callbackDateInput || !callbackTimeInput) return;
            var date = callbackDateInput.value;
            if (!date) {
                callbackTimeInput.removeAttribute('min');
                callbackTimeInput.removeAttribute('max');
                if (callbackHint) callbackHint.hidden = true;
                return;
            }

            fetch(endpoint + 'booking.php?action=callback_window&date=' + encodeURIComponent(date))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.restricted) {
                        callbackTimeInput.removeAttribute('min');
                        callbackTimeInput.removeAttribute('max');
                        if (callbackHint) callbackHint.hidden = true;
                        return;
                    }
                    if (data.closed) {
                        callbackTimeInput.setAttribute('min', '00:00');
                        callbackTimeInput.setAttribute('max', '00:00');
                        if (callbackHint) {
                            callbackHint.textContent = (window.BK_LANG && window.BK_LANG.callback_closed) || 'We are closed on this date.';
                            callbackHint.hidden = false;
                        }
                        return;
                    }
                    callbackTimeInput.setAttribute('min', data.start);
                    callbackTimeInput.setAttribute('max', data.end);
                    if (callbackHint) {
                        var tpl = (window.BK_LANG && window.BK_LANG.callback_hours) || 'Available between {start} and {end}.';
                        callbackHint.textContent = tpl.replace('{start}', data.start).replace('{end}', data.end);
                        callbackHint.hidden = false;
                    }
                })
                .catch(function () {
                    // Fail open client-side — the server-side check in
                    // booking-submit.php is the actual enforcement point.
                    callbackTimeInput.removeAttribute('min');
                    callbackTimeInput.removeAttribute('max');
                    if (callbackHint) callbackHint.hidden = true;
                });
        }

        if (callbackDateInput) {
            callbackDateInput.addEventListener('change', refreshCallbackWindow);
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (!selected.date || !selected.time) {
                showMessage((window.BK_LANG && window.BK_LANG.select_slot) || 'Please select a time slot.', true);
                return;
            }

            submitBtn.disabled = true;
            var originalLabel = submitBtn.textContent;
            submitBtn.textContent = (window.BK_LANG && window.BK_LANG.submitting) || 'Sending…';

            var formData = new FormData(form);

            fetch(endpoint + 'booking-submit.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { return r.json().then(function (json) { return { ok: r.ok, json: json }; }); })
                .then(function (res) {
                    if (res.ok && res.json.success) {
                        form.hidden = true;
                        successEl.hidden = false;
                    } else {
                        showMessage(res.json.message || (window.BK_LANG && window.BK_LANG.error_generic) || 'An error occurred.', true);
                        // A stale/taken slot means this day's list is out of date — refresh it.
                        if (res.json.error === 'booking_error_slot_taken' && selected.date) {
                            selectDay(selected.date, monthGrid.querySelector('.bk-day-btn--selected'));
                        }
                    }
                })
                .catch(function () {
                    showMessage((window.BK_LANG && window.BK_LANG.error_generic) || 'An error occurred.', true);
                })
                .finally(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalLabel;
                });
        });

        function showMessage(text, isError) {
            messageEl.textContent = text;
            messageEl.hidden = false;
            messageEl.className = 'bk-message' + (isError ? ' bk-message--error' : '');
        }

        dayTitle.hidden = true;
        renderWeekdayRow();
        loadMonth();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.bk-booking').forEach(initBookingWidget);
    });
})();
