// Referrers tab: expandable host rows.
(function() {
	// ── Expandable rows (was inline onclick="anToggleRef(...)") ──────────────
	document.addEventListener('click', function(e) {
		if (e.target.closest('a')) return; // let links navigate without toggling the row
		var row = e.target.closest('.an-ref-expandable[data-ref-toggle]');
		if (!row) return;
		var target = document.getElementById(row.getAttribute('data-ref-toggle'));
		if (!target) return;
		var isOpen = target.style.display !== 'none';
		target.style.display = isOpen ? 'none' : '';
		row.classList.toggle('open', !isOpen);
	});

})();
