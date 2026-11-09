// Comments admin dashboard: shortcode-copy button, search-clear auto-submit,
// per-row approve/reject/delete, bulk selection + actions. Reads its own
// adjacent <script type="application/json"> data island.
(function () {
	var dataEl = document.getElementById('cm-dashboard-data');
	var i18n = {};
	if (dataEl) {
		try { i18n = JSON.parse(dataEl.textContent); } catch (e) {}
	}

	// Destructive actions confirm through the core admin's own modal
	// (window.showModal, common.js) rather than a native confirm() dialog,
	// matching every other delete flow in the admin. common.js is loaded in
	// the layout footer, after this file, so it is only ever referenced from
	// inside an event handler — never at load time. The native confirm()
	// fallback covers the case where common.js failed to load, so a delete
	// button is never silently inert.
	function confirmDanger(message, onConfirm) {
		if (typeof window.showModal !== 'function') {
			if (window.confirm(message)) onConfirm();
			return;
		}
		window.showModal(message, i18n.confirmTitle || '', {
			showCancel:  true,
			confirmText: i18n.confirmDeleteButton || '',
			cancelText:  i18n.cancel || '',
			danger:      true,
			onConfirm:   onConfirm,
		});
	}

	// ── Shortcode copy ────────────────────────────────────────────────────────
	var copyEl = document.getElementById('cm-shortcode-copy');
	if (copyEl) {
		copyEl.addEventListener('click', function () {
			navigator.clipboard.writeText('[comments]').then(function () {
				var original = copyEl.textContent;
				copyEl.textContent = i18n.copied || 'Copied!';
				setTimeout(function () { copyEl.textContent = original; }, 1200);
			});
		});
	}

	// The native <input type="search"> clear button (✕) empties the field
	// client-side only — it fires a 'search' event but never submits the
	// form on its own. Without this, clearing via that icon leaves the
	// stale ?q= param in the URL until the user manually re-submits.
	var searchInput = document.getElementById('cm-a-search-input');
	if (searchInput) {
		searchInput.addEventListener('search', function () {
			if (searchInput.value === '') {
				searchInput.form.submit();
			}
		});
	}

	// Per-row approve/reject/delete buttons submit the shared bulk form
	// with a single-item override, rather than each row carrying its own
	// <form> — avoids nested forms (invalid HTML) around the checkbox
	// column shared with the bulk-action toolbar. The submit is always
	// programmatic (the buttons are type="button") because deleting waits
	// on an asynchronous modal answer.
	document.querySelectorAll('[data-row-action]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var actionType = btn.dataset.rowAction;
			var id = btn.dataset.rowId;
			var form = document.getElementById('cm-a-bulk-form');
			if (!form) return;

			function submitRow() {
				var typeInput = document.getElementById('cm-a-bulk-type');
				if (typeInput) typeInput.value = actionType;
				form.querySelectorAll('input[name="ids[]"]').forEach(function (cb) { cb.checked = (cb.value === id); });
				form.submit();
			}

			if (actionType === 'delete') {
				confirmDanger(i18n.confirmDelete, submitRow);
			} else {
				submitRow();
			}
		});
	});

	var selectAll = document.getElementById('cm-a-select-all');
	var rowChecks = function () { return document.querySelectorAll('.cm-a-row-check'); };
	var countEl   = document.getElementById('cm-a-bulk-count');

	function updateCount() {
		var checked = Array.prototype.filter.call(rowChecks(), function (cb) { return cb.checked; });
		countEl.textContent = checked.length > 0 ? (i18n.nSelected || '').replace('{count}', checked.length) : (i18n.noneSelected || '');
	}

	if (selectAll) {
		selectAll.addEventListener('change', function () {
			rowChecks().forEach(function (cb) { cb.checked = selectAll.checked; });
			updateCount();
		});
	}
	document.addEventListener('change', function (e) {
		if (e.target.classList && e.target.classList.contains('cm-a-row-check')) updateCount();
	});

	document.querySelectorAll('[data-bulk]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var checked = Array.prototype.filter.call(rowChecks(), function (cb) { return cb.checked; });
			if (checked.length === 0) return;

			function submitBulk() {
				document.getElementById('cm-a-bulk-type').value = btn.dataset.bulk;
				document.getElementById('cm-a-bulk-form').submit();
			}

			if (btn.dataset.bulk === 'delete') {
				confirmDanger((i18n.confirmBulkDelete || '').replace('{count}', checked.length), submitBulk);
			} else {
				submitBulk();
			}
		});
	});

	updateCount();
})();
