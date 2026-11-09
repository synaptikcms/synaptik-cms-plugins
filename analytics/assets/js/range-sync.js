// Syncs localStorage with the current range on every page load. Also
// restores the saved range when landing with no explicit range param.
// Reads its own adjacent <script type="application/json"> data island.
(function() {
	var el = document.getElementById('an-range-sync-data');
	if (!el) return;
	var data;
	try { data = JSON.parse(el.textContent); } catch (e) { return; }

	var KEY   = 'an_range';
	var valid = ['7d', '30d', '90d', '365d'];
	var current = data.current;
	var fromUrl = data.fromUrl;

	if (fromUrl) {
		try { localStorage.setItem(KEY, current); } catch (e) {}
	} else {
		var saved;
		try { saved = localStorage.getItem(KEY); } catch (e) {}
		if (saved && valid.indexOf(saved) !== -1 && saved !== current) {
			var url = new URL(window.location.href);
			url.searchParams.set('range', saved);
			window.location.replace(url.toString());
		}
	}
})();
