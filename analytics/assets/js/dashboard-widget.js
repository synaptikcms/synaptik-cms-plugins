(function () {
	var chartSrc = document.currentScript ? document.currentScript.src.replace(/js\/[^\/?]*(\?.*)?$/, 'vendor/chart-4.4.1.umd.min.js') : '';
	var dataEl = document.getElementById('an-dash-widget-data');
	var canvas = document.getElementById('an-dash-chart');
	if (!dataEl || !canvas) return;

	var data;
	try {
		data = JSON.parse(dataEl.textContent);
	} catch (e) {
		return;
	}

	// Read once per render — see the data-theme MutationObserver below, which
	// re-reads these and restyles the chart in place when the admin theme
	// toggle fires, instead of leaving it stuck with the colors read at the
	// time the chart was first drawn (previously required a full page
	// reload to pick up a dark/light switch).
	function themeColors() {
		var s = getComputedStyle(document.documentElement);
		return {
			accent: s.getPropertyValue('--primary').trim() || '#6c63ff',
			text:   s.getPropertyValue('--text').trim()    || '#1a1a2e',
			grid:   s.getPropertyValue('--border').trim()  || '#e5e7eb'
		};
	}

	var chart = null;

	function render() {
		var c = themeColors();
		chart = new Chart(canvas, {
			type: 'line',
			data: {
				labels: data.days,
				datasets: [{
					label: data.label,
					data: data.views,
					borderColor: c.accent,
					backgroundColor: c.accent + '22',
					borderWidth: 2,
					pointRadius: 3,
					pointHoverRadius: 5,
					fill: true,
					tension: 0.35
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
				scales: {
					x: { ticks: { color: c.text, maxTicksLimit: 7, maxRotation: 0 }, grid: { display: false } },
					y: { beginAtZero: true, ticks: { color: c.text, precision: 0 }, grid: { color: c.grid } }
				}
			}
		});

		new MutationObserver(function () {
			if (!chart) return;
			var t = themeColors();
			chart.data.datasets[0].borderColor     = t.accent;
			chart.data.datasets[0].backgroundColor = t.accent + '22';
			chart.options.scales.x.ticks.color = t.text;
			chart.options.scales.y.ticks.color = t.text;
			chart.options.scales.y.grid.color  = t.grid;
			chart.update('none');
		}).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
	}

	if (typeof Chart !== 'undefined') {
		render();
	} else {
		var s = document.createElement('script');
		s.src = chartSrc;
		s.onload = render;
		document.head.appendChild(s);
	}
})();
