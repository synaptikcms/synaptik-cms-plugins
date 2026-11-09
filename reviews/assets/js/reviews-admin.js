/**
 * Reviews Plugin — Admin JS
 * Drag-and-drop reordering of the review list. Uses the native HTML5 drag
 * API — no dependency — and persists the new order via a single POST once
 * a drag completes.
 */
(function () {
    'use strict';

    var list = document.querySelector('[data-rv-a-list]');
    if (!list) return;

    var endpoint = list.getAttribute('data-endpoint');
    var csrf = list.getAttribute('data-csrf');
    var draggedRow = null;

    list.addEventListener('dragstart', function (e) {
        var row = e.target.closest('.rv-a-row');
        if (!row) return;
        draggedRow = row;
        row.classList.add('rv-a-row--dragging');
        e.dataTransfer.effectAllowed = 'move';
    });

    list.addEventListener('dragend', function () {
        if (draggedRow) {
            draggedRow.classList.remove('rv-a-row--dragging');
            draggedRow = null;
        }
        persistOrder();
    });

    list.addEventListener('dragover', function (e) {
        e.preventDefault();
        var row = e.target.closest('.rv-a-row');
        if (!row || row === draggedRow || !draggedRow) return;

        var rect = row.getBoundingClientRect();
        var isAfter = (e.clientY - rect.top) > (rect.height / 2);

        list.insertBefore(draggedRow, isAfter ? row.nextSibling : row);
    });

    function persistOrder() {
        var ids = Array.prototype.map.call(list.querySelectorAll('.rv-a-row'), function (row) {
            return row.getAttribute('data-id');
        });

        var formData = new FormData();
        formData.append('action', 'reorder_reviews');
        formData.append('_csrf', csrf);
        formData.append('order', JSON.stringify(ids));

        fetch(endpoint, { method: 'POST', body: formData }).catch(function () {
            // Silent failure — the order simply reverts to its last saved
            // state on next page load, which is an acceptable degradation
            // for a non-critical display preference.
        });
    }
})();
