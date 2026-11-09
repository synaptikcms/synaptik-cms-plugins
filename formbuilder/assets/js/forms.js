// Forms list: click-to-copy shortcode tag. Delete confirmation is handled
// by the core's generic [data-confirm] submit handler (admin/assets/js/common.js).
// Reads its own adjacent <script type="application/json"> data island.
(function () {
	var dataEl = document.getElementById('fb-forms-data');
	var i18n = {};
	if (dataEl) {
		try { i18n = JSON.parse(dataEl.textContent); } catch (e) {}
	}

	document.querySelectorAll('[data-fb-copy]').forEach(function (el) {
		el.style.cursor = 'pointer';
		el.title = i18n.clickToCopy || '';
		el.addEventListener('click', function () {
			navigator.clipboard.writeText(el.textContent.trim());
		});
	});
})();
