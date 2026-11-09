// Analytics settings page: save retention, purge all data, install GeoIP DB.
// Reads its own adjacent <script type="application/json"> data island.
(function() {
	var el = document.getElementById('an-settings-data');
	if (!el) return;
	var data;
	try { data = JSON.parse(el.textContent); } catch (e) { return; }
	var i18n = data.i18n || {};

	function anSaveSettings() {
		var days   = document.getElementById('an-retention').value;
		var widget = document.getElementById('an-dashboard-widget');
		var btn    = document.getElementById('an-save-btn');
		var msg    = document.getElementById('an-save-msg');
		if (btn) btn.disabled = true;

		fetch(data.saveUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'csrf_token=' + encodeURIComponent(data.csrfToken)
				+ '&retention_days=' + encodeURIComponent(days)
				+ '&dashboard_widget_enabled=' + (widget && widget.checked ? '1' : '')
		})
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (btn) btn.disabled = false;
			if (msg) {
				msg.textContent = d.ok ? i18n.settingsSaved : (d.error || 'Error');
				msg.style.display = '';
			}
		})
		.catch(function() {
			if (btn) btn.disabled = false;
			if (msg) { msg.textContent = 'Request failed.'; msg.style.display = ''; }
		});
	}

	function anPurgeAll() {
		if (!confirm(i18n.purgeConfirm)) return;

		var btn = document.getElementById('an-purge-btn');
		var msg = document.getElementById('an-purge-msg');
		if (btn) btn.disabled = true;

		fetch(data.purgeUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'csrf_token=' + encodeURIComponent(data.csrfToken)
		})
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d.ok) {
				location.reload();
			} else {
				if (msg) { msg.textContent = d.error || 'Error'; msg.style.display = ''; }
				if (btn) btn.disabled = false;
			}
		})
		.catch(function() {
			if (msg) { msg.textContent = 'Request failed.'; msg.style.display = ''; }
			if (btn) btn.disabled = false;
		});
	}

	function anInstallGeoip() {
		var btn      = document.getElementById('an-geoip-btn');
		var progress = document.getElementById('an-geoip-progress');
		var bar      = document.getElementById('an-geoip-bar');
		var msg      = document.getElementById('an-geoip-msg');

		btn.disabled = true;
		progress.style.display = 'block';
		bar.style.width = '0%';
		bar.style.background = '';
		bar.style.transition = 'none';
		msg.textContent = i18n.geoipWaiting;

		// Animated indeterminate bar while waiting for the synchronous response
		var pct = 5;
		var direction = 1;
		var ticker = setInterval(function() {
			pct += direction * 1.2;
			if (pct >= 90) direction = -1;
			if (pct <= 5)  direction = 1;
			bar.style.width = pct.toFixed(1) + '%';
		}, 80);

		fetch(data.geoipUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'csrf_token=' + encodeURIComponent(data.csrfToken)
			})
			.then(function(r) {
				return r.text().then(function(text) {
					try {
						return JSON.parse(text);
					} catch (e) {
						throw new Error('Non-JSON response: ' + text.substring(0, 300));
					}
				});
			})
			.then(function(d) {
				clearInterval(ticker);
				if (d.ok) {
					bar.style.transition = 'width .3s';
					bar.style.width = '100%';
					bar.style.background = 'var(--success, #22c55e)';
					msg.textContent = d.message + ' (' + d.v4.toLocaleString() + ' IPv4, ' + d.v6.toLocaleString() + ' IPv6)';
					setTimeout(function() { location.reload(); }, 1800);
				} else {
					bar.style.width = '100%';
					bar.style.background = 'var(--danger, #ef4444)';
					msg.textContent = d.error || i18n.geoipError;
					btn.disabled = false;
				}
			})
			.catch(function(err) {
				clearInterval(ticker);
				bar.style.width = '100%';
				bar.style.background = 'var(--danger, #ef4444)';
				msg.textContent = err.message || i18n.geoipError;
				btn.disabled = false;
			});
	}

	var saveBtn = document.getElementById('an-save-btn');
	if (saveBtn) saveBtn.addEventListener('click', anSaveSettings);

	var purgeBtn = document.getElementById('an-purge-btn');
	if (purgeBtn) purgeBtn.addEventListener('click', anPurgeAll);

	var geoipBtn = document.getElementById('an-geoip-btn');
	if (geoipBtn) geoipBtn.addEventListener('click', anInstallGeoip);

	function anAiPost(url, confirmMsg) {
		if (confirmMsg && !confirm(confirmMsg)) return;
		var msg = document.getElementById('an-ai-msg');
		fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'csrf_token=' + encodeURIComponent(data.csrfToken)
		})
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d.ok) { location.reload(); return; }
			if (msg) { msg.textContent = d.error || 'Error'; msg.style.display = ''; }
		});
	}
	var aiEnable = document.getElementById('an-ai-enable-btn');
	if (aiEnable) aiEnable.addEventListener('click', function() {
		anAiPost(data.aiEnableUrl, document.getElementById('an-ai-token') ? i18n.aiRegenConfirm : '');
	});
	var aiDisable = document.getElementById('an-ai-disable-btn');
	if (aiDisable) aiDisable.addEventListener('click', function() { anAiPost(data.aiDisableUrl, i18n.aiDisableConfirm); });
})();
