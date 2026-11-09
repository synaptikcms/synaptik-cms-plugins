// Settings page: appointment-type row add/remove, ICS placeholder token
// insertion, callback-hours toggle. Reads its own adjacent
// <script type="application/json"> data island.
(function () {
	var dataEl = document.getElementById('bk-settings-data');
	var i18n = {};
	if (dataEl) {
		try { i18n = JSON.parse(dataEl.textContent); } catch (e) {}
	}

	var list = document.getElementById('bk-types-list');
	var addBtn = document.getElementById('bk-add-type');

	addBtn.addEventListener('click', function () {
		var row = document.createElement('div');
		row.className = 'bk-type-row';
		row.innerHTML =
			'<input type="text" name="type_key[]" placeholder="' + (i18n.typeKeyPlaceholder || '') + '" pattern="[a-z0-9_]+" required>' +
			'<input type="text" name="type_label[]" placeholder="' + (i18n.typeLabelPlaceholder || '') + '" required>' +
			'<input type="number" name="type_duration[]" value="30" min="5" step="5" required>' +
			'<button type="button" class="bk-btn bk-btn--delete bk-remove-row">' + (i18n.removeType || 'Remove') + '</button>';
		list.appendChild(row);
	});

	list.addEventListener('click', function (e) {
		if (e.target.classList.contains('bk-remove-row')) {
			e.target.closest('.bk-type-row').remove();
		}
	});

	// ─── ICS placeholder insertion ───────────────────────────────────────────
	// Inserts the clicked token into whichever ICS field (summary,
	// description, location, or url) was last focused, at the cursor
	// position — defaults to the summary field if none has been focused
	// yet this page load.
	var icsFieldIds = ['bk-ics-summary', 'bk-ics-description', 'bk-ics-location', 'bk-ics-url'];
	var icsFields = icsFieldIds.map(function (id) { return document.getElementById(id); }).filter(Boolean);
	var lastFocusedIcsField = icsFields[0] || null;

	icsFields.forEach(function (field) {
		field.addEventListener('focus', function () {
			lastFocusedIcsField = field;
		});
	});

	var tokenBar = document.getElementById('bk-ics-token-bar');
	if (tokenBar) {
		tokenBar.addEventListener('click', function (e) {
			var btn = e.target.closest('.bk-token-btn');
			if (!btn || !lastFocusedIcsField) return;

			var token = btn.getAttribute('data-token');
			var field = lastFocusedIcsField;
			var start = field.selectionStart != null ? field.selectionStart : field.value.length;
			var end   = field.selectionEnd != null ? field.selectionEnd : field.value.length;

			field.value = field.value.slice(0, start) + token + field.value.slice(end);
			field.focus();
			var newPos = start + token.length;
			field.setSelectionRange(newPos, newPos);
		});
	}
	// Hide the "restrict callback hours" option when callback is disabled entirely
	var enableCb  = document.getElementById('bk-enable-callback');
	var restrictWrap = document.getElementById('bk-restrict-callback-wrap');
	if (enableCb && restrictWrap) {
		function toggleRestrictWrap() {
			restrictWrap.style.display = enableCb.checked ? '' : 'none';
		}
		toggleRestrictWrap();
		enableCb.addEventListener('change', toggleRestrictWrap);
	}
})();
