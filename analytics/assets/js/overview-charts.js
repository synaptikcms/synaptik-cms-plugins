// Overview tab charts (Chart.js bundled in assets/vendor, loaded once). Reads its own
// adjacent <script type="application/json"> data island.
(function() {
	var chartSrc = document.currentScript ? document.currentScript.src.replace(/js\/[^\/?]*(\?.*)?$/, 'vendor/chart-4.4.1.umd.min.js') : '';
	var el = document.getElementById('an-overview-data');
	if (!el) return;
	var data;
	try { data = JSON.parse(el.textContent); } catch (e) { return; }

	var STORAGE_KEY_METRIC = 'an_metric';

	var days       = data.days;
	var dataViews  = data.dataViews;
	var dataUnique = data.dataUnique;
	var devData    = data.devData;
	var devLabels  = data.devLabels;
	var medData    = data.medData;
	var medLabels  = data.medLabels;
	var labelViews  = data.labelViews;
	var labelUnique = data.labelUnique;
	var titleViews  = data.titleViews;
	var titleUnique = data.titleUnique;

	// Re-read on every call rather than once — see the data-theme
	// MutationObserver below, which restyles the chart in place when the
	// admin theme toggle fires instead of leaving it stuck with whatever
	// colors were current when the chart was first drawn (previously
	// required a full page reload to pick up a dark/light switch).
	function themeColors() {
		var s = getComputedStyle(document.documentElement);
		return {
			accent: s.getPropertyValue('--primary').trim() || '#6c63ff',
			text:   s.getPropertyValue('--text').trim()    || '#1a1a2e',
			grid:   s.getPropertyValue('--border').trim()  || '#e5e7eb'
		};
	}

	var lineChart = null;

	// ── Persist & restore metric ──────────────────────────────────────────────
	function getSavedMetric() {
		try { return localStorage.getItem(STORAGE_KEY_METRIC) || 'views'; } catch (e) { return 'views'; }
	}

	function saveMetric(m) {
		try { localStorage.setItem(STORAGE_KEY_METRIC, m); } catch (e) {}
	}

	function applyMetric(metric) {
		var isUnique = metric === 'unique';
		var chartData = isUnique ? dataUnique : dataViews;
		var label     = isUnique ? labelUnique : labelViews;
		var heading   = document.getElementById('an-chart-heading');
		if (heading) heading.textContent = isUnique ? titleUnique : titleViews;

		document.querySelectorAll('.an-metric-btn').forEach(function(b) {
			b.classList.toggle('active', b.dataset.metric === metric);
		});

		if (lineChart) {
			lineChart.data.datasets[0].data  = chartData;
			lineChart.data.datasets[0].label = label;
			lineChart.update('none');
		}

		saveMetric(metric);
	}

	function loadCharts() {
		// ── Line chart ───────────────────────────────────────────────────────
		var ctxLine = document.getElementById('an-chart-views');
		var savedMetric = getSavedMetric();
		var initData  = savedMetric === 'unique' ? dataUnique : dataViews;
		var initLabel = savedMetric === 'unique' ? labelUnique : labelViews;

		if (ctxLine) {
			var c0 = themeColors();
			lineChart = new Chart(ctxLine, {
				type: 'line',
				data: {
					labels: days,
					datasets: [{
						label: initLabel,
						data: initData,
						borderColor: c0.accent,
						backgroundColor: c0.accent + '22',
						borderWidth: 2,
						pointRadius: days.length > 60 ? 0 : 3,
						pointHoverRadius: 5,
						fill: true,
						tension: 0.35,
					}]
				},
				options: {
					responsive: true,
					plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
					scales: {
						x: {
							ticks: {
								color: c0.text,
								maxTicksLimit: days.length > 60 ? 12 : 8,
								maxRotation: 0,
							},
							grid: { color: c0.grid }
						},
						y: {
							beginAtZero: true,
							ticks: { color: c0.text, precision: 0 },
							grid: { color: c0.grid }
						}
					}
				}
			});

			// Apply saved metric state to buttons and heading immediately
			applyMetric(savedMetric);

			new MutationObserver(function () {
				if (!lineChart) return;
				var t = themeColors();
				lineChart.data.datasets[0].borderColor     = t.accent;
				lineChart.data.datasets[0].backgroundColor = t.accent + '22';
				lineChart.options.scales.x.ticks.color = t.text;
				lineChart.options.scales.x.grid.color  = t.grid;
				lineChart.options.scales.y.ticks.color = t.text;
				lineChart.options.scales.y.grid.color  = t.grid;
				lineChart.update('none');
			}).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
		}

		// ── Metric toggle buttons ─────────────────────────────────────────────
		document.querySelectorAll('.an-metric-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				applyMetric(btn.dataset.metric);
			});
		});

		// ── Donut helper ──────────────────────────────────────────────────────
		function makeDonut(id, chartData, labels, colors) {
			var ctx = document.getElementById(id);
			if (!ctx) return;
			new Chart(ctx, {
				type: 'doughnut',
				data: {
					labels: labels,
					datasets: [{ data: chartData, backgroundColor: colors, borderWidth: 2, borderColor: 'transparent' }]
				},
				options: {
					cutout: '70%',
					plugins: {
						legend: { display: false },
						tooltip: { callbacks: { label: function(c) { return c.label + ': ' + c.parsed; } } }
					}
				}
			});
		}

		makeDonut('an-chart-devices', devData, devLabels, ['#6c63ff', '#22c55e', '#f59e0b']);
		makeDonut('an-chart-media',   medData, medLabels, ['#6c63ff', '#22c55e', '#f59e0b', '#3b82f6', '#d946ef']);
	}

	// Load the bundled Chart.js if not already present, then render
	if (typeof Chart !== 'undefined') {
		loadCharts();
	} else {
		var s = document.createElement('script');
		s.src = chartSrc;
		s.onload = loadCharts;
		document.head.appendChild(s);
	}
})();
