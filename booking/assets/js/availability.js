// Availability page: weekly hours lunch-break toggle + date exception rows.
// Reads its own adjacent <script type="application/json"> data island.
(function () {
	var dataEl = document.getElementById('bk-availability-data');
	var i18n = {};
	if (dataEl) {
		try { i18n = JSON.parse(dataEl.textContent); } catch (e) {}
	}

	var weeklyTable = document.getElementById('bk-weekly-table');
	var list = document.getElementById('bk-exceptions-list');
	var addBtn = document.getElementById('bk-add-exception');

	// ── Weekly hours: lunch-break toggle unlocks its time fields immediately ──
	weeklyTable.addEventListener('change', function (e) {
		if (e.target.classList.contains('bk-lunch-toggle')) {
			var tr = e.target.closest('tr');
			var lunchTimeInputs = tr.querySelectorAll('input[name*="[lunch_start]"], input[name*="[lunch_end]"]');
			lunchTimeInputs.forEach(function (input) { input.readOnly = !e.target.checked; });
		}
	});

	// ── Date exceptions ──────────────────────────────────────────────────────
	function rowTemplate() {
		var row = document.createElement('div');
		row.className = 'bk-exception-row';
		row.innerHTML =
			'<input type="date" name="exception_start_date[]" class="bk-exc-start">' +
			'<span class="bk-exc-range-sep">&rarr;</span>' +
			'<input type="date" name="exception_end_date[]" class="bk-exc-end">' +
			'<input type="hidden" name="exception_closed_flag[]" value="0" class="bk-exc-closed-flag">' +
			'<label><input type="checkbox" class="bk-exc-closed"> ' + (i18n.closedAllDay || 'Closed all day') + '</label>' +
			'<input type="time" name="exception_start_time[]">' +
			'<input type="time" name="exception_end_time[]">' +
			'<button type="button" class="bk-btn bk-btn--delete bk-remove-row">&times;</button>';
		return row;
	}

	addBtn.addEventListener('click', function () {
		list.appendChild(rowTemplate());
	});

	list.addEventListener('click', function (e) {
		if (e.target.classList.contains('bk-remove-row')) {
			e.target.closest('.bk-exception-row').remove();
		}
	});

	list.addEventListener('change', function (e) {
		if (e.target.classList.contains('bk-exc-closed')) {
			var row = e.target.closest('.bk-exception-row');
			var timeInputs = row.querySelectorAll('input[type="time"]');
			var flagInput  = row.querySelector('.bk-exc-closed-flag');
			// readonly (not disabled) — keeps these fields in the form
			// submission at their original position even while blocked
			// from editing. See the PHP-side comment above for why.
			timeInputs.forEach(function (input) { input.readOnly = e.target.checked; });
			flagInput.value = e.target.checked ? '1' : '0';
		}
		// Keep the end date from ever being set before the start date —
		// a single-day exception is just start === end, so this also covers
		// the "one day only" case without needing a separate UI toggle.
		if (e.target.classList.contains('bk-exc-start')) {
			var row2 = e.target.closest('.bk-exception-row');
			var endInput = row2.querySelector('.bk-exc-end');
			if (!endInput.value || endInput.value < e.target.value) {
				endInput.value = e.target.value;
			}
			endInput.min = e.target.value;
		}
	});
})();
