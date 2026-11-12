(function () {
    'use strict';

    var cfg = JSON.parse(document.getElementById('vpb-config').textContent);
    var S = cfg.strings || {};
    var app = document.getElementById('vpb-app');
    var state = {
        blocks: normalizeBlocks(cfg.layout.blocks),
        lang: cfg.lang,
        sel: null,
        tab: 'content',
        dirty: false,
        hasLayout: cfg.hasLayout,
        rev: cfg.rev || '',
        device: 'desktop',
        token: '',
        saving: false
    };
    var els = {};
    var uid = 0;

    function plainObject(value) {
        return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    }

    function normalizeBlocks(blocks) {
        (blocks || []).forEach(function (block) {
            block.st = plainObject(block.st);
            block.v = plainObject(block.v);
            Object.keys(block.v).forEach(function (key) {
                var value = block.v[key];
                if (Array.isArray(value) && value.length && value[0] && typeof value[0] === 'object' && value[0].type !== undefined) normalizeBlocks(value);
            });
        });
        return blocks || [];
    }

    var picker = null;
    var drag = null;
    var previewTimer = 0;
    var lastDraft = '';
    var previewBusy = false;
    var previewAgain = false;
    var scrollY = 0;
    var spanLabels = {'12': '1/1', '9': '3/4', '8': '2/3', '6': '1/2', '4': '1/3', '3': '1/4'};
    var groupOrder = ['basic', 'hero', 'content', 'cards', 'media', 'layout', 'conversion'];

    function t(key, fallback) {
        return S[key] !== undefined ? S[key] : (fallback !== undefined ? fallback : key);
    }

    function el(tag, props, kids) {
        var node = document.createElement(tag);
        props = props || {};
        Object.keys(props).forEach(function (name) {
            var value = props[name];
            if (name === 'class') node.className = value;
            else if (name === 'text') node.textContent = value;
            else if (name.indexOf('on') === 0) node.addEventListener(name.slice(2), value);
            else if (value !== false && value !== null && value !== undefined) node.setAttribute(name, value === true ? '' : value);
        });
        (kids || []).forEach(function (kid) {
            if (kid) node.appendChild(typeof kid === 'string' ? document.createTextNode(kid) : kid);
        });
        return node;
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function newId() {
        var out = '';
        for (var i = 0; i < 8; i++) out += Math.floor(Math.random() * 16).toString(16);
        return out;
    }

    function emptyValue(def) {
        if (def.t === 'text' || def.t === 'textarea' || def.t === 'list') {
            var map = {};
            cfg.langs.forEach(function (lang) { map[lang] = def.t === 'list' ? [] : ''; });
            return map;
        }
        if (def.t === 'items' || def.t === 'blocks') return [];
        return '';
    }

    function emptyFields(defs) {
        var out = {};
        defs.forEach(function (def) { out[def.k] = emptyValue(def); });
        return out;
    }

    function typeLabel(type) {
        var def = cfg.types[type];
        return def && def.label ? def.label : t('vpb_t_' + type, type);
    }

    function typeDesc(type) {
        var def = cfg.types[type];
        return def && def.desc ? def.desc : t('vpb_d_' + type, '');
    }

    function blocksFieldOf(block) {
        var def = cfg.types[block.type];
        if (!def) return null;
        for (var i = 0; i < def.fields.length; i++) {
            if (def.fields[i].t === 'blocks') return def.fields[i];
        }
        return null;
    }

    function current() {
        var sel = state.sel;
        if (!sel || !state.blocks[sel.i]) return null;
        var block = state.blocks[sel.i];
        if (sel.j === undefined) return {block: block, row: null, fields: cfg.types[block.type].fields, sub: false};
        var field = blocksFieldOf(block);
        var row = field && block.v[field.k] ? block.v[field.k][sel.j] : null;
        if (!row) return null;
        return {block: block, row: row, field: field, fields: field.types[row.type] || [], sub: true};
    }

    function summaryOf(values, fields) {
        var keys = ['title', 'eyebrow', 'label', 'q', 'text', 'body'];
        for (var i = 0; i < keys.length; i++) {
            var value = values && values[keys[i]];
            if (value && typeof value === 'object' && !Array.isArray(value)) {
                var text = value[state.lang] || value[cfg.langs[0]];
                if (text) return text;
            }
        }
        return '';
    }

    function setStatus(text, error) {
        if (!els.status) return;
        els.status.textContent = text;
        els.status.classList.toggle('is-error', !!error);
    }

    var hist = {stack: [], idx: -1, timer: 0};

    function histCommit() {
        window.clearTimeout(hist.timer);
        hist.timer = 0;
        var json = JSON.stringify(state.blocks);
        if (hist.idx >= 0 && hist.stack[hist.idx] === json) {
            renderHistoryState();
            return;
        }
        hist.stack.splice(hist.idx + 1);
        hist.stack.push(json);
        if (hist.stack.length > 100) hist.stack.shift();
        hist.idx = hist.stack.length - 1;
        renderHistoryState();
    }

    function histSchedule() {
        window.clearTimeout(hist.timer);
        hist.timer = window.setTimeout(histCommit, 500);
        renderHistoryState();
    }

    function renderHistoryState() {
        if (!els.undo) return;
        els.undo.disabled = hist.idx <= 0 && !hist.timer;
        els.redo.disabled = hist.idx >= hist.stack.length - 1;
    }

    function histApply() {
        state.blocks = normalizeBlocks(JSON.parse(hist.stack[hist.idx]));
        if (!current()) state.sel = state.sel && state.blocks[state.sel.i] ? {i: state.sel.i} : null;
        state.dirty = true;
        setStatus(t('vpb_unsaved'));
        renderStructure();
        renderInspector();
        renderHistoryState();
        schedulePreview();
    }

    function undo() {
        if (hist.timer) histCommit();
        if (hist.idx <= 0) return;
        hist.idx--;
        histApply();
    }

    function redo() {
        if (hist.timer) histCommit();
        if (hist.idx >= hist.stack.length - 1) return;
        hist.idx++;
        histApply();
    }

    function modalOpen() {
        var modal = document.getElementById('global-modal');
        return !!modal && modal.style.display !== 'none';
    }

    function onKey(event) {
        var key = String(event.key || '').toLowerCase();
        var mod = event.ctrlKey || event.metaKey;
        var node = event.target;
        var typing = !!node && (node.tagName === 'INPUT' || node.tagName === 'TEXTAREA' || node.tagName === 'SELECT' || node.isContentEditable);
        if (mod && key === 's') {
            event.preventDefault();
            save();
            return;
        }
        if (typing || modalOpen()) return;
        if (mod && key === 'z') {
            event.preventDefault();
            if (event.shiftKey) redo(); else undo();
        } else if (mod && key === 'y') {
            event.preventDefault();
            redo();
        } else if (mod && key === 'd' && state.sel) {
            event.preventDefault();
            duplicateSel();
        } else if (!mod && (key === 'delete' || key === 'backspace') && state.sel) {
            event.preventDefault();
            deleteSel();
        }
    }

    function changed() {
        state.dirty = true;
        setStatus(t('vpb_unsaved'));
        histSchedule();
        renderStructure();
        schedulePreview();
    }

    function refresh() {
        state.dirty = true;
        setStatus(t('vpb_unsaved'));
        histSchedule();
        renderStructure();
        renderInspector();
        schedulePreview();
    }

    function post(action, extra) {
        var body = new FormData();
        body.append('action', action);
        body.append('key', cfg.key);
        body.append('_csrf', cfg.csrf);
        Object.keys(extra || {}).forEach(function (name) { body.append(name, extra[name]); });
        return fetch(cfg.actionsUrl, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (response) {
            return response.json().catch(function () { return {ok: false, error: 'invalid'}; });
        });
    }

    function failMessage(res) {
        return res && (res.error === 'auth' || res.error === 'csrf') ? t('vpb_session_error') : t('vpb_save_error');
    }

    function schedulePreview(immediate) {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(runPreview, immediate ? 0 : 650);
    }

    function runPreview() {
        if (previewBusy) {
            previewAgain = true;
            return;
        }
        var json = JSON.stringify({blocks: state.blocks});
        if (json === lastDraft && state.token) return;
        previewBusy = true;
        post('save_draft', {layout: json}).then(function (res) {
            previewBusy = false;
            if (!res.ok) {
                setStatus(failMessage(res), true);
                return;
            }
            lastDraft = json;
            state.token = res.token;
            loadFrame();
            if (previewAgain) {
                previewAgain = false;
                schedulePreview();
            }
        }).catch(function () {
            previewBusy = false;
            setStatus(t('vpb_save_error'), true);
        });
    }

    function loadFrame() {
        if (!els.frame || !state.token) return;
        try {
            scrollY = els.frame.contentWindow.scrollY || 0;
        } catch (error) {
            scrollY = 0;
        }
        var url = cfg.previewUrl + (cfg.previewUrl.indexOf('?') > -1 ? '&' : '?') + 'vpb_preview=' + encodeURIComponent(state.token) + '&_=' + Date.now();
        els.frame.src = url;
    }

    function parsePath(value) {
        var parts = String(value || '').split(':');
        var sel = {i: parseInt(parts[0], 10)};
        if (isNaN(sel.i)) return null;
        if (parts.length === 3) sel.j = parseInt(parts[2], 10);
        return sel;
    }

    function nodeRect(node, doc) {
        var win = doc.defaultView;
        var rect = node.getBoundingClientRect();
        if (rect.width || rect.height) return rect;
        var top = Infinity, left = Infinity, right = -Infinity, bottom = -Infinity;
        Array.prototype.forEach.call(node.children, function (child) {
            var r = nodeRect(child, doc);
            if (!r.width && !r.height) return;
            top = Math.min(top, r.top); left = Math.min(left, r.left);
            right = Math.max(right, r.right); bottom = Math.max(bottom, r.bottom);
        });
        if (top === Infinity) return {top: 0, left: 0, width: 0, height: 0};
        return {top: top, left: left, width: right - left, height: bottom - top, right: right, bottom: bottom};
    }

    function pathSelector(sel) {
        if (!sel) return '';
        return sel.j === undefined ? '[data-vpb-path="' + sel.i + '"]' : '[data-vpb-path="' + sel.i + ':' + (blocksFieldOf(state.blocks[sel.i]) || {k: 'blocks'}).k + ':' + sel.j + '"]';
    }

    function placeOverlay(box, node, doc) {
        if (!node) {
            box.style.display = 'none';
            return;
        }
        var rect = nodeRect(node, doc);
        var win = doc.defaultView;
        box.style.display = 'block';
        box.style.top = (rect.top + win.scrollY) + 'px';
        box.style.left = (rect.left + win.scrollX) + 'px';
        box.style.width = rect.width + 'px';
        box.style.height = rect.height + 'px';
    }

    function updateOverlays() {
        var doc = frameDoc();
        if (!doc || !doc.__vpb) return;
        var selector = pathSelector(state.sel);
        var node = selector ? doc.querySelector(selector) : null;
        placeOverlay(doc.__vpb.selected, node, doc);
        placeBar(doc, node);
    }

    function selLabel() {
        var cur = current();
        if (!cur) return '';
        return cur.sub ? t('vpb_b_' + cur.row.type, cur.row.type) : typeLabel(cur.block.type);
    }

    function placeBar(doc, node) {
        var ui = doc.__vpb.bar;
        if (!node) {
            ui.style.display = 'none';
            return;
        }
        var rect = nodeRect(node, doc);
        var win = doc.defaultView;
        ui.__label.textContent = selLabel();
        ui.__grip.style.display = state.sel && state.sel.j === undefined ? 'block' : 'none';
        ui.style.display = 'flex';
        var top = rect.top + win.scrollY - ui.offsetHeight - 4;
        if (top < win.scrollY + 2) top = rect.top + win.scrollY + 6;
        var left = rect.left + win.scrollX + (rect.width - ui.offsetWidth) / 2;
        ui.style.top = top + 'px';
        ui.style.left = Math.max(win.scrollX + 4, left) + 'px';
    }

    function makeBar(doc) {
        var ui = doc.createElement('div');
        ui.setAttribute('data-vpb-ui', '');
        ui.style.cssText = 'position:absolute;z-index:2147483001;display:none;align-items:center;gap:2px;padding:3px;background:#41924e;color:#fff;border-radius:7px;box-shadow:0 4px 14px rgba(0,0,0,.3);font:600 12px system-ui,sans-serif;line-height:1';
        var grip = doc.createElement('button');
        grip.type = 'button';
        grip.textContent = '\u283f';
        grip.title = t('vpb_drag');
        grip.setAttribute('aria-label', t('vpb_drag'));
        grip.style.cssText = 'width:26px;height:26px;border:0;border-radius:5px;background:rgba(255,255,255,.18);color:#fff;font-size:16px;cursor:grab;padding:0;touch-action:none';
        grip.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 || !state.sel || state.sel.j !== undefined) return;
            event.preventDefault();
            event.stopPropagation();
            startFrameDrag(doc, grip, event);
        });
        ui.appendChild(grip);
        var label = doc.createElement('span');
        label.style.cssText = 'padding:0 8px 0 6px;white-space:nowrap';
        ui.appendChild(label);
        ui.__grip = grip;
        ui.__label = label;
        [['\u2191', 'vpb_move_up', function () { moveSel(-1); }], ['\u2193', 'vpb_move_down', function () { moveSel(1); }], ['\u29c9', 'vpb_duplicate', duplicateSel], ['\u2715', 'vpb_delete', deleteSel]].forEach(function (action) {
            var button = doc.createElement('button');
            button.type = 'button';
            button.textContent = action[0];
            button.title = t(action[1]);
            button.setAttribute('aria-label', t(action[1]));
            button.style.cssText = 'width:26px;height:26px;border:0;border-radius:5px;background:transparent;color:#fff;font-size:14px;cursor:pointer;padding:0';
            button.addEventListener('click', function (event) { event.stopPropagation(); action[2](); });
            ui.appendChild(button);
        });
        doc.body.appendChild(ui);
        return ui;
    }

    function startFrameDrag(doc, handle, event) {
        var win = doc.defaultView;
        var from = state.sel.i;
        var nodes = Array.prototype.filter.call(doc.querySelectorAll('[data-vpb-path]'), function (node) { return node.getAttribute('data-vpb-path').indexOf(':') < 0; });
        var line = doc.createElement('div');
        line.setAttribute('data-vpb-ui', '');
        line.style.cssText = 'position:absolute;left:0;width:100%;height:4px;margin-top:-2px;background:#4fa75c;border-radius:2px;z-index:2147483003;pointer-events:none;box-shadow:0 0 0 2px rgba(79,167,92,.3)';
        doc.body.appendChild(line);
        doc.__vpb.hover.style.display = 'none';
        doc.__vpb.adder.style.display = 'none';
        var oldSelect = doc.body.style.userSelect;
        var oldCursor = doc.body.style.cursor;
        doc.body.style.userSelect = 'none';
        doc.body.style.cursor = 'grabbing';
        var slot = from;
        var pointerY = event.clientY;
        function locate() {
            var y = pointerY + win.scrollY;
            var found = state.blocks.length;
            var edge = null;
            for (var n = 0; n < nodes.length; n++) {
                var rect = nodeRect(nodes[n], doc);
                if (y < rect.top + win.scrollY + rect.height / 2) {
                    found = parseInt(nodes[n].getAttribute('data-vpb-path'), 10);
                    edge = rect.top + win.scrollY;
                    break;
                }
                edge = rect.top + win.scrollY + rect.height;
            }
            slot = found;
            line.style.top = (edge === null ? 0 : edge) + 'px';
        }
        var scroller = window.setInterval(function () {
            if (pointerY < 70) win.scrollBy(0, -20);
            else if (pointerY > win.innerHeight - 70) win.scrollBy(0, 20);
            else return;
            locate();
        }, 30);
        function onMove(e) {
            pointerY = e.clientY;
            locate();
        }
        function finish(apply) {
            window.clearInterval(scroller);
            handle.removeEventListener('pointermove', onMove);
            handle.removeEventListener('pointerup', onUp);
            handle.removeEventListener('pointercancel', onCancel);
            try { handle.releasePointerCapture(event.pointerId); } catch (error) {}
            line.remove();
            doc.body.style.userSelect = oldSelect;
            doc.body.style.cursor = oldCursor;
            if (!apply) return;
            var target = slot > from ? slot - 1 : slot;
            if (target === from) {
                updateOverlays();
                return;
            }
            swap(state.blocks, from, target, function () { state.sel = {i: target}; });
        }
        function onUp() { finish(true); }
        function onCancel() { finish(false); }
        handle.setPointerCapture(event.pointerId);
        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', onUp);
        handle.addEventListener('pointercancel', onCancel);
        locate();
    }

    function makeAdder(doc) {
        var button = doc.createElement('button');
        button.type = 'button';
        button.setAttribute('data-vpb-ui', '');
        button.textContent = '+';
        button.title = t('vpb_add_below');
        button.setAttribute('aria-label', t('vpb_add_below'));
        button.style.cssText = 'position:absolute;z-index:2147483002;display:none;width:30px;height:30px;border:0;border-radius:50%;background:#41924e;color:#fff;font:400 22px/30px system-ui,sans-serif;padding:0;cursor:pointer;box-shadow:0 3px 10px rgba(0,0,0,.35)';
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            if (button.__at !== undefined) openPalette(button.__at);
        });
        doc.body.appendChild(button);
        return button;
    }

    function placeAdder(doc, node) {
        var button = doc.__vpb.adder;
        var path = node ? node.getAttribute('data-vpb-path') : '';
        if (!node || path.indexOf(':') > -1) {
            button.style.display = 'none';
            return;
        }
        var rect = nodeRect(node, doc);
        var win = doc.defaultView;
        button.__at = parseInt(path, 10) + 1;
        button.style.display = 'block';
        button.style.top = (rect.top + win.scrollY + rect.height - 15) + 'px';
        button.style.left = (rect.left + win.scrollX + rect.width / 2 - 15) + 'px';
    }

    function frameDoc() {
        try {
            return els.frame && els.frame.contentDocument && els.frame.contentDocument.body ? els.frame.contentDocument : null;
        } catch (error) {
            return null;
        }
    }

    function makeOverlay(doc, color, fill) {
        var box = doc.createElement('div');
        box.style.cssText = 'position:absolute;z-index:2147483000;pointer-events:none;display:none;box-sizing:border-box;border:2px solid ' + color + ';background:' + fill + ';border-radius:4px;';
        doc.body.appendChild(box);
        return box;
    }

    function onFrameLoad() {
        var doc = frameDoc();
        if (!doc) return;
        var win = doc.defaultView;
        var style = doc.createElement('style');
        style.textContent = '[data-vpb-path]{cursor:pointer}';
        doc.head.appendChild(style);
        doc.__vpb = {hover: makeOverlay(doc, '#4fa75c', 'rgba(79,167,92,.08)'), selected: makeOverlay(doc, '#4fa75c', 'rgba(79,167,92,.14)'), bar: makeBar(doc), adder: makeAdder(doc)};
        doc.addEventListener('click', function (event) {
            if (event.target.closest && event.target.closest('[data-vpb-ui]')) return;
            var target = event.target.closest ? event.target.closest('[data-vpb-path]') : null;
            event.preventDefault();
            event.stopPropagation();
            var sel = target ? parsePath(target.getAttribute('data-vpb-path')) : null;
            if (sel && state.blocks[sel.i]) select(sel, true);
        }, true);
        doc.addEventListener('submit', function (event) { event.preventDefault(); }, true);
        doc.addEventListener('keydown', onKey);
        doc.addEventListener('mouseover', function (event) {
            if (event.target.closest && event.target.closest('[data-vpb-ui]')) return;
            var target = event.target.closest ? event.target.closest('[data-vpb-path]') : null;
            placeOverlay(doc.__vpb.hover, target, doc);
            placeAdder(doc, target ? target.closest('[data-vpb-path]:not([data-vpb-path*=":"])') : null);
        });
        doc.addEventListener('mouseleave', function () { doc.__vpb.hover.style.display = 'none'; });
        win.addEventListener('resize', updateOverlays);
        win.scrollTo(0, scrollY);
        window.setTimeout(updateOverlays, 60);
        window.setTimeout(updateOverlays, 500);
        if (state.reveal) {
            state.reveal = false;
            window.setTimeout(function () { scrollFrameTo(state.sel); }, 120);
        }
    }

    function select(sel, fromFrame) {
        state.sel = sel;
        if (sel && els.panelR && !els.panelR.open) setPanel(els.panelR, true, true);
        renderStructure();
        renderInspector();
        updateOverlays();
        if (!fromFrame) scrollFrameTo(sel);
    }

    function scrollFrameTo(sel) {
        var doc = frameDoc();
        var selector = pathSelector(sel);
        var node = doc && selector ? doc.querySelector(selector) : null;
        if (!node) return;
        var rect = nodeRect(node, doc);
        var view = doc.defaultView;
        if (rect.top < 0 || rect.top > view.innerHeight - 80) view.scrollTo({top: Math.max(0, rect.top + view.scrollY - 90), behavior: 'smooth'});
        window.setTimeout(updateOverlays, 350);
    }

    function optionLabel(def, value, label) {
        return t('vpb_o_' + def.k + '_' + value, label);
    }

    function swap(list, from, to, afterMove) {
        if (to < 0 || to >= list.length || from === to) return;
        var moved = list.splice(from, 1)[0];
        list.splice(to, 0, moved);
        if (afterMove) afterMove(from, to);
        refresh();
    }

    function iconButton(labelKey, glyph, handler, extra) {
        return el('button', {type: 'button', class: 'vpb-icon' + (extra ? ' ' + extra : ''), title: t(labelKey), 'aria-label': t(labelKey), onclick: function (event) { event.stopPropagation(); handler(event); }, text: glyph});
    }

    function makeSortable(card, handle, list, index, afterMove) {
        handle.addEventListener('mousedown', function () { card.draggable = true; });
        handle.addEventListener('mouseup', function () { card.draggable = false; });
        card.addEventListener('dragstart', function (event) {
            if (!card.draggable) return;
            event.stopPropagation();
            drag = {list: list, index: index};
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(index));
            card.classList.add('is-dragging');
        });
        card.addEventListener('dragend', function () {
            card.draggable = false;
            card.classList.remove('is-dragging');
            drag = null;
            Array.prototype.forEach.call(app.querySelectorAll('.is-over'), function (node) { node.classList.remove('is-over'); });
        });
        card.addEventListener('dragover', function (event) {
            if (!drag || drag.list !== list) return;
            event.preventDefault();
            event.stopPropagation();
            card.classList.add('is-over');
        });
        card.addEventListener('dragleave', function () { card.classList.remove('is-over'); });
        card.addEventListener('drop', function (event) {
            if (!drag || drag.list !== list) return;
            event.preventDefault();
            event.stopPropagation();
            var from = drag.index;
            drag = null;
            swap(list, from, index, afterMove);
        });
    }

    var ICONS = {
        left: '<path d="M4 6h16M4 12h10M4 18h14"/>',
        center: '<path d="M4 6h16M7 12h10M5 18h14"/>',
        right: '<path d="M4 6h16M10 12h10M6 18h14"/>',
        top: '<path d="M4 4h16"/><rect x="8" y="8" width="8" height="11" rx="1"/>',
        middle: '<path d="M4 12h16"/><rect x="8" y="6" width="8" height="12" rx="1"/>',
        bottom: '<path d="M4 20h16"/><rect x="8" y="5" width="8" height="11" rx="1"/>',
        desktop: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        mobile: '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>',
        image: '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-8 8"/>',
        folder: '<path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        upload: '<path d="M12 16V4m0 0-4 4m4-4 4 4M4 20h16"/>',
        panelL: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
        panelR: '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/>',
        search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        undo: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
        back: '<path d="M19 12H5m7-7-7 7 7 7"/>',
        history: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        preview: '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        reset: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        redo: '<path d="m15 14 5-5-5-5"/><path d="M20 9H10a6 6 0 0 0 0 12h3"/>'
    };

    function icon(name) {
        var node = el('span', {class: 'vpb-ico'});
        node.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
        return node;
    }


    var cardRow = function (n, y, h) {
        var out = [], w = Math.floor((56 - (n - 1) * 4) / n);
        for (var k = 0; k < n; k++) {
            var x = 4 + k * (w + 4);
            out.push(['c', x, y, w, h], ['o', x + 5, y + 6, 2.5], ['h', x + 3, y + 12, w - 6], ['l', x + 3, y + 18, w - 6]);
        }
        return out;
    };
    var splitWire = function (rev, list) {
        var ix = rev ? 36 : 4, tx = rev ? 4 : 34;
        var out = [['i', ix, 7, 24, 26], ['h', tx, 9, 24]];
        if (list) out.push(['o', tx + 2, 18, 1.8], ['l', tx + 6, 17, 18], ['o', tx + 2, 24, 1.8], ['l', tx + 6, 23, 16]);
        else out.push(['l', tx, 16, 26], ['l', tx, 21, 20], ['b', tx, 27, 11]);
        return out;
    };
    var planWire = function () {
        return [['c', 4, 10, 16, 24], ['c', 24, 6, 16, 30], ['c', 44, 10, 16, 24], ['h', 7, 14, 10], ['h', 27, 11, 10], ['h', 47, 14, 10], ['b', 7, 28, 10], ['b', 27, 29, 10], ['b', 47, 28, 10]];
    };
    var stepWire = function () {
        return [['o', 12, 12, 4], ['o', 32, 12, 4], ['o', 52, 12, 4], ['h', 6, 22, 12], ['h', 26, 22, 12], ['h', 46, 22, 12], ['l', 6, 28, 12], ['l', 26, 28, 12], ['l', 46, 28, 12]];
    };
    var tableWire = function () {
        var out = [['c', 4, 6, 56, 28], ['h', 8, 9, 14]];
        for (var r = 0; r < 4; r++) out.push(['l', 8, 17 + r * 4.5, 16], ['o', 38, 18 + r * 4.5, 1.2], ['o', 48, 18 + r * 4.5, 1.2], ['o', 56, 18 + r * 4.5, 1.2]);
        return out;
    };
    var accordion = function () {
        var out = [['h', 4, 4, 24]];
        for (var r = 0; r < 4; r++) out.push(['c', 4, 11 + r * 7.5, 56, 6], ['l', 8, 13.5 + r * 7.5, 22]);
        return out;
    };
    var WIRES = {
        page_hero: [['h', 18, 8, 28], ['l', 10, 16, 44], ['l', 14, 21, 36], ['b', 26, 27, 12]],
        hero: [['h', 4, 9, 30], ['l', 4, 16, 26], ['l', 4, 21, 22], ['b', 4, 27, 12], ['i', 38, 6, 22, 28]],
        metrics: [['h', 4, 12, 9], ['l', 4, 20, 9], ['h', 20, 12, 9], ['l', 20, 20, 9], ['h', 36, 12, 9], ['l', 36, 20, 9], ['h', 52, 12, 9], ['l', 52, 20, 9]],
        split: splitWire(false, false),
        split_classic: splitWire(false, false),
        module_section: splitWire(false, true),
        module_extra: splitWire(true, true),
        cards: cardRow(3, 8, 24),
        module_related: cardRow(3, 8, 24),
        module_family: [['h', 4, 5, 20], ['c', 4, 12, 26, 11], ['c', 34, 12, 26, 11], ['c', 4, 26, 26, 11], ['c', 34, 26, 26, 11]],
        faq: [['c', 4, 5, 56, 7], ['c', 4, 14, 56, 7], ['c', 4, 23, 56, 7], ['c', 4, 32, 56, 7], ['l', 8, 7.5, 24], ['l', 8, 16.5, 28], ['l', 8, 25.5, 20], ['l', 8, 34.5, 26]],
        text: [['h', 4, 8, 26], ['l', 4, 16, 54], ['l', 4, 21, 50], ['l', 4, 26, 54], ['l', 4, 31, 30]],
        page_body: [['l', 4, 8, 54], ['l', 4, 13, 50], ['l', 4, 18, 54], ['l', 4, 23, 46], ['l', 4, 28, 52], ['l', 4, 33, 30]],
        tabs: [['b', 4, 6, 14], ['c', 22, 6, 14, 5], ['c', 40, 6, 14, 5], ['c', 4, 15, 56, 20], ['l', 9, 21, 30], ['l', 9, 26, 42]],
        steps: stepWire(),
        pricing_trial: stepWire(),
        grid: [['c', 4, 5, 26, 14], ['c', 34, 5, 26, 14], ['c', 4, 23, 56, 12], ['l', 8, 10, 14], ['l', 38, 10, 14], ['l', 8, 28, 30]],
        spacer: [['l', 31, 5, 2], ['l', 31, 33, 2], ['c', 20, 14, 24, 12]],
        callout: [['c', 4, 8, 56, 24], ['b', 4, 8, 3], ['o', 14, 20, 3], ['h', 22, 14, 24], ['l', 22, 21, 30], ['l', 22, 26, 22]],
        checklist: [['o', 8, 9, 2], ['l', 14, 8, 40], ['o', 8, 17, 2], ['l', 14, 16, 34], ['o', 8, 25, 2], ['l', 14, 24, 42], ['o', 8, 33, 2], ['l', 14, 32, 28]],
        compare: tableWire(),
        pricing_compare: tableWire(),
        plans: planWire(),
        pricing_plans: planWire(),
        testimonials: [['c', 4, 6, 56, 28], ['l', 10, 12, 44], ['l', 10, 17, 38], ['o', 13, 27, 3], ['h', 20, 25, 16]],
        banner: [['i', 4, 7, 56, 26], ['h', 14, 14, 36], ['b', 24, 23, 16]],
        anchors: [['b', 4, 16, 12], ['c', 20, 16, 12, 5], ['c', 36, 16, 12, 5], ['c', 52, 16, 8, 5]],
        pillar: [['c', 10, 5, 44, 30], ['o', 32, 13, 3.5], ['h', 18, 21, 28], ['l', 18, 27, 28]],
        nolist: [['c', 8, 6, 48, 28], ['h', 14, 12, 28], ['l', 14, 19, 36], ['l', 14, 24, 30]],
        levels: [['b', 4, 7, 56], ['c', 4, 15, 46, 5], ['c', 4, 23, 34, 5], ['c', 4, 31, 22, 5]],
        visual_gallery: [['i', 4, 5, 17, 13], ['i', 23, 5, 17, 13], ['i', 42, 5, 17, 13], ['i', 4, 21, 17, 13], ['i', 23, 21, 17, 13], ['i', 42, 21, 17, 13]],
        module_crumb: [['l', 4, 17, 10], ['l', 18, 17, 4], ['l', 26, 17, 14]],
        module_details: accordion(),
        pricing_offers: [['c', 4, 6, 56, 11], ['b', 8, 9, 8], ['l', 20, 10, 26], ['c', 4, 21, 56, 11], ['b', 8, 24, 8], ['l', 20, 25, 22]],
        pricing_payment: [['c', 4, 8, 26, 24], ['c', 34, 8, 26, 24], ['h', 8, 12, 14], ['h', 38, 12, 14], ['l', 8, 19, 16], ['l', 8, 24, 12], ['l', 38, 19, 16], ['l', 38, 24, 12]],
        cta: [['c', 4, 8, 56, 24], ['h', 16, 13, 32], ['l', 20, 20, 24], ['b', 25, 25, 14]],
        columns: [['h', 4, 7, 20], ['l', 4, 16, 26], ['l', 4, 21, 22], ['l', 4, 26, 24], ['l', 34, 16, 26], ['l', 34, 21, 22], ['l', 34, 26, 24]],
        image: [['i', 4, 5, 56, 30], ['o', 20, 16, 3], ['l', 28, 28, 20]],
        video: [['i', 4, 5, 56, 30], ['o', 32, 20, 6], ['l', 29, 19, 6]],
        quote: [['b', 4, 8, 3], ['l', 12, 12, 44], ['l', 12, 17, 38], ['h', 12, 26, 16]],
        buttons: [['b', 8, 17, 22], ['c', 34, 17, 22, 5]],
        divider: [['l', 4, 19, 56]]
    };
    WIRES.intro = WIRES.page_hero;
    WIRES.media_text = WIRES.split;
    WIRES.features = WIRES.cards;
    WIRES.stats = WIRES.metrics;
    WIRES.process = WIRES.steps;
    WIRES.accordion = WIRES.faq;
    WIRES.testimonial = WIRES.testimonials;
    WIRES.pricing_table = WIRES.plans;
    WIRES.notice = WIRES.callout;
    WIRES.cta_box = WIRES.cta;
    WIRES.gallery = WIRES.visual_gallery;
    WIRES.logos = [['i', 4, 14, 12, 12], ['i', 20, 14, 12, 12], ['i', 36, 14, 12, 12], ['i', 52, 14, 8, 12]];
    WIRES.team = [['o', 14, 14, 5], ['o', 32, 14, 5], ['o', 50, 14, 5], ['l', 8, 24, 12], ['l', 26, 24, 12], ['l', 44, 24, 12]];
    WIRES.data_table = WIRES.compare;
    WIRES.map = WIRES.banner;
    var WIRE_GROUP = {basic: 'text', hero: 'hero', content: 'text', cards: 'cards', media: 'banner', layout: 'grid', conversion: 'cta'};

    function wireframe(type, group) {
        var shapes = WIRES[type] || WIRES[WIRE_GROUP[group]] || WIRES.text;
        var svg = '<svg viewBox="0 0 64 40" aria-hidden="true">';
        shapes.forEach(function (s) {
            if (s[0] === 'h') svg += '<rect x="' + s[1] + '" y="' + s[2] + '" width="' + s[3] + '" height="3" rx="1.5" fill="currentColor"/>';
            else if (s[0] === 'l') svg += '<rect x="' + s[1] + '" y="' + s[2] + '" width="' + s[3] + '" height="2" rx="1" fill="currentColor" opacity=".4"/>';
            else if (s[0] === 'b') svg += '<rect x="' + s[1] + '" y="' + s[2] + '" width="' + s[3] + '" height="' + (s[3] <= 4 ? 24 : 5) + '" rx="2" fill="var(--primary, #2563eb)"/>';
            else if (s[0] === 'i') svg += '<rect x="' + s[1] + '" y="' + s[2] + '" width="' + s[3] + '" height="' + s[4] + '" rx="2" fill="currentColor" opacity=".18"/>';
            else if (s[0] === 'c') svg += '<rect x="' + s[1] + '" y="' + s[2] + '" width="' + s[3] + '" height="' + s[4] + '" rx="2" fill="none" stroke="currentColor" stroke-opacity=".5" stroke-width="1"/>';
            else svg += '<circle cx="' + s[1] + '" cy="' + s[2] + '" r="' + s[3] + '" fill="var(--primary, #2563eb)" opacity=".85"/>';
        });
        var node = el('div', {class: 'vpb-wire'});
        node.innerHTML = svg + '</svg>';
        return node;
    }

    function labelled(labelKey, control, extra) {
        var wrap = el('div', {class: 'vpb-field' + (extra ? ' ' + extra : '')});
        wrap.appendChild(el('label', {text: t(labelKey)}));
        wrap.appendChild(control);
        return wrap;
    }

    function segField(obj, key, labelKey, options) {
        var group = el('div', {class: 'vpb-seg', role: 'group', 'aria-label': t(labelKey)});
        function paint() {
            Array.prototype.forEach.call(group.children, function (node) {
                var on = node.getAttribute('data-v') === (obj[key] || '');
                node.classList.toggle('is-on', on);
                node.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }
        options.forEach(function (option) {
            var button = el('button', {type: 'button', class: 'vpb-seg-btn', 'data-v': option.v, title: t(option.label), 'aria-label': t(option.label), onclick: function () {
                obj[key] = option.v;
                paint();
                changed();
            }}, [icon(option.icon)]);
            group.appendChild(button);
        });
        paint();
        return labelled(labelKey, group);
    }

    function rangeField(obj, key, labelKey, min, max, def, unit) {
        unit = unit || 'px';
        var has = obj[key] !== undefined && obj[key] !== '';
        var input = el('input', {type: 'range', min: min, max: max, step: 1, 'aria-label': t(labelKey)});
        input.value = has ? obj[key] : def;
        var out = el('output', {text: has ? obj[key] + ' ' + unit : t('vpb_auto')});
        var auto = el('button', {type: 'button', class: 'btn btn-outline btn-sm', text: t('vpb_auto'), onclick: function () {
            delete obj[key];
            input.value = def;
            out.textContent = t('vpb_auto');
            box.classList.add('is-auto');
            changed();
        }});
        var box = el('div', {class: 'vpb-range' + (has ? '' : ' is-auto')}, [input, out, auto]);
        input.addEventListener('input', function () {
            obj[key] = parseInt(input.value, 10);
            out.textContent = input.value + ' ' + unit;
            box.classList.remove('is-auto');
            changed();
        });
        return labelled(labelKey, box);
    }

    function colorField(obj, key, labelKey) {
        var picker = el('input', {type: 'color', value: obj[key] || '#ffffff', 'aria-label': t(labelKey)});
        var box = el('div', {class: 'vpb-color' + (obj[key] ? ' is-set' : '')});
        function set(value) {
            if (value) obj[key] = value; else delete obj[key];
            picker.value = value || '#ffffff';
            box.classList.toggle('is-set', !!value);
            changed();
        }
        picker.addEventListener('input', function () { set(picker.value.toLowerCase()); });
        box.appendChild(picker);
        box.appendChild(el('button', {type: 'button', class: 'btn btn-outline btn-sm', title: t('vpb_auto'), 'aria-label': t('vpb_auto'), text: '\u2715', onclick: function () { set(''); }}));
        return labelled(labelKey, box);
    }

    function imageUrl(value) {
        if (!value) return '';
        return /^https?:\/\//i.test(value) ? value : (cfg.siteBase || '') + value.replace(/^\/+/, '');
    }

    function openMediaPicker(onPick) {
        if (!picker) {
            picker = window.SynaptikImagePicker.create({
                title: t('vpb_media_title'),
                getFilesUrl: cfg.mediaUrl,
                baseUrl: cfg.siteBase,
                i18n: {home: t('vpb_media_root'), loading: t('vpb_loading'), noImages: t('vpb_media_empty'), loadError: t('vpb_media_error')}
            });
        }
        picker.open(onPick);
    }

    function imageField(def, obj, label) {
        var wrap = el('div', {class: 'vpb-field vpb-field--image'});
        var thumb = el('div', {class: 'vpb-thumb'});
        var url = el('input', {type: 'text', value: obj[def.k] || '', maxlength: 300, placeholder: t('vpb_media_url')});
        function paint() {
            thumb.textContent = '';
            var src = imageUrl(obj[def.k]);
            if (src) thumb.appendChild(el('img', {src: src, alt: ''})); else thumb.appendChild(icon('image'));
            url.value = obj[def.k] || '';
        }
        function set(value) { obj[def.k] = value; paint(); changed(); }
        url.addEventListener('input', function () { obj[def.k] = url.value.trim(); thumb.textContent = ''; var src = imageUrl(obj[def.k]); if (src) thumb.appendChild(el('img', {src: src, alt: ''})); changed(); });
        wrap.appendChild(el('label', {text: label}));
        wrap.appendChild(el('div', {class: 'vpb-imgrow'}, [
            thumb,
            el('div', {class: 'vpb-imgbtns'}, [
                el('button', {type: 'button', class: 'btn btn-outline btn-sm', text: t('vpb_media_choose'), onclick: function () { openMediaPicker(set); }}),
                el('button', {type: 'button', class: 'btn btn-outline btn-sm', text: t('vpb_clear'), onclick: function () { set(''); }})
            ])
        ]));
        wrap.appendChild(url);
        paint();
        return wrap;
    }

    function mdWrap(input, before, after, placeholder) {
        var s = input.selectionStart, e = input.selectionEnd, v = input.value;
        var sel = v.slice(s, e) || placeholder;
        input.value = v.slice(0, s) + before + sel + after + v.slice(e);
        input.selectionStart = s + before.length;
        input.selectionEnd = s + before.length + sel.length;
        input.focus();
        input.dispatchEvent(new Event('input'));
    }

    function mdLines(input, prefix) {
        var v = input.value;
        var a = v.lastIndexOf('\n', input.selectionStart - 1) + 1;
        var b = v.indexOf('\n', input.selectionEnd);
        if (b < 0) b = v.length;
        var block = v.slice(a, b).split('\n').map(function (line) { return prefix + line; }).join('\n');
        input.value = v.slice(0, a) + block + v.slice(b);
        input.selectionStart = a;
        input.selectionEnd = a + block.length;
        input.focus();
        input.dispatchEvent(new Event('input'));
    }

    function mdToolbar(input) {
        var actions = [
            ['vpb_md_bold', '<b>B</b>', function () { mdWrap(input, '**', '**', t('vpb_md_bold')); }],
            ['vpb_md_italic', '<i>I</i>', function () { mdWrap(input, '*', '*', t('vpb_md_italic')); }],
            ['vpb_md_heading', 'H', function () { mdLines(input, '### '); }],
            ['vpb_md_list', '\u2022', function () { mdLines(input, '- '); }],
            ['vpb_md_quote', '\u201d', function () { mdLines(input, '> '); }],
            ['vpb_md_link', '\u2197', function () { mdWrap(input, '[', '](https://)', t('vpb_md_link')); }]
        ];
        var bar = el('div', {class: 'vpb-md', role: 'toolbar', 'aria-label': t('vpb_md_label')});
        actions.forEach(function (action) {
            var button = el('button', {type: 'button', class: 'vpb-md-btn', title: t(action[0]), 'aria-label': t(action[0]), onclick: action[2]});
            button.innerHTML = action[1];
            bar.appendChild(button);
        });
        return bar;
    }

    function renderField(def, obj) {
        var label = t('vpb_f_' + def.k, def.k);
        var wrap = el('div', {class: 'vpb-field vpb-field--' + def.t});
        var input;

        if (def.t === 'text' || def.t === 'textarea') {
            obj[def.k] = obj[def.k] || emptyValue(def);
            var value = obj[def.k][state.lang] || '';
            input = def.t === 'text' ? el('input', {type: 'text', value: value, maxlength: 300}) : el("textarea", {rows: 3});
            if (def.t === 'textarea') input.value = value;
            input.addEventListener('input', function () {
                obj[def.k][state.lang] = input.value;
                changed();
            });
            wrap.appendChild(el('label', {}, [label, el('span', {class: 'vpb-lang', text: state.lang.toUpperCase()})]));
            if (def.md) wrap.appendChild(mdToolbar(input));
            wrap.appendChild(input);
        } else if (def.t === 'list') {
            obj[def.k] = obj[def.k] || emptyValue(def);
            input = el("textarea", {rows: 3});
            input.value = (obj[def.k][state.lang] || []).join('\n');
            input.addEventListener('input', function () {
                obj[def.k][state.lang] = input.value.split('\n').map(function (row) { return row.trim(); }).filter(Boolean);
                changed();
            });
            wrap.appendChild(el('label', {}, [label, el('span', {class: 'vpb-lang', text: state.lang.toUpperCase()}), el('span', {class: 'vpb-note', text: t('vpb_one_per_line')})]));
            wrap.appendChild(input);
        } else if (def.t === 'select') {
            input = el('select');
            input.appendChild(el('option', {value: '', text: def.k === 'bg' ? t('vpb_o_bg_') : t('vpb_none')}));
            Object.keys(def.o || {}).forEach(function (key) {
                input.appendChild(el('option', {value: key, text: optionLabel(def, key, def.o[key])}));
            });
            input.value = obj[def.k] || '';
            input.addEventListener('change', function () {
                obj[def.k] = input.value;
                changed();
            });
            wrap.appendChild(el('label', {text: label}));
            wrap.appendChild(input);
        } else if (def.t === 'check') {
            input = el('input', {type: 'checkbox'});
            input.checked = !!obj[def.k];
            input.addEventListener('change', function () {
                obj[def.k] = input.checked ? '1' : '';
                changed();
            });
            wrap.appendChild(el('label', {class: 'vpb-check'}, [input, label]));
        } else if (def.t === 'color') {
            return colorField(obj, def.k, def.k === 'bg' ? 'vpb_f_bgcolor' : 'vpb_f_' + def.k);
        } else if (def.t === 'image') {
            return imageField(def, obj, label);
        } else if (def.t === 'video') {
            input = el('input', {type: 'text', value: obj[def.k] || '', maxlength: 500, placeholder: t('vpb_ph_video')});
            var hint = el('small', {class: 'vpb-hint'});
            var detect = function () {
                var v = input.value;
                var kind = /youtu/i.test(v) ? 'YouTube' : /vimeo/i.test(v) ? 'Vimeo' : /dailymotion|dai\.ly/i.test(v) ? 'Dailymotion' : /\.(mp4|webm|ogg)(\?|$)/i.test(v) ? t('vpb_video_file') : '';
                hint.textContent = kind ? '\u2713 ' + kind : t('vpb_video_hint');
            };
            input.addEventListener('input', function () {
                var v = input.value.trim();
                var m = v.match(/<iframe[^>]+src=["']([^"']+)["']/i);
                if (m) {
                    v = m[1];
                    input.value = v;
                }
                obj[def.k] = v;
                detect();
                changed();
            });
            detect();
            wrap.appendChild(el('label', {text: t(def.k === 'embed' ? 'vpb_f_embed' : 'vpb_f_video')}));
            wrap.appendChild(input);
            wrap.appendChild(hint);
        } else if (def.t === 'link' || def.t === 'slug' || def.t === 'shortcode') {
            var listId = 'vpb-links-' + (++uid);
            input = el('input', {type: 'text', value: obj[def.k] || '', maxlength: def.t === 'shortcode' ? 500 : 300, list: def.t === 'link' ? listId : false, placeholder: def.t === 'shortcode' ? '[newsletter_signup]' : false});
            input.addEventListener('input', function () {
                obj[def.k] = input.value.trim();
                changed();
            });
            wrap.appendChild(el('label', {text: label}));
            wrap.appendChild(input);
            if (def.t === 'link') {
                var list = el('datalist', {id: listId});
                Object.keys(cfg.links || {}).forEach(function (key) {
                    list.appendChild(el('option', {value: key, label: cfg.links[key]}));
                });
                wrap.appendChild(list);
            }
        } else if (def.t === 'items') {
            obj[def.k] = obj[def.k] || [];
            wrap.appendChild(el('label', {text: label}));
            wrap.appendChild(renderItems(def, obj[def.k]));
        }
        return wrap;
    }

    function renderItems(def, list) {
        var box = el('div', {class: 'vpb-items'});
        list.forEach(function (item, index) {
            var grip = el('span', {class: 'vpb-grip', title: t('vpb_drag'), 'aria-hidden': 'true', text: '⠿'});
            var card = el('div', {class: 'vpb-item'});
            card.appendChild(el('div', {class: 'vpb-item-head'}, [
                grip,
                el('strong', {text: '#' + (index + 1)}),
                el('span', {class: 'vpb-grow'}),
                iconButton('vpb_move_up', '↑', function () { swap(list, index, index - 1); }),
                iconButton('vpb_move_down', '↓', function () { swap(list, index, index + 1); }),
                iconButton('vpb_delete', '✕', function () {
                    list.splice(index, 1);
                    refresh();
                }, 'vpb-icon--danger')
            ]));
            makeSortable(card, grip, list, index);
            def.f.forEach(function (sub) {
                if (item[sub.k] === undefined) item[sub.k] = emptyValue(sub);
                card.appendChild(renderField(sub, item));
            });
            box.appendChild(card);
        });
        box.appendChild(el('button', {type: 'button', class: 'btn btn-outline btn-sm', text: '+ ' + t('vpb_add_item'), onclick: function () {
            list.push(emptyFields(def.f));
            refresh();
        }}));
        return box;
    }

    function presetRow(target) {
        var row = el('div', {class: 'vpb-presets'});
        Object.keys(cfg.presets || {}).forEach(function (name) {
            var preset = cfg.presets[name];
            row.appendChild(el('button', {type: 'button', class: 'vpb-preset', title: t('vpb_preset_' + name, name), 'aria-label': t('vpb_preset_' + name, name), style: 'background:' + (preset.bg || '#fff') + ';color:' + (preset.color || '#000'), onclick: function () {
                ['bg', 'color', 'hcolor'].forEach(function (k) { if (preset[k]) target.st[k] = preset[k]; else delete target.st[k]; });
                renderInspector();
                changed();
            }}, [el('span', {text: 'Aa'}), el('em', {text: t('vpb_preset_' + name, name)})]));
        });
        row.appendChild(el('button', {type: 'button', class: 'vpb-preset vpb-preset--none', title: t('vpb_preset_none'), onclick: function () {
            ['bg', 'color', 'hcolor'].forEach(function (k) { delete target.st[k]; });
            renderInspector();
            changed();
        }}, [el('span', {text: '∅'}), el('em', {text: t('vpb_preset_none')})]));
        return labelled('vpb_f_preset', row);
    }

    function fieldsHaveText(fields) {
        return (fields || []).some(function (def) {
            if (def.t === 'text' || def.t === 'textarea' || def.t === 'list') return true;
            if (def.t === 'items') return fieldsHaveText(def.f);
            if (def.t === 'blocks') return Object.keys(def.types || {}).some(function (name) { return fieldsHaveText(def.types[name]); });
            return false;
        });
    }

    function fieldsHaveImage(fields) {
        return (fields || []).some(function (def) {
            if (def.t === 'image') return true;
            if (def.t === 'items') return fieldsHaveImage(def.f);
            if (def.t === 'blocks') return Object.keys(def.types || {}).some(function (name) { return fieldsHaveImage(def.types[name]); });
            return false;
        });
    }

    function renderStyleTab(cur) {
        var box = el('div', {class: 'vpb-fields vpb-fields--grid'});
        var target = cur.sub ? cur.row : cur.block;
        target.st = plainObject(target.st);
        var st = target.st;
        var aligns = [{v: '', icon: 'left', label: 'vpb_o_align_'}, {v: 'center', icon: 'center', label: 'vpb_o_align_center'}, {v: 'right', icon: 'right', label: 'vpb_o_align_right'}];
        var sizes = function () { return [['', t('vpb_o_default')], ['s', t('vpb_o_size_s')], ['l', t('vpb_o_size_l')], ['xl', t('vpb_o_size_xl')]]; };
        var pads = [['', t('vpb_o_default')], ['none', t('vpb_o_pad_none')], ['s', t('vpb_o_pad_s')], ['m', t('vpb_o_pad_m')], ['l', t('vpb_o_pad_l')], ['xl', t('vpb_o_pad_xl')]];
        var wide = function (node) { node.classList.add('vpb-wide'); return node; };
        var heading = function (key) { return wide(el('h4', {class: 'vpb-sub-title', text: t(key)})); };
        box.appendChild(heading('vpb_s_look'));
        box.appendChild(wide(presetRow(target)));
        if (!cur.sub && cfg.types[cur.block.type].bg && Object.keys(cfg.bgs || {}).length) {
            box.appendChild(wide(plainSelect(cur.block, 'bg', 'vpb_f_bgpreset', [['', t('vpb_o_bg_')]].concat(Object.keys(cfg.bgs).map(function (key) { return [key, t('vpb_o_bg_' + key, cfg.bgs[key])]; })))));
        }
        var hasText = fieldsHaveText(cur.fields);
        var hasButtons = cur.sub ? (cur.field.btn || []).indexOf(cur.row.type) > -1 : !!cfg.types[cur.block.type].btn;
        box.appendChild(heading('vpb_s_colors'));
        box.appendChild(colorField(st, 'bg', 'vpb_f_bgcolor'));
        if (hasText) {
            box.appendChild(colorField(st, 'color', 'vpb_f_color'));
            box.appendChild(colorField(st, 'hcolor', 'vpb_f_hcolor'));
            if (!cur.sub) box.appendChild(colorField(st, 'tcolor', 'vpb_f_tcolor'));
        }
        if (hasText || !cur.sub) {
            box.appendChild(heading('vpb_s_text'));
            box.appendChild(segField(st, 'talign', 'vpb_f_talign', aligns));
        }
        if (hasText) {
            box.appendChild(plainSelect(st, 'size', 'vpb_f_size', sizes()));
            box.appendChild(plainSelect(st, 'hsz', 'vpb_f_hsz', sizes()));
            if (!cur.sub) box.appendChild(wide(rangeField(st, 'tsz', 'vpb_f_tsz', 12, 96, 32, true)));
        }
        box.appendChild(heading('vpb_s_space'));
        if (cur.sub) {
            var select = el('select');
            cur.field.spans.forEach(function (span) { select.appendChild(el('option', {value: span, text: spanLabels[span] || span})); });
            select.value = cur.row.span || cur.field.spans[0];
            select.addEventListener('change', function () { cur.row.span = select.value; changed(); });
            box.appendChild(labelled('vpb_f_span', select));
            box.appendChild(plainSelect(st, 'pad', 'vpb_f_pad', pads));
            box.appendChild(segField(cur.row, 'align', 'vpb_f_align', aligns));
            box.appendChild(segField(cur.row, 'valign', 'vpb_f_valign', [{v: '', icon: 'top', label: 'vpb_o_valign_'}, {v: 'center', icon: 'middle', label: 'vpb_o_valign_center'}, {v: 'end', icon: 'bottom', label: 'vpb_o_valign_end'}]));
        } else {
            box.appendChild(plainSelect(st, 'pad', 'vpb_f_pad', pads));
            box.appendChild(plainSelect(st, 'width', 'vpb_f_width', [['', t('vpb_o_default')], ['narrow', t('vpb_o_width_narrow')], ['wide', t('vpb_o_width_wide')], ['full', t('vpb_o_width_full')]]));
            box.appendChild(wide(rangeField(st, 'bw', 'vpb_f_bw', 30, 100, 90, '%')));
        }
        box.appendChild(wide(rangeField(st, 'rpx', 'vpb_f_rad', 0, 80, 12)));
        if (fieldsHaveImage(cur.fields)) {
            box.appendChild(heading('vpb_s_images'));
            box.appendChild(plainSelect(st, 'imgasp', 'vpb_f_imgasp', [['', t('vpb_o_imgasp_')], ['1/1', '1:1'], ['4/3', '4:3'], ['3/2', '3:2'], ['16/9', '16:9'], ['21/9', '21:9'], ['3/4', '3:4']]));
            box.appendChild(plainSelect(st, 'imgfit', 'vpb_f_imgfit', [['', t('vpb_o_imgfit_')], ['contain', t('vpb_o_imgfit_contain')]]));
            box.appendChild(plainSelect(st, 'imgpos', 'vpb_f_imgpos', [['', t('vpb_o_imgpos_')], ['top', t('vpb_o_imgpos_top')], ['bottom', t('vpb_o_imgpos_bottom')]]));
            box.appendChild(plainSelect(st, 'imgsh', 'vpb_f_imgsh', [['', t('vpb_o_imgsh_')], ['s', t('vpb_o_imgsh_s')], ['m', t('vpb_o_imgsh_m')], ['l', t('vpb_o_imgsh_l')]]));
            box.appendChild(segField(st, 'imgal', 'vpb_f_imgal', aligns));
            box.appendChild(wide(rangeField(st, 'imgr', 'vpb_f_imgr', 0, 100, 12)));
            box.appendChild(wide(rangeField(st, 'imgw', 'vpb_f_imgw', 20, 100, 100, '%')));
        }
        if (hasButtons) {
            box.appendChild(heading('vpb_s_buttons'));
            box.appendChild(colorField(st, 'btnbg', 'vpb_f_btnbg'));
            box.appendChild(colorField(st, 'btncolor', 'vpb_f_btncolor'));
            box.appendChild(plainSelect(st, 'btnsz', 'vpb_f_btnsz', sizes()));
            box.appendChild(wide(rangeField(st, 'btnr', 'vpb_f_btnr', 0, 60, 8)));
        }
        return box;
    }

    function plainSelect(obj, key, labelKey, options) {
        var wrap = el('div', {class: 'vpb-field'});
        var select = el('select');
        options.forEach(function (option) { select.appendChild(el('option', {value: option[0], text: option[1]})); });
        select.value = obj[key] || '';
        select.addEventListener('change', function () { obj[key] = select.value; changed(); });
        wrap.appendChild(el('label', {text: t(labelKey)}));
        wrap.appendChild(select);
        return wrap;
    }

    function renderBlockList(block, field) {
        var list = block.v[field.k] = block.v[field.k] || [];
        var box = el('div', {class: 'vpb-field'});
        box.appendChild(el('label', {text: t('vpb_f_' + field.k, field.k)}));
        var rows = el('div', {class: 'vpb-blist'});
        list.forEach(function (row, j) {
            var active = state.sel && state.sel.i === state.blocks.indexOf(block) && state.sel.j === j;
            rows.appendChild(el('button', {type: 'button', class: 'vpb-brow' + (active ? ' is-on' : ''), onclick: function () { select({i: state.blocks.indexOf(block), j: j}); }}, [
                el('strong', {text: t('vpb_b_' + row.type, row.type)}),
                el('span', {class: 'vpb-summary', text: summaryOf(row.v)})
            ]));
        });
        box.appendChild(rows);
        var select = el('select', {'aria-label': t('vpb_choose_type')});
        Object.keys(field.types).forEach(function (name) { select.appendChild(el('option', {value: name, text: t('vpb_b_' + name, name)})); });
        box.appendChild(el('div', {class: 'vpb-adder'}, [select, el('button', {type: 'button', class: 'btn btn-outline btn-sm', text: '+ ' + t('vpb_add_block'), onclick: function () {
            list.push({type: select.value, span: field.spans[0], align: '', valign: '', st: {}, v: emptyFields(field.types[select.value])});
            state.sel = {i: state.blocks.indexOf(block), j: list.length - 1};
            refresh();
        }})]));
        return box;
    }

    function renderInspector() {
        var box = els.inspector;
        box.textContent = '';
        var cur = current();
        if (!cur) {
            box.appendChild(el('p', {class: 'vpb-empty', text: t('vpb_select_hint')}));
            return;
        }
        var label = cur.sub ? t('vpb_b_' + cur.row.type, cur.row.type) : typeLabel(cur.block.type);
        var list = cur.sub ? cur.block.v[cur.field.k] : state.blocks;
        var index = cur.sub ? state.sel.j : state.sel.i;
        box.appendChild(el('div', {class: 'vpb-ins-head'}, [
            cur.sub ? el('button', {type: 'button', class: 'btn btn-outline btn-sm', text: '↑ ' + typeLabel(cur.block.type), onclick: function () { select({i: state.sel.i}); }}) : null,
            el('strong', {text: label}),
            el('span', {class: 'vpb-grow'}),
            iconButton('vpb_move_up', '↑', function () { moveSel(-1); }),
            iconButton('vpb_move_down', '↓', function () { moveSel(1); }),
            iconButton('vpb_duplicate', '⧉', duplicateSel),
            iconButton('vpb_delete', '✕', deleteSel, 'vpb-icon--danger')
        ]));
        var tabs = el('div', {class: 'vpb-tabs2', role: 'tablist'});
        ['content', 'style'].forEach(function (name) {
            tabs.appendChild(el('button', {type: 'button', role: 'tab', class: 'vpb-tab2' + (state.tab === name ? ' is-on' : ''), 'aria-selected': state.tab === name ? 'true' : 'false', text: t('vpb_tab_' + name), onclick: function () { state.tab = name; renderInspector(); }}));
        });
        box.appendChild(tabs);
        if (state.tab === 'style') {
            box.appendChild(renderStyleTab(cur));
            return;
        }
        var body = el('div', {class: 'vpb-fields'});
        var values = cur.sub ? cur.row.v : cur.block.v;
        cur.fields.forEach(function (def) {
            if (values[def.k] === undefined) values[def.k] = emptyValue(def);
            if (def.t === 'blocks') {
                body.appendChild(renderBlockList(cur.block, def));
            } else {
                body.appendChild(renderField(def, values));
            }
        });
        if (!cur.fields.length) body.appendChild(el('p', {class: 'vpb-empty', text: t('vpb_no_fields')}));
        box.appendChild(body);
    }

    function selCtx() {
        var cur = current();
        if (!cur) return null;
        return {cur: cur, list: cur.sub ? cur.block.v[cur.field.k] : state.blocks, index: cur.sub ? state.sel.j : state.sel.i};
    }

    function moveSel(delta) {
        var c = selCtx();
        if (c) move(c.list, c.index, c.index + delta, c.cur.sub);
    }

    function duplicateSel() {
        var c = selCtx();
        if (!c) return;
        c.list.splice(c.index + 1, 0, c.cur.sub ? clone(c.cur.row) : Object.assign(clone(c.cur.block), {id: newId()}));
        state.sel = c.cur.sub ? {i: state.sel.i, j: c.index + 1} : {i: c.index + 1};
        refresh();
    }

    function deleteSel() {
        var c = selCtx();
        if (!c) return;
        var remove = function () {
            c.list.splice(c.index, 1);
            state.sel = c.cur.sub ? {i: state.sel.i} : null;
            refresh();
        };
        if (c.cur.sub) remove(); else confirmThen(t('vpb_confirm_delete'), remove);
    }

    function move(list, from, to, sub) {
        if (to < 0 || to >= list.length) return;
        swap(list, from, to, function () {
            state.sel = sub ? {i: state.sel.i, j: to} : {i: to};
        });
    }

    function renderStructure() {
        var box = els.structure;
        box.textContent = '';
        if (!state.blocks.length) box.appendChild(el('p', {class: 'vpb-empty', text: t('vpb_empty_state')}));
        state.blocks.forEach(function (block, index) {
            var def = cfg.types[block.type];
            if (!def) return;
            var on = state.sel && state.sel.i === index && state.sel.j === undefined;
            var grip = el('span', {class: 'vpb-grip', title: t('vpb_drag'), 'aria-hidden': 'true', text: '⠿'});
            var row = el('div', {class: 'vpb-srow' + (on ? ' is-on' : ''), onclick: function () { select({i: index}); }}, [
                grip,
                el('strong', {text: typeLabel(block.type)}),
                el('span', {class: 'vpb-summary', text: summaryOf(block.v)})
            ]);
            makeSortable(row, grip, state.blocks, index, function (from, to) {
                if (state.sel && state.sel.j === undefined) state.sel = {i: to};
            });
            box.appendChild(row);
            var field = blocksFieldOf(block);
            if (field && block.v[field.k]) {
                block.v[field.k].forEach(function (sub, j) {
                    var subOn = state.sel && state.sel.i === index && state.sel.j === j;
                    var subGrip = el('span', {class: 'vpb-grip', title: t('vpb_drag'), 'aria-hidden': 'true', text: '⠿'});
                    var subRow = el('div', {class: 'vpb-srow vpb-srow--sub' + (subOn ? ' is-on' : ''), onclick: function () { select({i: index, j: j}); }}, [
                        subGrip,
                        el('span', {text: t('vpb_b_' + sub.type, sub.type)}),
                        el('span', {class: 'vpb-summary', text: summaryOf(sub.v)})
                    ]);
                    makeSortable(subRow, subGrip, block.v[field.k], j, function (from, to) {
                        if (state.sel && state.sel.j !== undefined) state.sel = {i: index, j: to};
                    });
                    box.appendChild(subRow);
                });
            }
        });
        box.appendChild(el('button', {type: 'button', class: 'btn btn-primary btn-block vpb-add', text: '+ ' + t('vpb_add_section'), onclick: function () { openPalette(); }}));
    }

    function openPalette(at) {
        var overlay = el('div', {class: 'vpb-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': t('vpb_palette_title')});
        var close = function () { overlay.remove(); };
        overlay.addEventListener('click', function (event) { if (event.target === overlay) close(); });
        var panel = el('div', {class: 'vpb-palette'});
        panel.appendChild(el('div', {class: 'vpb-palette-head'}, [el('strong', {text: t('vpb_palette_title')}), el('span', {class: 'vpb-grow'}), iconButton('vpb_close', '✕', close)]));
        var search = el('input', {type: 'search', class: 'vpb-search', placeholder: t('vpb_palette_search'), 'aria-label': t('vpb_palette_search')});
        panel.appendChild(search);
        var sections = [];
        var groups = {};
        Object.keys(cfg.types).forEach(function (name) {
            var group = cfg.types[name].group;
            (groups[group] = groups[group] || []).push(name);
        });
        Object.keys(groups).sort(function (a, b) {
            var x = groupOrder.indexOf(a), y = groupOrder.indexOf(b);
            return (x < 0 ? 99 : x) - (y < 0 ? 99 : y);
        }).forEach(function (group) {
            var title = el('h4', {text: t('vpb_g_' + group, group)});
            var grid = el('div', {class: 'vpb-palette-grid'});
            var picks = [];
            groups[group].forEach(function (name) {
                var pick = el('button', {type: 'button', class: 'vpb-pick', onclick: function () {
                    close();
                    addSection(name, at);
                }}, [wireframe(name, cfg.types[name].group), el('strong', {text: typeLabel(name)}), el('span', {text: typeDesc(name)})]);
                picks.push({node: pick, text: (typeLabel(name) + ' ' + typeDesc(name) + ' ' + name).toLowerCase()});
                grid.appendChild(pick);
            });
            sections.push({title: title, grid: grid, picks: picks});
            panel.appendChild(title);
            panel.appendChild(grid);
        });
        var none = el('p', {class: 'vpb-empty', hidden: true, text: t('vpb_palette_none')});
        panel.appendChild(none);
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            var any = false;
            sections.forEach(function (section) {
                var shown = 0;
                section.picks.forEach(function (pick) {
                    var on = q === '' || pick.text.indexOf(q) > -1;
                    pick.node.hidden = !on;
                    if (on) shown++;
                });
                section.title.hidden = section.grid.hidden = shown === 0;
                if (shown) any = true;
            });
            none.hidden = any;
        });
        overlay.appendChild(panel);
        document.body.appendChild(overlay);
        search.focus();
        overlay.addEventListener('keydown', function (event) { if (event.key === 'Escape') close(); });
    }

    function addSection(type, position) {
        var def = cfg.types[type];
        var block = {id: newId(), type: type, bg: '', st: {}, v: emptyFields(def.fields)};
        var at = typeof position === 'number' ? position : (state.sel ? state.sel.i + 1 : state.blocks.length);
        state.blocks.splice(at, 0, block);
        state.sel = {i: at};
        state.tab = 'content';
        state.reveal = true;
        refresh();
    }

    function save(force) {
        if (state.saving) return;
        state.saving = true;
        setStatus(t('vpb_saving'));
        post('save_layout', {layout: JSON.stringify({blocks: state.blocks}), rev: state.rev, force: force === true ? '1' : ''}).then(function (res) {
            state.saving = false;
            if (!res.ok) {
                if (res.error === 'conflict') {
                    setStatus(t('vpb_conflict_status'), true);
                    window.showModal(t('vpb_conflict'), window.t('confirm'), {showCancel: true, confirmText: t('vpb_overwrite'), cancelText: window.t('cancel'), danger: true, onConfirm: function () { save(true); }});
                    return;
                }
                setStatus(failMessage(res), true);
                return;
            }
            state.rev = res.rev || '';
            lastDraft = '';
            state.blocks = normalizeBlocks(res.layout.blocks);
            state.hasLayout = res.hasLayout;
            state.dirty = false;
            histCommit();
            if (state.sel && !state.blocks[state.sel.i]) state.sel = null;
            renderStructure();
            renderInspector();
            renderToolbarState();
            setStatus(t('vpb_saved'));
            schedulePreview(true);
        }).catch(function () {
            state.saving = false;
            setStatus(t('vpb_save_error'), true);
        });
    }

    function confirmThen(message, action) {
        window.showModal(message, window.t('confirm_deletion'), {showCancel: true, confirmText: window.t('delete'), cancelText: window.t('cancel'), danger: true, onConfirm: action});
    }

    function reset() {
        confirmThen(t('vpb_confirm_reset'), doReset);
    }

    function doReset() {
        post('reset_layout').then(function (res) {
            if (!res.ok) {
                setStatus(failMessage(res), true);
                return;
            }
            state.blocks = [];
            state.hasLayout = false;
            state.rev = '';
            lastDraft = '';
            state.dirty = false;
            histCommit();
            state.sel = null;
            renderStructure();
            renderInspector();
            renderToolbarState();
            setStatus(t('vpb_reset_done'));
            schedulePreview(true);
        });
    }

    function openVersions() {
        post('list_versions').then(function (res) {
            if (!res.ok) {
                setStatus(failMessage(res), true);
                return;
            }
            var list = el('div', {class: 'vpb-versions'});
            if (!res.versions.length) list.appendChild(el('p', {class: 'vpb-empty', text: t('vpb_versions_empty')}));
            res.versions.forEach(function (version) {
                var when = new Date(version.time * 1000).toLocaleString();
                list.appendChild(el('div', {class: 'vpb-version'}, [
                    el('span', {text: when + ' · ' + t('vpb_version_sections').replace('%d', version.blocks)}),
                    el('button', {type: 'button', class: 'btn btn-outline btn-sm', text: t('vpb_version_restore'), onclick: function () { restoreVersion(version.id); }})
                ]));
            });
            window.showModal('', t('vpb_versions'), {confirmText: t('vpb_close'), onOpen: function (modal) { modal.querySelector('#modal-message').appendChild(list); }});
        }).catch(function () { setStatus(t('vpb_save_error'), true); });
    }

    function restoreVersion(id) {
        post('get_version', {id: id}).then(function (res) {
            if (!res.ok) {
                setStatus(failMessage(res), true);
                return;
            }
            window.closeModal();
            state.blocks = normalizeBlocks(res.layout.blocks);
            state.sel = null;
            refresh();
            histCommit();
        }).catch(function () { setStatus(t('vpb_save_error'), true); });
    }

    function renderToolbarState() {
        els.reset.hidden = !state.hasLayout;
        els.versionsBtn.hidden = !state.hasLayout;
    }

    function openPreviewTab() {
        var tab = window.open('', '_blank');
        if (tab) tab.document.title = t('vpb_preview');
        post('save_draft', {layout: JSON.stringify({blocks: state.blocks})}).then(function (res) {
            if (!res.ok) {
                if (tab) tab.close();
                setStatus(failMessage(res), true);
                return;
            }
            state.token = res.token;
            var url = cfg.previewUrl + (cfg.previewUrl.indexOf('?') > -1 ? '&' : '?') + 'vpb_preview=' + encodeURIComponent(res.token) + '&vpb_view=1&_=' + Date.now();
            if (tab) tab.location.href = url; else window.location.href = url;
        }).catch(function () {
            if (tab) tab.close();
            setStatus(t('vpb_save_error'), true);
        });
    }

    function setDevice(device) {
        state.device = device;
        els.stage.classList.toggle('is-mobile', device === 'mobile');
        Array.prototype.forEach.call(els.devices.children, function (button) {
            button.classList.toggle('is-on', button.getAttribute('data-device') === device);
        });
        window.setTimeout(updateOverlays, 250);
    }

    var MIN_W = 240;
    var MIN_H = 130;
    var zTop = 10;

    function readStore(name, fallback) {
        try {
            var v = window.localStorage.getItem('vpb_' + name);
            return v === null ? fallback : v;
        } catch (error) {
            return fallback;
        }
    }

    function writeStore(name, value) {
        try { window.localStorage.setItem('vpb_' + name, String(value)); } catch (error) {}
    }

    function saveWin(p) {
        writeStore('win_' + p.side, JSON.stringify({x: p.x, y: p.y, w: p.w, h: p.h, open: p.open, min: p.min}));
    }

    function clampWin(p) {
        var W = els.main.clientWidth, H = els.main.clientHeight;
        p.w = Math.max(MIN_W, Math.min(p.w, W));
        p.h = Math.max(MIN_H, Math.min(p.h, H));
        p.x = Math.max(0, Math.min(p.x, W - p.w));
        p.y = Math.max(0, Math.min(p.y, H - (p.min ? 40 : p.h)));
    }

    function applyWin(p) {
        clampWin(p);
        var style = p.node.style;
        style.left = p.x + 'px';
        style.top = p.y + 'px';
        style.width = p.w + 'px';
        style.height = p.min ? 'auto' : p.h + 'px';
        p.node.hidden = !p.open;
        p.node.classList.toggle('is-min', p.min);
        p.toggle.classList.toggle('is-on', p.open);
        p.toggle.setAttribute('aria-pressed', p.open ? 'true' : 'false');
        p.shade.textContent = p.min ? '□' : '−';
    }

    function front(p) {
        p.node.style.zIndex = String(++zTop);
    }

    function setPanel(p, open, keep) {
        p.open = open;
        if (open) front(p);
        applyWin(p);
        if (!keep) saveWin(p);
    }

    function trackPointer(event, onMove, onEnd) {
        var handle = event.currentTarget;
        event.preventDefault();
        handle.setPointerCapture(event.pointerId);
        app.classList.add('is-resizing');
        function stop() {
            handle.removeEventListener('pointermove', onMove);
            handle.removeEventListener('pointerup', stop);
            handle.removeEventListener('pointercancel', stop);
            app.classList.remove('is-resizing');
            onEnd();
        }
        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', stop);
        handle.addEventListener('pointercancel', stop);
    }

    function startDrag(p, event) {
        if (event.target.closest && event.target.closest('button')) return;
        front(p);
        var sx = event.clientX, sy = event.clientY, ox = p.x, oy = p.y;
        trackPointer(event, function (e) {
            p.x = ox + e.clientX - sx;
            p.y = oy + e.clientY - sy;
            applyWin(p);
        }, function () { saveWin(p); });
    }

    function startResize(p, dir, event) {
        front(p);
        var sx = event.clientX, sy = event.clientY, ow = p.w, oh = p.h, ox = p.x;
        var W = els.main.clientWidth, H = els.main.clientHeight;
        trackPointer(event, function (e) {
            var dx = e.clientX - sx, dy = e.clientY - sy;
            if (dir.indexOf('e') > -1) p.w = Math.max(MIN_W, Math.min(ow + dx, W - ox));
            if (dir.indexOf('w') > -1) {
                p.w = Math.max(MIN_W, Math.min(ow - dx, ox + ow));
                p.x = ox + ow - p.w;
            }
            if (dir.indexOf('s') > -1) p.h = Math.max(MIN_H, Math.min(oh + dy, H - p.y));
            applyWin(p);
        }, function () { saveWin(p); });
    }

    function panel(side, title, body) {
        var W = els.main ? els.main.clientWidth : 1200;
        var H = els.main ? els.main.clientHeight : 700;
        var saved = null;
        try { saved = JSON.parse(readStore('win_' + side, 'null')); } catch (error) {}
        var p = {side: side, open: side === 'left', min: false, w: side === 'left' ? 300 : 360, h: Math.max(MIN_H, H - 24), x: 12, y: 12};
        if (side === 'right') p.x = Math.max(12, W - p.w - 12);
        if (saved && typeof saved === 'object') {
            ['x', 'y', 'w', 'h'].forEach(function (k) { if (typeof saved[k] === 'number') p[k] = saved[k]; });
            p.open = !!saved.open;
            p.min = !!saved.min;
        }
        p.shade = el('button', {type: 'button', class: 'vpb-icon', title: t('vpb_collapse'), 'aria-label': t('vpb_collapse'), onclick: function () { p.min = !p.min; applyWin(p); saveWin(p); }});
        var head = el('div', {class: 'vpb-side-head', onpointerdown: function (event) { startDrag(p, event); }, ondblclick: function () { p.min = !p.min; applyWin(p); saveWin(p); }}, [
            el('h3', {text: title}),
            el('span', {class: 'vpb-grow'}),
            p.shade,
            el('button', {type: 'button', class: 'vpb-icon', title: t('vpb_close'), 'aria-label': t('vpb_close'), text: '✕', onclick: function () { setPanel(p, false); }})
        ]);
        var children = [head, el('div', {class: 'vpb-side-body'}, [body])];
        ['e', 'w', 's', 'se', 'sw'].forEach(function (dir) {
            children.push(el('div', {class: 'vpb-rz vpb-rz--' + dir, onpointerdown: function (event) { startResize(p, dir, event); }}));
        });
        p.node = el('aside', {class: 'vpb-side vpb-side--' + side, 'aria-label': title, onpointerdown: function () { front(p); }}, children);
        p.toggle = el('button', {type: 'button', class: 'vpb-ibtn vpb-panel-toggle', title: title, 'aria-label': title, onclick: function () { setPanel(p, !p.open); }}, [icon(side === 'left' ? 'panelL' : 'panelR')]);
        return p;
    }

    function build() {
        var tabs = el('div', {class: 'vpb-tabs', role: 'tablist'});
        cfg.langs.forEach(function (lang) {
            tabs.appendChild(el('button', {type: 'button', role: 'tab', 'aria-selected': lang === state.lang ? 'true' : 'false', class: 'vpb-tab' + (lang === state.lang ? ' is-on' : ''), text: lang.toUpperCase(), onclick: function () {
                state.lang = lang;
                Array.prototype.forEach.call(tabs.children, function (node) {
                    var on = node.textContent === lang.toUpperCase();
                    node.classList.toggle('is-on', on);
                    node.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                renderStructure();
                renderInspector();
            }}));
        });
        els.devices = el('div', {class: 'vpb-group'}, [
            el('button', {type: 'button', class: 'vpb-ibtn is-on', 'data-device': 'desktop', title: t('vpb_device_desktop'), 'aria-label': t('vpb_device_desktop'), onclick: function () { setDevice('desktop'); }}, [icon('desktop')]),
            el('button', {type: 'button', class: 'vpb-ibtn', 'data-device': 'mobile', title: t('vpb_device_mobile'), 'aria-label': t('vpb_device_mobile'), onclick: function () { setDevice('mobile'); }}, [icon('mobile')])
        ]);
        els.undo = el('button', {type: 'button', class: 'vpb-ibtn', title: t('vpb_undo') + ' (Ctrl+Z)', 'aria-label': t('vpb_undo'), onclick: undo}, [icon('undo')]);
        els.redo = el('button', {type: 'button', class: 'vpb-ibtn', title: t('vpb_redo') + ' (Ctrl+Shift+Z)', 'aria-label': t('vpb_redo'), onclick: redo}, [icon('redo')]);
        els.history = el('div', {class: 'vpb-group'}, [els.undo, els.redo]);
        els.status = el('span', {class: 'vpb-status', role: 'status'});
        els.viewBtn = el('button', {type: 'button', class: 'vpb-ibtn', title: t('vpb_view_preview'), 'aria-label': t('vpb_view_preview'), onclick: openPreviewTab}, [icon('preview')]);
        els.versionsBtn = el('button', {type: 'button', class: 'vpb-ibtn', title: t('vpb_versions'), 'aria-label': t('vpb_versions'), onclick: openVersions}, [icon('history')]);
        els.reset = el('button', {type: 'button', class: 'vpb-ibtn vpb-ibtn--danger', title: t('vpb_reset'), 'aria-label': t('vpb_reset'), onclick: reset}, [icon('reset')]);
        els.structure = el('div', {class: 'vpb-structure'});
        els.inspector = el('div', {class: 'vpb-inspector'});
        els.frame = el('iframe', {class: 'vpb-frame', title: t('vpb_preview')});
        els.frame.addEventListener('load', onFrameLoad);
        els.stage = el('div', {class: 'vpb-stage'}, [els.frame]);
        els.main = el('div', {class: 'vpb-main'}, [els.stage]);

        app.appendChild(el('div', {class: 'vpb-toolbar'}, [
            el('a', {class: 'vpb-ibtn', href: cfg.listUrl, title: t('vpb_back'), 'aria-label': t('vpb_back')}, [icon('back')]),
            el('strong', {class: 'vpb-page', text: cfg.label, title: cfg.label}),
            el('span', {class: 'vpb-sep'}),
            el('div', {class: 'vpb-group', id: 'vpb-toggles'}),
            tabs,
            el('span', {class: 'vpb-sep'}),
            els.devices,
            els.history,
            el('span', {class: 'vpb-grow'}),
            els.status,
            els.versionsBtn,
            els.viewBtn,
            els.reset,
            el('button', {type: 'button', class: 'vpb-save', text: t('vpb_save'), onclick: function () { save(); }})
        ]));
        app.appendChild(els.main);
        els.panelL = panel('left', t('vpb_structure'), els.structure);
        els.panelR = panel('right', t('vpb_inspector'), els.inspector);
        var toggles = document.getElementById('vpb-toggles');
        toggles.removeAttribute('id');
        toggles.appendChild(els.panelL.toggle);
        toggles.appendChild(els.panelR.toggle);
        els.main.appendChild(els.panelL.node);
        els.main.appendChild(els.panelR.node);
        applyWin(els.panelL);
        applyWin(els.panelR);
        front(els.panelL);
        window.addEventListener('resize', function () { applyWin(els.panelL); applyWin(els.panelR); });
        renderToolbarState();
        renderStructure();
        renderInspector();
        histCommit();
        document.addEventListener('keydown', onKey);
        schedulePreview(true);
        if (cfg.draft && cfg.draft.blocks) {
            window.showModal(t('vpb_draft_found'), t('vpb_draft_title'), {showCancel: true, confirmText: t('vpb_draft_restore'), cancelText: t('vpb_draft_discard'), onConfirm: function () {
                state.blocks = normalizeBlocks(cfg.draft.blocks);
                state.sel = null;
                refresh();
                histCommit();
            }});
        }
    }

    window.addEventListener('beforeunload', function (event) {
        if (state.dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    build();
})();
