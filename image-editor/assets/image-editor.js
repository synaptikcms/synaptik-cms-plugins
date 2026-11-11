(function () {
	'use strict';

	var cfgEl = document.getElementById('ie-config');
	if (!cfgEl) return;
	var CFG;
	try { CFG = JSON.parse(cfgEl.textContent); } catch (e) { return; }
	var S = CFG.strings || {};
	function t(k) { return S[k] || k; }

	var EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
	var FONT = 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif';
	var MAX_SIDE = 8000;
	var HIST_MAX = 30;

	var I = {
		edit: ['M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z'],
		undo: ['M3 7v6h6', 'M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13'],
		redo: ['M21 7v6h-6', 'M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13'],
		reset: ['M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8', 'M3 3v5h5'],
		eye: ['M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z', 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z'],
		crop: ['M6 2v14a2 2 0 0 0 2 2h14', 'M18 22V8a2 2 0 0 0-2-2H2'],
		rotcw: ['M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8', 'M21 3v5h-5'],
		rotccw: ['M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8', 'M3 3v5h5'],
		resize: ['M8 3H5a2 2 0 0 0-2 2v3', 'M21 8V5a2 2 0 0 0-2-2h-3', 'M3 16v3a2 2 0 0 0 2 2h3', 'M16 21h3a2 2 0 0 0 2-2v-3'],
		sliders: ['M4 21v-7', 'M4 10V3', 'M12 21v-9', 'M12 8V3', 'M20 21v-5', 'M20 12V3', 'M1 14h6', 'M9 8h6', 'M17 16h6'],
		layers: ['m12 2 10 5-10 5L2 7Z', 'm2 17 10 5 10-5', 'm2 12 10 5 10-5'],
		drop: ['M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z'],
		cursor: ['m3 3 7.07 16.97 2.51-7.39 7.39-2.51L3 3z'],
		type: ['M4 7V4h16v3', 'M9 20h6', 'M12 4v16'],
		square: ['M3 5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'],
		circle: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z'],
		line: ['M5 19 19 5'],
		arrow: ['M5 12h14', 'm12 5 7 7-7 7'],
		pen: ['M12 20h9', 'M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z'],
		trash: ['M3 6h18', 'M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6', 'M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2'],
		fliph: ['M8 3H5a2 2 0 0 0-2 2v14c0 1.1.9 2 2 2h3', 'M16 3h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-3', 'M12 20v2', 'M12 14v2', 'M12 8v2', 'M12 2v2'],
		flipv: ['M3 8V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3', 'M3 16v3a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3', 'M4 12H2', 'M10 12H8', 'M16 12h-2', 'M22 12h-2'],
		x: ['M18 6 6 18', 'M6 6l12 12']
	};

	function ico(n, sz) {
		var s = '<svg viewBox="0 0 24 24" width="' + (sz || 16) + '" height="' + (sz || 16) + '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
		I[n].forEach(function (d) { s += '<path d="' + d + '"/>'; });
		return s + '</svg>';
	}

	function h(tag, attrs, kids) {
		var e = document.createElement(tag);
		if (attrs) {
			for (var k in attrs) {
				var v = attrs[k];
				if (v === false || v == null) continue;
				if (k === 'class') e.className = v;
				else if (k === 'html') e.innerHTML = v;
				else if (k === 'text') e.textContent = v;
				else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v);
				else if (v === true) e.setAttribute(k, '');
				else e.setAttribute(k, v);
			}
		}
		(kids || []).forEach(function (c) {
			if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return e;
	}

	function cv(w, hh) {
		var c = document.createElement('canvas');
		c.width = w;
		c.height = hh;
		return c;
	}

	function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }

	var FILTERS = {
		none: {},
		bw: { s: -100, c: 10 },
		sepia: { sepia: 1 },
		vintage: { sepia: 0.45, c: -10, w: 25, fade: 0.12 },
		cool: { w: -40, s: 10 },
		warm: { w: 40, s: 10 },
		invert: { invert: 1 },
		fade: { fade: 0.2, s: -20, c: -10 },
		dramatic: { c: 40, s: -25, b: -5 }
	};
	var FILTER_ORDER = ['none', 'bw', 'sepia', 'vintage', 'cool', 'warm', 'invert', 'fade', 'dramatic'];
	var RATIOS = [['free', null], ['orig', null], ['1:1', 1], ['4:3', 4 / 3], ['3:4', 3 / 4], ['3:2', 3 / 2], ['2:3', 2 / 3], ['16:9', 16 / 9], ['9:16', 9 / 16]];

	function blurLine(src, dst, off, stride, n, r) {
		var win = 2 * r + 1;
		for (var c = 0; c < 4; c++) {
			var sum = 0, k, i;
			for (k = -r; k <= r; k++) sum += src[off + clamp(k, 0, n - 1) * stride + c];
			for (i = 0; i < n; i++) {
				dst[off + i * stride + c] = sum / win;
				sum += src[off + clamp(i + r + 1, 0, n - 1) * stride + c] - src[off + clamp(i - r, 0, n - 1) * stride + c];
			}
		}
	}

	function boxBlur(data, w, hh, r, passes) {
		var tmp = new Uint8ClampedArray(data.length), a = data, b = tmp, p, x, y, swap;
		for (p = 0; p < passes; p++) {
			for (y = 0; y < hh; y++) blurLine(a, b, y * w * 4, 4, w, r);
			swap = a; a = b; b = swap;
			for (x = 0; x < w; x++) blurLine(a, b, x * 4, w * 4, hh, r);
			swap = a; a = b; b = swap;
		}
		if (a !== data) data.set(a);
	}

	function isNeutralFx(adj, filter) {
		return adj.b === 0 && adj.c === 0 && adj.s === 0 && adj.w === 0 && adj.bl === 0 && adj.sh === 0 && filter === 'none';
	}

	function applyFx(src, adj, filter) {
		var w = src.width, hh = src.height, out = cv(w, hh), x = out.getContext('2d', { willReadFrequently: true });
		x.drawImage(src, 0, 0);
		var id = x.getImageData(0, 0, w, hh), d = id.data, L = Math.max(w, hh);
		var f = FILTERS[filter] || FILTERS.none;
		var r = Math.round(adj.bl * L / 1000);
		if (r >= 1) boxBlur(d, w, hh, r, 2);
		if (adj.sh > 0) {
			var copy = new Uint8ClampedArray(d);
			boxBlur(copy, w, hh, Math.max(1, Math.round(L / 1000 * 1.5)), 1);
			var amt = adj.sh / 100 * 2;
			for (var q = 0; q < d.length; q += 4) {
				d[q] = d[q] + amt * (d[q] - copy[q]);
				d[q + 1] = d[q + 1] + amt * (d[q + 1] - copy[q + 1]);
				d[q + 2] = d[q + 2] + amt * (d[q + 2] - copy[q + 2]);
			}
		}
		var br = (adj.b + (f.b || 0)) * 2.55;
		var ct = clamp(adj.c + (f.c || 0), -100, 100);
		var sat = 1 + clamp(adj.s + (f.s || 0), -100, 100) / 100;
		var wm = (adj.w + (f.w || 0)) * 0.4;
		var cf = (259 * (ct + 255)) / (255 * (259 - ct));
		var sep = f.sepia || 0, fade = f.fade || 0, inv = !!f.invert;
		var simple = br === 0 && ct === 0 && sat === 1 && wm === 0 && !sep && !fade && !inv;
		if (!simple) {
			for (var i = 0; i < d.length; i += 4) {
				var rr = d[i] + br + wm, gg = d[i + 1] + br, bb = d[i + 2] + br - wm;
				if (ct !== 0) {
					rr = cf * (rr - 128) + 128;
					gg = cf * (gg - 128) + 128;
					bb = cf * (bb - 128) + 128;
				}
				if (sat !== 1) {
					var gr = 0.299 * rr + 0.587 * gg + 0.114 * bb;
					rr = gr + (rr - gr) * sat;
					gg = gr + (gg - gr) * sat;
					bb = gr + (bb - gr) * sat;
				}
				if (sep) {
					var sr = 0.393 * rr + 0.769 * gg + 0.189 * bb;
					var sg = 0.349 * rr + 0.686 * gg + 0.168 * bb;
					var sb = 0.272 * rr + 0.534 * gg + 0.131 * bb;
					rr += (sr - rr) * sep;
					gg += (sg - gg) * sep;
					bb += (sb - bb) * sep;
				}
				if (fade) {
					rr = rr * (1 - fade) + fade * 140;
					gg = gg * (1 - fade) + fade * 140;
					bb = bb * (1 - fade) + fade * 140;
				}
				if (inv) {
					rr = 255 - rr;
					gg = 255 - gg;
					bb = 255 - bb;
				}
				d[i] = rr;
				d[i + 1] = gg;
				d[i + 2] = bb;
			}
		}
		x.putImageData(id, 0, 0);
		return out;
	}

	function scaleCanvas(src, w, hh) {
		var cur = src;
		while (cur.width / 2 > w && cur.height / 2 > hh) {
			var step = cv(Math.max(1, Math.floor(cur.width / 2)), Math.max(1, Math.floor(cur.height / 2)));
			var sx = step.getContext('2d');
			sx.imageSmoothingQuality = 'high';
			sx.drawImage(cur, 0, 0, step.width, step.height);
			cur = step;
		}
		var out = cv(w, hh), ox = out.getContext('2d');
		ox.imageSmoothingQuality = 'high';
		ox.drawImage(cur, 0, 0, w, hh);
		return out;
	}

	var mctx = cv(1, 1).getContext('2d');

	function textMetrics(a) {
		mctx.font = '700 ' + a.size + 'px ' + FONT;
		var lines = String(a.text).split('\n'), w = 0;
		lines.forEach(function (l) { w = Math.max(w, mctx.measureText(l).width); });
		return { w: Math.max(w, a.size * 0.5), h: lines.length * a.size * 1.2, lines: lines };
	}

	function bbox(a) {
		var xs, ys, m;
		switch (a.t) {
			case 'rect':
			case 'ellipse':
				return { x: Math.min(a.x, a.x + a.w), y: Math.min(a.y, a.y + a.h), w: Math.abs(a.w), h: Math.abs(a.h) };
			case 'line':
			case 'arrow':
				return { x: Math.min(a.x, a.x2), y: Math.min(a.y, a.y2), w: Math.abs(a.x2 - a.x), h: Math.abs(a.y2 - a.y) };
			case 'pen':
				xs = a.pts.map(function (p) { return p[0]; });
				ys = a.pts.map(function (p) { return p[1]; });
				return { x: Math.min.apply(null, xs), y: Math.min.apply(null, ys), w: Math.max.apply(null, xs) - Math.min.apply(null, xs), h: Math.max.apply(null, ys) - Math.min.apply(null, ys) };
			default:
				m = textMetrics(a);
				return { x: a.x, y: a.y, w: m.w, h: m.h };
		}
	}

	function segDist(px, py, x1, y1, x2, y2) {
		var dx = x2 - x1, dy = y2 - y1, l2 = dx * dx + dy * dy;
		var k = l2 === 0 ? 0 : clamp(((px - x1) * dx + (py - y1) * dy) / l2, 0, 1);
		return Math.hypot(px - (x1 + k * dx), py - (y1 + k * dy));
	}

	function hitAnn(a, px, py, tol) {
		var b, i;
		if (a.t === 'line' || a.t === 'arrow') return segDist(px, py, a.x, a.y, a.x2, a.y2) <= tol + a.sw / 2;
		if (a.t === 'pen') {
			for (i = 1; i < a.pts.length; i++) {
				if (segDist(px, py, a.pts[i - 1][0], a.pts[i - 1][1], a.pts[i][0], a.pts[i][1]) <= tol + a.sw / 2) return true;
			}
			return a.pts.length === 1 && Math.hypot(px - a.pts[0][0], py - a.pts[0][1]) <= tol + a.sw;
		}
		b = bbox(a);
		return px >= b.x - tol && px <= b.x + b.w + tol && py >= b.y - tol && py <= b.y + b.h + tol;
	}

	function drawAnn(x, a, s) {
		x.save();
		x.globalAlpha = a.op / 100;
		x.strokeStyle = a.color;
		x.fillStyle = a.color;
		x.lineWidth = a.sw * s;
		x.lineCap = 'round';
		x.lineJoin = 'round';
		var i, ang, hl;
		switch (a.t) {
			case 'rect':
				x.beginPath();
				x.rect(a.x * s, a.y * s, a.w * s, a.h * s);
				if (a.fill) x.fill();
				x.stroke();
				break;
			case 'ellipse':
				x.beginPath();
				x.ellipse((a.x + a.w / 2) * s, (a.y + a.h / 2) * s, Math.abs(a.w / 2) * s, Math.abs(a.h / 2) * s, 0, 0, Math.PI * 2);
				if (a.fill) x.fill();
				x.stroke();
				break;
			case 'line':
			case 'arrow':
				x.beginPath();
				x.moveTo(a.x * s, a.y * s);
				x.lineTo(a.x2 * s, a.y2 * s);
				x.stroke();
				if (a.t === 'arrow') {
					ang = Math.atan2(a.y2 - a.y, a.x2 - a.x);
					hl = (a.sw * 4 + 8) * s;
					x.beginPath();
					x.moveTo(a.x2 * s, a.y2 * s);
					x.lineTo(a.x2 * s - hl * Math.cos(ang - Math.PI / 7), a.y2 * s - hl * Math.sin(ang - Math.PI / 7));
					x.lineTo(a.x2 * s - hl * Math.cos(ang + Math.PI / 7), a.y2 * s - hl * Math.sin(ang + Math.PI / 7));
					x.closePath();
					x.fill();
				}
				break;
			case 'pen':
				x.beginPath();
				x.moveTo(a.pts[0][0] * s, a.pts[0][1] * s);
				if (a.pts.length === 1) x.lineTo(a.pts[0][0] * s + 0.01, a.pts[0][1] * s);
				for (i = 1; i < a.pts.length; i++) x.lineTo(a.pts[i][0] * s, a.pts[i][1] * s);
				x.stroke();
				break;
			case 'text':
				var m = textMetrics(a);
				x.font = '700 ' + a.size * s + 'px ' + FONT;
				x.textBaseline = 'top';
				m.lines.forEach(function (l, n) { x.fillText(l, a.x * s, (a.y + n * a.size * 1.2) * s); });
				break;
		}
		x.restore();
	}

	function drawWm(x, wm, bw, bh, s) {
		if (!wm) return;
		var W = bw * s, H = bh * s, mw, mh, paint, fs, tw;
		if (wm.mode === 'image') {
			if (!wm.img) return;
			mw = W * wm.size / 100;
			mh = mw * wm.img.naturalHeight / wm.img.naturalWidth;
			paint = function (px, py) { x.drawImage(wm.img, px, py, mw, mh); };
		} else {
			if (!wm.text) return;
			fs = 100;
			x.save();
			x.font = '700 ' + fs + 'px ' + FONT;
			tw = x.measureText(wm.text).width || 1;
			fs = fs * (W * wm.size / 100) / tw;
			x.restore();
			mw = W * wm.size / 100;
			mh = fs * 1.15;
			paint = function (px, py) { x.fillText(wm.text, px, py); };
		}
		x.save();
		x.globalAlpha = wm.op / 100;
		if (wm.mode !== 'image') {
			x.font = '700 ' + fs + 'px ' + FONT;
			x.textBaseline = 'top';
			x.fillStyle = wm.color;
		}
		if (wm.tile) {
			var gx = Math.max(mw * 1.6, 20 * s), gy = Math.max(mh * 2.6, 20 * s), span = Math.hypot(W, H), row = 0, i, j;
			x.translate(W / 2, H / 2);
			x.rotate(-Math.PI / 6);
			for (j = -span; j < span; j += gy) {
				var off = (row++ % 2) ? gx / 2 : 0;
				for (i = -span; i < span; i += gx) paint(i + off, j);
			}
		} else {
			var m = W * 0.02, px, py, pr = wm.pos.charAt(0), pc = wm.pos.charAt(1);
			px = pc === 'l' ? m : (pc === 'c' ? (W - mw) / 2 : W - mw - m);
			py = pr === 't' ? m : (pr === 'm' ? (H - mh) / 2 : H - mh - m);
			paint(px, py);
		}
		x.restore();
	}

	function openEditor(name, url) {
		if (document.querySelector('.ie-overlay')) return;
		var img = new Image();
		img.onload = function () { start(name, img); };
		img.onerror = function () { window.showModal(t('ie_load_failed'), t('notification'), { danger: true }); };
		img.src = url + (url.indexOf('?') < 0 ? '?' : '&') + 'ie=' + Date.now();
	}

	function start(name, img) {
		var ext = (name.split('.').pop() || '').toLowerCase();
		var iw = img.naturalWidth, ih = img.naturalHeight, k0 = Math.min(1, MAX_SIDE / Math.max(iw, ih));
		var base = cv(Math.max(1, Math.round(iw * k0)), Math.max(1, Math.round(ih * k0)));
		base.getContext('2d').drawImage(img, 0, 0, base.width, base.height);

		var L0 = Math.max(base.width, base.height);
		var st = {
			base: base, orig: base,
			adj: { b: 0, c: 0, s: 0, w: 0, bl: 0, sh: 0 }, filter: 'none',
			ann: [], wm: null, hist: [], hi: -1,
			tab: 'crop', tool: 'select', sel: -1, comparing: false,
			crop: null, ratio: 'free', pv: null, drag: null, view: null, stage: null
		};
		var sty = { color: '#e63946', sw: Math.max(3, Math.round(L0 / 250)), size: Math.max(16, Math.round(L0 / 18)), fill: false, op: 100 };
		var ui = {};
		var raf = 0;

		function snap() {
			return { base: st.base, adj: Object.assign({}, st.adj), filter: st.filter, ann: JSON.stringify(st.ann), wm: st.wm ? Object.assign({}, st.wm) : null };
		}

		function push() {
			st.hist = st.hist.slice(0, st.hi + 1);
			st.hist.push(snap());
			if (st.hist.length > HIST_MAX) st.hist.shift();
			st.hi = st.hist.length - 1;
			syncUI();
		}

		function restore(s) {
			st.base = s.base;
			st.adj = Object.assign({}, s.adj);
			st.filter = s.filter;
			st.ann = JSON.parse(s.ann);
			st.wm = s.wm ? Object.assign({}, s.wm) : null;
			st.sel = -1;
			st.pv = null;
			st.crop = null;
			if (st.tab === 'crop') initCrop();
			syncUI();
			layout();
		}

		function undo() { if (st.hi > 0) { st.hi--; restore(st.hist[st.hi]); } }
		function redo() { if (st.hi < st.hist.length - 1) { st.hi++; restore(st.hist[st.hi]); } }
		function resetAll() { if (st.hi > 0) { restore(st.hist[0]); push(); } }

		function neutral() { return isNeutralFx(st.adj, st.filter); }
		function hasBake() { return st.ann.length > 0 || !!st.wm || !neutral(); }

		function pvSrc() {
			if (st.pv && st.pv.src === st.base) return st.pv;
			var Lm = Math.max(st.base.width, st.base.height), k = Math.min(1, 1400 / Lm);
			var c = cv(Math.max(1, Math.round(st.base.width * k)), Math.max(1, Math.round(st.base.height * k)));
			var x = c.getContext('2d');
			x.imageSmoothingQuality = 'high';
			x.drawImage(st.base, 0, 0, c.width, c.height);
			st.pv = { src: st.base, cv: c, key: '', fx: null };
			return st.pv;
		}

		function fxPreview() {
			var p = pvSrc(), key = JSON.stringify(st.adj) + st.filter;
			if (p.key !== key || !p.fx) {
				p.fx = neutral() ? p.cv : applyFx(p.cv, st.adj, st.filter);
				p.key = key;
			}
			return p.fx;
		}

		function paintOverlay(x, s) {
			st.ann.forEach(function (a) { drawAnn(x, a, s); });
			drawWm(x, st.wm, st.base.width, st.base.height, s);
		}

		function flatten() {
			if (!hasBake()) return st.base;
			var b = st.base, out = cv(b.width, b.height), x = out.getContext('2d');
			x.drawImage(neutral() ? b : applyFx(b, st.adj, st.filter), 0, 0);
			paintOverlay(x, 1);
			return out;
		}

		function rImg(e) {
			var r = st.view.getBoundingClientRect();
			return { x: (e.clientX - r.left) / r.width * st.base.width, y: (e.clientY - r.top) / r.height * st.base.height, k: st.base.width / r.width };
		}

		function layout() {
			if (!st.view) return;
			var r = st.stage.getBoundingClientRect(), aw = r.width - 24, ah = r.height - 24;
			var bw = st.base.width, bh = st.base.height, k = Math.min(aw / bw, ah / bh, 1);
			if (!isFinite(k) || k <= 0) k = 1;
			var cw = Math.max(1, Math.round(bw * k)), ch = Math.max(1, Math.round(bh * k));
			var dpr = Math.min(window.devicePixelRatio || 1, 2);
			st.view.style.width = cw + 'px';
			st.view.style.height = ch + 'px';
			st.view.width = Math.round(cw * dpr);
			st.view.height = Math.round(ch * dpr);
			draw();
		}

		function schedule() {
			if (raf) return;
			raf = requestAnimationFrame(function () { raf = 0; draw(); });
		}

		function draw() {
			if (!st.view) return;
			var v = st.view, x = v.getContext('2d'), W = v.width, H = v.height;
			x.clearRect(0, 0, W, H);
			if (st.comparing) {
				var o = st.orig, ko = Math.min(W / o.width, H / o.height), ow = o.width * ko, oh = o.height * ko;
				x.imageSmoothingQuality = 'high';
				x.drawImage(o, (W - ow) / 2, (H - oh) / 2, ow, oh);
				return;
			}
			x.imageSmoothingQuality = 'high';
			x.drawImage(fxPreview(), 0, 0, W, H);
			var s = W / st.base.width;
			paintOverlay(x, s);
			if (st.tab === 'crop' && st.crop) drawCropUI(x, s);
			if (st.tab === 'annotate' && st.sel >= 0 && st.ann[st.sel]) drawSelUI(x, s);
		}

		function hs() { return (st.view.width / st.view.getBoundingClientRect().width) * 5; }

		function cropHandles() {
			var c = st.crop, free = RATIOS_VALUE() === null;
			var pts = [['nw', c.x, c.y], ['ne', c.x + c.w, c.y], ['sw', c.x, c.y + c.h], ['se', c.x + c.w, c.y + c.h]];
			if (free) pts.push(['n', c.x + c.w / 2, c.y], ['s', c.x + c.w / 2, c.y + c.h], ['w', c.x, c.y + c.h / 2], ['e', c.x + c.w, c.y + c.h / 2]);
			return pts;
		}

		function drawCropUI(x, s) {
			var c = st.crop, W = st.view.width, H = st.view.height, cx = c.x * s, cy = c.y * s, cw = c.w * s, ch = c.h * s, hz = hs(), i;
			x.save();
			x.fillStyle = 'rgba(0,0,0,0.55)';
			x.fillRect(0, 0, W, cy);
			x.fillRect(0, cy + ch, W, H - cy - ch);
			x.fillRect(0, cy, cx, ch);
			x.fillRect(cx + cw, cy, W - cx - cw, ch);
			x.strokeStyle = '#fff';
			x.lineWidth = Math.max(1, hz / 3);
			x.strokeRect(cx, cy, cw, ch);
			x.globalAlpha = 0.35;
			x.beginPath();
			for (i = 1; i < 3; i++) {
				x.moveTo(cx + cw * i / 3, cy);
				x.lineTo(cx + cw * i / 3, cy + ch);
				x.moveTo(cx, cy + ch * i / 3);
				x.lineTo(cx + cw, cy + ch * i / 3);
			}
			x.stroke();
			x.globalAlpha = 1;
			x.fillStyle = '#fff';
			x.strokeStyle = 'rgba(0,0,0,0.6)';
			cropHandles().forEach(function (p) {
				x.fillRect(p[1] * s - hz, p[2] * s - hz, hz * 2, hz * 2);
				x.strokeRect(p[1] * s - hz, p[2] * s - hz, hz * 2, hz * 2);
			});
			x.restore();
		}

		function annHandles(a) {
			if (a.t === 'line' || a.t === 'arrow') return [['p1', a.x, a.y], ['p2', a.x2, a.y2]];
			if (a.t === 'pen') return [];
			var b = bbox(a);
			return [['se', b.x + b.w, b.y + b.h]];
		}

		function drawSelUI(x, s) {
			var a = st.ann[st.sel], b = bbox(a), hz = hs();
			x.save();
			x.strokeStyle = '#2f80ed';
			x.fillStyle = '#fff';
			x.lineWidth = Math.max(1, hz / 3);
			x.setLineDash([hz, hz]);
			x.strokeRect(b.x * s - hz, b.y * s - hz, b.w * s + hz * 2, b.h * s + hz * 2);
			x.setLineDash([]);
			annHandles(a).forEach(function (p) {
				x.beginPath();
				x.arc(p[1] * s, p[2] * s, hz * 1.2, 0, Math.PI * 2);
				x.fill();
				x.stroke();
			});
			x.restore();
		}

		function RATIOS_VALUE() {
			if (st.ratio === 'free') return null;
			if (st.ratio === 'orig') return st.base.width / st.base.height;
			for (var i = 0; i < RATIOS.length; i++) if (RATIOS[i][0] === st.ratio) return RATIOS[i][1];
			return null;
		}

		function initCrop() {
			var bw = st.base.width, bh = st.base.height, r = RATIOS_VALUE();
			if (r === null) {
				st.crop = { x: 0, y: 0, w: bw, h: bh };
			} else {
				var w = bw, hh = w / r;
				if (hh > bh) { hh = bh; w = hh * r; }
				st.crop = { x: (bw - w) / 2, y: (bh - hh) / 2, w: w, h: hh };
			}
			syncUI();
		}

		function cropDown(p) {
			var c = st.crop, tol = 14 * p.k, best = null, bd = tol;
			cropHandles().forEach(function (hp) {
				var d = Math.hypot(p.x - hp[1], p.y - hp[2]);
				if (d <= bd) { bd = d; best = hp[0]; }
			});
			if (best) st.drag = { mode: 'crop-h', h: best, o: Object.assign({}, c) };
			else if (p.x >= c.x && p.x <= c.x + c.w && p.y >= c.y && p.y <= c.y + c.h) st.drag = { mode: 'crop-m', sx: p.x, sy: p.y, o: Object.assign({}, c) };
		}

		function cropMove(p) {
			var d = st.drag, bw = st.base.width, bh = st.base.height, o = d.o, min = 16, r = RATIOS_VALUE();
			if (d.mode === 'crop-m') {
				st.crop = { x: clamp(o.x + p.x - d.sx, 0, bw - o.w), y: clamp(o.y + p.y - d.sy, 0, bh - o.h), w: o.w, h: o.h };
				return;
			}
			var px = clamp(p.x, 0, bw), py = clamp(p.y, 0, bh), hd = d.h;
			if (r !== null) {
				var ax = hd.indexOf('w') >= 0 ? o.x + o.w : o.x, ay = hd.indexOf('n') >= 0 ? o.y + o.h : o.y;
				var dx = px - ax, dy = py - ay, sx = dx < 0 ? -1 : 1, sy = dy < 0 ? -1 : 1;
				var w = Math.max(Math.abs(dx), Math.abs(dy) * r);
				w = Math.min(w, sx > 0 ? bw - ax : ax, (sy > 0 ? bh - ay : ay) * r);
				w = Math.max(w, min);
				var hh = w / r;
				st.crop = { x: sx > 0 ? ax : ax - w, y: sy > 0 ? ay : ay - hh, w: w, h: hh };
				return;
			}
			var l = o.x, tp = o.y, rr = o.x + o.w, bt = o.y + o.h;
			if (hd.indexOf('w') >= 0) l = Math.min(px, rr - min);
			if (hd.indexOf('e') >= 0) rr = Math.max(px, l + min);
			if (hd.indexOf('n') >= 0) tp = Math.min(py, bt - min);
			if (hd.indexOf('s') >= 0) bt = Math.max(py, tp + min);
			st.crop = { x: l, y: tp, w: rr - l, h: bt - tp };
		}

		function commitBase(out) {
			st.base = out;
			st.adj = { b: 0, c: 0, s: 0, w: 0, bl: 0, sh: 0 };
			st.filter = 'none';
			st.ann = [];
			st.wm = null;
			st.sel = -1;
			st.crop = null;
			st.pv = null;
			if (st.tab === 'crop') initCrop();
			push();
			layout();
		}

		function guard(fn) {
			if (!hasBake()) { fn(); return; }
			window.showModal(t('ie_baked'), t('ie_title'), { showCancel: true, onConfirm: fn });
		}

		function applyCrop() {
			var c = st.crop, src = flatten(), w = Math.max(1, Math.round(c.w)), hh = Math.max(1, Math.round(c.h));
			var out = cv(w, hh);
			out.getContext('2d').drawImage(src, Math.round(c.x), Math.round(c.y), w, hh, 0, 0, w, hh);
			commitBase(out);
		}

		function rotate(dir) {
			var s = flatten(), o = cv(s.height, s.width), x = o.getContext('2d');
			x.translate(o.width / 2, o.height / 2);
			x.rotate(dir * Math.PI / 2);
			x.drawImage(s, -s.width / 2, -s.height / 2);
			commitBase(o);
		}

		function flip(horizontal) {
			var s = flatten(), o = cv(s.width, s.height), x = o.getContext('2d');
			if (horizontal) { x.translate(s.width, 0); x.scale(-1, 1); }
			else { x.translate(0, s.height); x.scale(1, -1); }
			x.drawImage(s, 0, 0);
			commitBase(o);
		}

		function applyResize() {
			var w = clamp(parseInt(ui.rw.value, 10) || 0, 1, MAX_SIDE), hh = clamp(parseInt(ui.rh.value, 10) || 0, 1, MAX_SIDE);
			if (w === st.base.width && hh === st.base.height) return;
			commitBase(scaleCanvas(flatten(), w, hh));
		}

		function setTab(n) {
			st.tab = n;
			if (n !== 'annotate') st.sel = -1;
			if (n === 'crop' && !st.crop) initCrop();
			Object.keys(ui.tabs).forEach(function (k) {
				ui.tabs[k].classList.toggle('active', k === n);
				ui.panels[k].hidden = k !== n;
			});
			if (n === 'filters') buildFilterThumbs();
			syncUI();
			draw();
		}

		function selectedAnn() { return st.sel >= 0 ? st.ann[st.sel] : null; }

		function annKind() {
			var a = selectedAnn();
			return a ? a.t : st.tool;
		}

		function newAnn(tool, p) {
			var a = { t: tool, x: p.x, y: p.y, color: sty.color, sw: sty.sw, op: sty.op };
			if (tool === 'rect' || tool === 'ellipse') { a.w = 0; a.h = 0; a.fill = sty.fill; }
			else if (tool === 'line' || tool === 'arrow') { a.x2 = p.x; a.y2 = p.y; }
			else if (tool === 'pen') { a.pts = [[p.x, p.y]]; }
			else if (tool === 'text') { a.text = t('ie_default_text'); a.size = sty.size; }
			return a;
		}

		function moveAnn(a, o, dx, dy) {
			if (a.t === 'pen') a.pts = o.pts.map(function (q) { return [q[0] + dx, q[1] + dy]; });
			else if (a.t === 'line' || a.t === 'arrow') { a.x = o.x + dx; a.y = o.y + dy; a.x2 = o.x2 + dx; a.y2 = o.y2 + dy; }
			else { a.x = o.x + dx; a.y = o.y + dy; }
		}

		function annDown(p) {
			var a, i, tol = 10 * p.k;
			if (st.tool === 'select') {
				a = selectedAnn();
				if (a) {
					var hd = annHandles(a);
					for (i = 0; i < hd.length; i++) {
						if (Math.hypot(p.x - hd[i][1], p.y - hd[i][2]) <= tol * 1.4) {
							st.drag = { mode: 'ann-h', h: hd[i][0], o: JSON.parse(JSON.stringify(a)) };
							return;
						}
					}
				}
				st.sel = -1;
				for (i = st.ann.length - 1; i >= 0; i--) {
					if (hitAnn(st.ann[i], p.x, p.y, tol)) { st.sel = i; break; }
				}
				if (st.sel >= 0) st.drag = { mode: 'ann-m', sx: p.x, sy: p.y, o: JSON.parse(JSON.stringify(st.ann[st.sel])) };
				syncUI();
				return;
			}
			a = newAnn(st.tool, p);
			st.ann.push(a);
			st.sel = st.ann.length - 1;
			if (st.tool === 'text') {
				push();
				setTool('select');
				setTimeout(function () { ui.txt.focus(); ui.txt.select(); }, 0);
				return;
			}
			st.drag = { mode: 'ann-c' };
			syncUI();
		}

		function annMove(p) {
			var d = st.drag, a = selectedAnn();
			if (!a) return;
			if (d.mode === 'ann-m') {
				moveAnn(a, d.o, p.x - d.sx, p.y - d.sy);
			} else if (d.mode === 'ann-c') {
				if (a.t === 'rect' || a.t === 'ellipse') { a.w = p.x - a.x; a.h = p.y - a.y; }
				else if (a.t === 'line' || a.t === 'arrow') { a.x2 = p.x; a.y2 = p.y; }
				else if (a.t === 'pen') { a.pts.push([p.x, p.y]); }
			} else if (d.mode === 'ann-h') {
				if (d.h === 'p1') { a.x = p.x; a.y = p.y; }
				else if (d.h === 'p2') { a.x2 = p.x; a.y2 = p.y; }
				else if (a.t === 'text') {
					var ow = bbox(d.o).w;
					a.size = clamp(d.o.size * (p.x - d.o.x) / (ow || 1), 8, Math.max(base.width, base.height));
				} else { a.w = p.x - a.x; a.h = p.y - a.y; }
			}
		}

		function annUp(d) {
			var a = selectedAnn();
			if (!a) return;
			if (d.mode === 'ann-c') {
				if (a.t === 'rect' || a.t === 'ellipse') {
					if (Math.abs(a.w) < 3 && Math.abs(a.h) < 3) { st.ann.pop(); st.sel = -1; syncUI(); return; }
					if (a.w < 0) { a.x += a.w; a.w = -a.w; }
					if (a.h < 0) { a.y += a.h; a.h = -a.h; }
				} else if ((a.t === 'line' || a.t === 'arrow') && Math.hypot(a.x2 - a.x, a.y2 - a.y) < 3) {
					st.ann.pop(); st.sel = -1; syncUI(); return;
				}
			} else if (d.mode === 'ann-h' && (a.t === 'rect' || a.t === 'ellipse')) {
				if (a.w < 0) { a.x += a.w; a.w = -a.w; }
				if (a.h < 0) { a.y += a.h; a.h = -a.h; }
			}
			push();
		}

		function onDown(e) {
			if (st.comparing || e.button > 0) return;
			e.preventDefault();
			var p = rImg(e);
			st.drag = null;
			if (st.tab === 'crop' && st.crop) cropDown(p);
			else if (st.tab === 'annotate') annDown(p);
			if (st.drag) st.view.setPointerCapture(e.pointerId);
			draw();
		}

		function onMove(e) {
			if (!st.drag) return;
			var p = rImg(e);
			if (st.drag.mode.indexOf('crop') === 0) { cropMove(p); syncCropInfo(); }
			else annMove(p);
			schedule();
		}

		function onUp() {
			if (!st.drag) return;
			var d = st.drag;
			st.drag = null;
			if (d.mode.indexOf('ann') === 0) annUp(d);
			draw();
		}

		function deleteSel() {
			if (st.sel < 0) return;
			st.ann.splice(st.sel, 1);
			st.sel = -1;
			push();
			draw();
		}

		function setTool(n) {
			st.tool = n;
			if (n !== 'select') st.sel = -1;
			syncUI();
			draw();
		}

		function slider(label, min, max, val, step, onInput, onChange) {
			var inp = h('input', { type: 'range', min: min, max: max, step: step || 1, value: val });
			var out = h('output', { text: String(val) });
			inp.addEventListener('input', function () { out.textContent = inp.value; onInput(parseFloat(inp.value)); });
			inp.addEventListener('change', function () { if (onChange) onChange(parseFloat(inp.value)); });
			var row = h('label', { class: 'ie-row' }, [h('span', { class: 'ie-row-label', text: label }), inp, out]);
			return { row: row, inp: inp, set: function (v) { inp.value = v; out.textContent = String(Math.round(v * 100) / 100); } };
		}

		function btn(icon, label, onclick, cls) {
			return h('button', { type: 'button', class: 'ie-btn ' + (cls || ''), onclick: onclick, title: label, 'aria-label': label, html: (icon ? ico(icon, 16) : '') + (cls && cls.indexOf('ie-icon') >= 0 ? '' : '<span>' + escapeText(label) + '</span>') });
		}

		function escapeText(s) {
			return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
		}

		function buildCropPanel() {
			var sel = h('div', { class: 'ie-chips' });
			ui.ratioBtns = {};
			RATIOS.forEach(function (r) {
				var b = h('button', { type: 'button', class: 'ie-chip', text: r[0] === 'free' ? t('ie_crop_free') : (r[0] === 'orig' ? t('ie_crop_original') : r[0]) });
				b.addEventListener('click', function () { st.ratio = r[0]; initCrop(); draw(); });
				ui.ratioBtns[r[0]] = b;
				sel.appendChild(b);
			});
			ui.cropInfo = h('p', { class: 'ie-info' });
			return h('div', { class: 'ie-panel-body' }, [sel, ui.cropInfo, btn('', t('ie_apply'), function () { guard(applyCrop); }, 'ie-primary')]);
		}

		function buildRotatePanel() {
			return h('div', { class: 'ie-panel-body ie-grid2' }, [
				btn('rotccw', t('ie_rotate_left'), function () { guard(function () { rotate(-1); }); }),
				btn('rotcw', t('ie_rotate_right'), function () { guard(function () { rotate(1); }); }),
				btn('fliph', t('ie_flip_h'), function () { guard(function () { flip(true); }); }),
				btn('flipv', t('ie_flip_v'), function () { guard(function () { flip(false); }); })
			]);
		}

		function buildResizePanel() {
			ui.rw = h('input', { type: 'number', min: 1, max: MAX_SIDE, class: 'ie-num' });
			ui.rh = h('input', { type: 'number', min: 1, max: MAX_SIDE, class: 'ie-num' });
			ui.lock = h('input', { type: 'checkbox', checked: true });
			ui.rw.addEventListener('input', function () {
				if (ui.lock.checked) ui.rh.value = Math.max(1, Math.round((parseInt(ui.rw.value, 10) || 0) * st.base.height / st.base.width));
			});
			ui.rh.addEventListener('input', function () {
				if (ui.lock.checked) ui.rw.value = Math.max(1, Math.round((parseInt(ui.rh.value, 10) || 0) * st.base.width / st.base.height));
			});
			return h('div', { class: 'ie-panel-body' }, [
				h('label', { class: 'ie-row' }, [h('span', { class: 'ie-row-label', text: t('ie_width') }), ui.rw, h('span', { text: 'px' })]),
				h('label', { class: 'ie-row' }, [h('span', { class: 'ie-row-label', text: t('ie_height') }), ui.rh, h('span', { text: 'px' })]),
				h('label', { class: 'ie-check' }, [ui.lock, h('span', { text: t('ie_keep_ratio') })]),
				btn('', t('ie_apply'), function () { guard(applyResize); }, 'ie-primary')
			]);
		}

		function buildAdjustPanel() {
			ui.adj = {};
			var defs = [['b', 'ie_brightness', -100, 100], ['c', 'ie_contrast', -100, 100], ['s', 'ie_saturation', -100, 100], ['w', 'ie_warmth', -100, 100], ['bl', 'ie_blur', 0, 20], ['sh', 'ie_sharpen', 0, 100]];
			var kids = defs.map(function (d) {
				var sl = slider(t(d[1]), d[2], d[3], 0, 1, function (v) { st.adj[d[0]] = v; schedule(); }, function () { push(); });
				ui.adj[d[0]] = sl;
				return sl.row;
			});
			kids.push(btn('', t('ie_adjust_reset'), function () {
				st.adj = { b: 0, c: 0, s: 0, w: 0, bl: 0, sh: 0 };
				push();
				draw();
			}));
			return h('div', { class: 'ie-panel-body' }, kids);
		}

		function buildFiltersPanel() {
			ui.filterGrid = h('div', { class: 'ie-filters' });
			return h('div', { class: 'ie-panel-body' }, [ui.filterGrid]);
		}

		function buildFilterThumbs() {
			var p = pvSrc().cv, k = Math.min(1, 110 / Math.max(p.width, p.height));
			var th = cv(Math.max(1, Math.round(p.width * k)), Math.max(1, Math.round(p.height * k)));
			th.getContext('2d').drawImage(p, 0, 0, th.width, th.height);
			ui.filterGrid.innerHTML = '';
			ui.filterBtns = {};
			FILTER_ORDER.forEach(function (n) {
				var c = n === 'none' ? th : applyFx(th, { b: 0, c: 0, s: 0, w: 0, bl: 0, sh: 0 }, n);
				var shown = cv(c.width, c.height);
				shown.getContext('2d').drawImage(c, 0, 0);
				var b = h('button', { type: 'button', class: 'ie-filter' + (st.filter === n ? ' active' : '') }, [shown, h('span', { text: t('ie_f_' + n) })]);
				b.addEventListener('click', function () {
					st.filter = n;
					push();
					draw();
				});
				ui.filterBtns[n] = b;
				ui.filterGrid.appendChild(b);
			});
		}

		function buildAnnotatePanel() {
			var tools = [['select', 'cursor', 'ie_t_select'], ['text', 'type', 'ie_t_text'], ['rect', 'square', 'ie_t_rect'], ['ellipse', 'circle', 'ie_t_ellipse'], ['line', 'line', 'ie_t_line'], ['arrow', 'arrow', 'ie_t_arrow'], ['pen', 'pen', 'ie_t_pen']];
			ui.toolBtns = {};
			var bar = h('div', { class: 'ie-tools' });
			tools.forEach(function (d) {
				var b = h('button', { type: 'button', class: 'ie-tool', title: t(d[2]), 'aria-label': t(d[2]), html: ico(d[1], 18) });
				b.addEventListener('click', function () { setTool(d[0]); });
				ui.toolBtns[d[0]] = b;
				bar.appendChild(b);
			});

			ui.txt = h('textarea', { class: 'ie-text', rows: 2 });
			ui.txt.addEventListener('input', function () {
				var a = selectedAnn();
				if (a && a.t === 'text') { a.text = ui.txt.value || ' '; schedule(); }
			});
			ui.txt.addEventListener('change', function () { if (selectedAnn()) push(); });
			ui.txtRow = h('div', { class: 'ie-row-block' }, [ui.txt]);

			ui.color = h('input', { type: 'color', value: sty.color });
			ui.color.addEventListener('input', function () {
				sty.color = ui.color.value;
				var a = selectedAnn();
				if (a) { a.color = sty.color; schedule(); }
			});
			ui.color.addEventListener('change', function () { if (selectedAnn()) push(); });
			ui.colorRow = h('label', { class: 'ie-row' }, [h('span', { class: 'ie-row-label', text: t('ie_color') }), ui.color]);

			ui.sw = slider(t('ie_stroke'), 1, 40, sty.sw, 1, function (v) {
				sty.sw = v;
				var a = selectedAnn();
				if (a && a.t !== 'text') { a.sw = v; schedule(); }
			}, function () { if (selectedAnn()) push(); });
			ui.size = slider(t('ie_size'), 8, 400, sty.size, 1, function (v) {
				sty.size = v;
				var a = selectedAnn();
				if (a && a.t === 'text') { a.size = v; schedule(); }
			}, function () { if (selectedAnn()) push(); });
			ui.op = slider(t('ie_opacity'), 5, 100, sty.op, 1, function (v) {
				sty.op = v;
				var a = selectedAnn();
				if (a) { a.op = v; schedule(); }
			}, function () { if (selectedAnn()) push(); });

			ui.fill = h('input', { type: 'checkbox' });
			ui.fill.addEventListener('change', function () {
				sty.fill = ui.fill.checked;
				var a = selectedAnn();
				if (a && (a.t === 'rect' || a.t === 'ellipse')) { a.fill = sty.fill; push(); draw(); }
			});
			ui.fillRow = h('label', { class: 'ie-check' }, [ui.fill, h('span', { text: t('ie_fill') })]);
			ui.del = btn('trash', t('ie_delete'), deleteSel, 'ie-danger');

			return h('div', { class: 'ie-panel-body' }, [bar, ui.txtRow, ui.colorRow, ui.size.row, ui.sw.row, ui.fillRow, ui.op.row, ui.del]);
		}

		function ensureWm() {
			if (!st.wm) st.wm = { mode: 'text', text: '', img: null, pos: 'br', tile: false, size: 30, op: 60, color: '#ffffff' };
			return st.wm;
		}

		function buildWatermarkPanel() {
			ui.wm = {};
			var mode = h('div', { class: 'ie-chips' });
			['text', 'image'].forEach(function (m) {
				var b = h('button', { type: 'button', class: 'ie-chip', text: t(m === 'text' ? 'ie_wm_text' : 'ie_wm_image') });
				b.addEventListener('click', function () { ensureWm().mode = m; push(); draw(); });
				ui.wm['m_' + m] = b;
				mode.appendChild(b);
			});
			ui.wm.text = h('input', { type: 'text', class: 'ie-input', placeholder: t('ie_wm_text_ph'), maxlength: 120 });
			ui.wm.text.addEventListener('input', function () { ensureWm().text = ui.wm.text.value; schedule(); });
			ui.wm.text.addEventListener('change', function () { push(); });
			ui.wm.color = h('input', { type: 'color', value: '#ffffff' });
			ui.wm.color.addEventListener('input', function () { ensureWm().color = ui.wm.color.value; schedule(); });
			ui.wm.color.addEventListener('change', function () { push(); });
			ui.wm.textRow = h('div', { class: 'ie-row-block' }, [ui.wm.text, h('label', { class: 'ie-row' }, [h('span', { class: 'ie-row-label', text: t('ie_color') }), ui.wm.color])]);

			ui.wm.file = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml', class: 'ie-file' });
			ui.wm.file.addEventListener('change', function () {
				var f = ui.wm.file.files && ui.wm.file.files[0];
				if (!f) return;
				var im = new Image();
				im.onload = function () {
					var w = ensureWm();
					w.mode = 'image';
					w.img = im;
					push();
					draw();
				};
				im.src = URL.createObjectURL(f);
			});
			ui.wm.imgRow = h('div', { class: 'ie-row-block' }, [ui.wm.file]);

			var grid = h('div', { class: 'ie-posgrid' });
			ui.wm.pos = {};
			['tl', 'tc', 'tr', 'ml', 'mc', 'mr', 'bl', 'bc', 'br'].forEach(function (p) {
				var b = h('button', { type: 'button', class: 'ie-pos', 'aria-label': p });
				b.addEventListener('click', function () { var w = ensureWm(); w.pos = p; w.tile = false; push(); draw(); });
				ui.wm.pos[p] = b;
				grid.appendChild(b);
			});
			ui.wm.tile = h('input', { type: 'checkbox' });
			ui.wm.tile.addEventListener('change', function () { ensureWm().tile = ui.wm.tile.checked; push(); draw(); });
			ui.wm.size = slider(t('ie_wm_size'), 5, 100, 30, 1, function (v) { ensureWm().size = v; schedule(); }, function () { push(); });
			ui.wm.op = slider(t('ie_wm_opacity'), 5, 100, 60, 1, function (v) { ensureWm().op = v; schedule(); }, function () { push(); });
			ui.wm.remove = btn('trash', t('ie_wm_remove'), function () { st.wm = null; push(); draw(); }, 'ie-danger');

			return h('div', { class: 'ie-panel-body' }, [
				mode, ui.wm.textRow, ui.wm.imgRow,
				h('div', { class: 'ie-row-block' }, [h('span', { class: 'ie-row-label', text: t('ie_wm_position') }), grid]),
				h('label', { class: 'ie-check' }, [ui.wm.tile, h('span', { text: t('ie_wm_tile') })]),
				ui.wm.size.row, ui.wm.op.row, ui.wm.remove
			]);
		}

		function syncCropInfo() {
			if (ui.cropInfo && st.crop) ui.cropInfo.textContent = Math.round(st.crop.w) + ' × ' + Math.round(st.crop.h) + ' px';
		}

		function syncUI() {
			if (!ui.root) return;
			ui.undo.disabled = st.hi <= 0;
			ui.redo.disabled = st.hi >= st.hist.length - 1;
			ui.reset.disabled = st.hi <= 0;
			Object.keys(ui.adj).forEach(function (kx) { ui.adj[kx].set(st.adj[kx]); });
			if (ui.filterBtns) Object.keys(ui.filterBtns).forEach(function (n) { ui.filterBtns[n].classList.toggle('active', st.filter === n); });
			ui.rw.value = st.base.width;
			ui.rh.value = st.base.height;
			Object.keys(ui.ratioBtns).forEach(function (r) { ui.ratioBtns[r].classList.toggle('active', st.ratio === r); });
			syncCropInfo();

			var L = Math.max(st.base.width, st.base.height);
			ui.size.inp.max = Math.max(40, Math.round(L / 3));
			ui.sw.inp.max = Math.max(10, Math.round(L / 40));

			Object.keys(ui.toolBtns).forEach(function (n) { ui.toolBtns[n].classList.toggle('active', st.tool === n); });
			var a = selectedAnn(), kind = annKind();
			if (a) {
				ui.color.value = a.color;
				ui.op.set(a.op);
				if (a.t === 'text') { ui.txt.value = a.text; ui.size.set(a.size); }
				else ui.sw.set(a.sw);
				if (a.t === 'rect' || a.t === 'ellipse') ui.fill.checked = !!a.fill;
			} else {
				ui.color.value = sty.color;
				ui.op.set(sty.op);
				ui.size.set(sty.size);
				ui.sw.set(sty.sw);
				ui.fill.checked = sty.fill;
			}
			ui.txtRow.hidden = kind !== 'text' || !a;
			ui.size.row.hidden = kind !== 'text';
			ui.sw.row.hidden = kind === 'text' || kind === 'select';
			ui.fillRow.hidden = kind !== 'rect' && kind !== 'ellipse';
			ui.colorRow.hidden = kind === 'select' && !a;
			ui.op.row.hidden = kind === 'select' && !a;
			ui.del.hidden = !a;

			var w = st.wm || { mode: 'text', text: '', pos: 'br', tile: false, size: 30, op: 60, color: '#ffffff' };
			ui.wm.m_text.classList.toggle('active', w.mode === 'text');
			ui.wm.m_image.classList.toggle('active', w.mode === 'image');
			ui.wm.textRow.hidden = w.mode !== 'text';
			ui.wm.imgRow.hidden = w.mode !== 'image';
			if (document.activeElement !== ui.wm.text) ui.wm.text.value = w.text || '';
			ui.wm.color.value = w.color;
			ui.wm.tile.checked = !!w.tile;
			ui.wm.size.set(w.size);
			ui.wm.op.set(w.op);
			Object.keys(ui.wm.pos).forEach(function (p) { ui.wm.pos[p].classList.toggle('active', !w.tile && w.pos === p); });
			ui.wm.remove.hidden = !st.wm;
		}

		function close(force) {
			if (!force && st.hi > 0) {
				window.showModal(t('ie_confirm_discard'), t('ie_title'), { showCancel: true, danger: true, onConfirm: function () { close(true); } });
				return;
			}
			document.removeEventListener('keydown', onKey, true);
			if (ro) ro.disconnect();
			window.removeEventListener('resize', layout);
			document.documentElement.classList.remove('ie-lock');
			if (ui.root.parentNode) ui.root.parentNode.removeChild(ui.root);
		}

		function onKey(e) {
			var tag = (e.target && e.target.tagName) || '';
			var typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
			var gm = document.getElementById('global-modal');
			if (gm && gm.style.display === 'flex') return;
			if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(false); return; }
			var mod = e.ctrlKey || e.metaKey;
			if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); if (e.shiftKey) redo(); else undo(); return; }
			if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); return; }
			if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); openSave(); return; }
			if (!typing && (e.key === 'Delete' || e.key === 'Backspace') && st.tab === 'annotate' && st.sel >= 0) { e.preventDefault(); deleteSel(); }
		}

		var ro = null;

		function exportBlob(format, quality) {
			var src = flatten(), out = src;
			if (format === 'jpeg') {
				out = cv(src.width, src.height);
				var x = out.getContext('2d');
				x.fillStyle = '#fff';
				x.fillRect(0, 0, out.width, out.height);
				x.drawImage(src, 0, 0);
			}
			return new Promise(function (res) { out.toBlob(res, 'image/' + format, quality / 100); });
		}

		function openSave() {
			var gm = document.getElementById('global-modal');
			if (gm && gm.style.display === 'flex') return;
			var stem = name.replace(/\.[^.]+$/, '');
			var defFormat = ext === 'png' ? 'png' : (ext === 'webp' && CFG.webp ? 'webp' : (ext === 'jpg' || ext === 'jpeg' ? 'jpeg' : 'png'));
			var fmt = h('select', { class: 'ie-input' });
			[['png', 'PNG'], ['jpeg', 'JPEG'], ['webp', 'WebP']].forEach(function (f) {
				if (f[0] === 'webp' && !CFG.webp) return;
				fmt.appendChild(h('option', { value: f[0], text: f[1], selected: f[0] === defFormat }));
			});
			var nameInp = h('input', { type: 'text', class: 'ie-input', value: stem + '-edited', maxlength: 100 });
			var q = slider(t('ie_save_quality'), 40, 100, 90, 1, function () {}, null);
			var rReplace = h('input', { type: 'radio', name: 'ie-mode', value: 'replace' });
			var rCopy = h('input', { type: 'radio', name: 'ie-mode', value: 'copy', checked: true });
			var nameRow = h('label', { class: 'ie-row-block' }, [h('span', { class: 'ie-row-label', text: t('ie_save_name') }), nameInp]);

			function canReplace() {
				var f = fmt.value;
				return ext !== 'gif' && ((f === 'jpeg' && (ext === 'jpg' || ext === 'jpeg')) || f === ext);
			}
			function refresh() {
				var ok = canReplace();
				rReplace.disabled = !ok;
				if (!ok && rReplace.checked) rCopy.checked = true;
				nameRow.hidden = rReplace.checked;
				q.row.hidden = fmt.value === 'png';
			}
			fmt.addEventListener('change', refresh);
			rReplace.addEventListener('change', refresh);
			rCopy.addEventListener('change', refresh);

			function send() {
				var format = fmt.value, quality = q.inp.value, mode = rReplace.checked ? 'replace' : 'copy', newName = nameInp.value;
				window.showModal(t('ie_saving'), t('ie_save_title'), { confirmText: null });
				exportBlob(format, parseInt(quality, 10)).then(function (blob) {
					if (!blob) throw new Error(t('ie_err_write'));
					var fd = new FormData();
					fd.append('action', 'ie_save');
					fd.append('csrf_token', CFG.csrf);
					fd.append('path', CFG.path || '');
					fd.append('name', name);
					fd.append('mode', mode);
					fd.append('new_name', newName);
					fd.append('format', format);
					fd.append('quality', quality);
					fd.append('ie_image', blob, 'edited.' + (format === 'jpeg' ? 'jpg' : format));
					return fetch(window.location.href.split('#')[0], { method: 'POST', body: fd, credentials: 'same-origin' });
				}).then(function (r) {
					return r.json();
				}).then(function (j) {
					if (!j || !j.success) throw new Error((j && j.message) || t('ie_err_write'));
					window.location.reload();
				}).catch(function (err) {
					window.showModal(escapeText(err && err.message ? err.message : t('ie_err_write')), t('notification'), { danger: true });
				});
			}

			window.showModal('<div class="ie-save-form"></div>', t('ie_save_title'), {
				showCancel: true,
				confirmText: t('ie_save_btn'),
				onOpen: function (modal) {
					var box = modal.querySelector('.ie-save-form');
					[
						h('label', { class: 'ie-check' }, [rReplace, h('span', { text: t('ie_save_replace') })]),
						h('label', { class: 'ie-check' }, [rCopy, h('span', { text: t('ie_save_copy') })]),
						nameRow,
						h('label', { class: 'ie-row-block' }, [h('span', { class: 'ie-row-label', text: t('ie_save_format') }), fmt]),
						q.row
					].forEach(function (el) { box.appendChild(el); });
					refresh();
					nameInp.focus();
				},
				onConfirm: send
			});
		}

		function buildRoot() {
			var tabDefs = [['crop', 'crop', 'ie_tab_crop'], ['rotate', 'rotcw', 'ie_tab_rotate'], ['resize', 'resize', 'ie_tab_resize'], ['adjust', 'sliders', 'ie_tab_adjust'], ['filters', 'layers', 'ie_tab_filters'], ['annotate', 'pen', 'ie_tab_annotate'], ['watermark', 'drop', 'ie_tab_watermark']];
			var builders = { crop: buildCropPanel, rotate: buildRotatePanel, resize: buildResizePanel, adjust: buildAdjustPanel, filters: buildFiltersPanel, annotate: buildAnnotatePanel, watermark: buildWatermarkPanel };
			ui.tabs = {};
			ui.panels = {};
			var tabBar = h('div', { class: 'ie-tabs', role: 'tablist' });
			var panelWrap = h('div', { class: 'ie-panels' });
			tabDefs.forEach(function (d) {
				var b = h('button', { type: 'button', class: 'ie-tabbtn', role: 'tab', title: t(d[2]), html: ico(d[1], 18) + '<span>' + escapeText(t(d[2])) + '</span>' });
				b.addEventListener('click', function () { setTab(d[0]); });
				ui.tabs[d[0]] = b;
				tabBar.appendChild(b);
				var p = h('div', { class: 'ie-panel', role: 'tabpanel' }, [builders[d[0]]()]);
				p.hidden = true;
				ui.panels[d[0]] = p;
				panelWrap.appendChild(p);
			});

			ui.undo = btn('undo', t('ie_undo'), undo, 'ie-icon');
			ui.redo = btn('redo', t('ie_redo'), redo, 'ie-icon');
			ui.reset = btn('reset', t('ie_reset'), resetAll, 'ie-icon');
			var cmp = btn('eye', t('ie_compare'), function () {}, 'ie-icon');
			function cmpOn(e) { e.preventDefault(); st.comparing = true; draw(); }
			function cmpOff() { if (st.comparing) { st.comparing = false; draw(); } }
			cmp.addEventListener('pointerdown', cmpOn);
			cmp.addEventListener('pointerup', cmpOff);
			cmp.addEventListener('pointerleave', cmpOff);
			cmp.addEventListener('pointercancel', cmpOff);

			var header = h('div', { class: 'ie-header' }, [
				h('strong', { class: 'ie-title', text: t('ie_title') + ' · ' + name }),
				h('div', { class: 'ie-hist' }, [ui.undo, ui.redo, ui.reset, cmp]),
				h('div', { class: 'ie-header-actions' }, [
					h('button', { type: 'button', class: 'ie-btn', text: t('ie_cancel'), onclick: function () { close(false); } }),
					h('button', { type: 'button', class: 'ie-btn ie-primary', text: t('ie_save_btn'), onclick: openSave })
				])
			]);

			st.view = h('canvas', { class: 'ie-canvas' });
			st.stage = h('div', { class: 'ie-stage' }, [st.view]);
			st.view.addEventListener('pointerdown', onDown);
			st.view.addEventListener('pointermove', onMove);
			st.view.addEventListener('pointerup', onUp);
			st.view.addEventListener('pointercancel', onUp);

			var side = h('div', { class: 'ie-side' }, [tabBar, panelWrap]);
			ui.root = h('div', { class: 'ie-overlay', role: 'dialog', 'aria-modal': 'true', 'aria-label': t('ie_title') }, [header, h('div', { class: 'ie-body' }, [st.stage, side])]);
		}

		buildRoot();
		document.body.appendChild(ui.root);
		document.documentElement.classList.add('ie-lock');
		document.addEventListener('keydown', onKey, true);
		window.addEventListener('resize', layout);
		if (window.ResizeObserver) {
			ro = new ResizeObserver(function () { layout(); });
			ro.observe(st.stage);
		}
		push();
		initCrop();
		setTab('crop');
		layout();
	}

	function isEditable(name) {
		return EXT.indexOf((name.split('.').pop() || '').toLowerCase()) >= 0;
	}

	function itemUrl(item) {
		var img = item.querySelector('.file-thumbnail img');
		if (img) return img.src;
		var a = item.querySelector('.copy-url-btn');
		return a ? a.getAttribute('data-url') : '';
	}

	function decorate() {
		document.querySelectorAll('.file-item').forEach(function (item) {
			var name = item.getAttribute('data-name') || '';
			if (item.getAttribute('data-filetype') !== 'image' || !isEditable(name)) return;
			var actions = item.querySelector('.file-actions');
			if (!actions || actions.querySelector('.ie-edit-btn')) return;
			var a = h('a', { href: '#', class: 'ie-edit-btn fm-icon-btn', title: t('ie_edit'), 'aria-label': t('ie_edit'), html: ico('edit', 14) });
			a.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				openEditor(name, itemUrl(item));
			});
			actions.insertBefore(a, actions.firstChild);
		});

		var menu = document.querySelector('#context-menu ul');
		if (menu && !menu.querySelector('[data-ie]')) {
			var li = h('li', { class: 'file-only', 'data-ie': '1', 'data-action': 'ie-edit', html: '<span class="action-icon">' + ico('edit', 14) + '</span> ' + escapeText(t('ie_edit')) });
			li.addEventListener('click', function () {
				var sel = document.querySelectorAll('.file-item.selected');
				if (sel.length !== 1) return;
				var nm = sel[0].getAttribute('data-name') || '';
				if (isEditable(nm)) openEditor(nm, itemUrl(sel[0]));
			});
			menu.insertBefore(li, menu.firstChild);
			var grid = document.querySelector('.file-grid');
			if (grid) {
				grid.addEventListener('contextmenu', function () {
					setTimeout(function () {
						var sel = document.querySelectorAll('.file-item.selected');
						var ok = sel.length === 1 && sel[0].getAttribute('data-filetype') === 'image' && isEditable(sel[0].getAttribute('data-name') || '');
						li.style.display = ok ? '' : 'none';
					}, 0);
				});
			}
		}
	}

	window.SynaptikImageEditor = { open: openEditor };
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', decorate);
	else decorate();
})();
