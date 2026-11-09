/**
 * Form Builder — Admin field editor.
 * Adds/removes field rows, toggles the dropdown-options textarea based on
 * the selected type, keeps the "required" hidden flag in sync with its
 * paired visible checkbox (see admin/templates/partials/field-row.php for
 * why the flag is a hidden input rather than the checkbox itself), and
 * lets rows be reordered by dragging their handle. Reordering is purely a
 * DOM operation — admin/actions.php's save_form reads every field_label[]/
 * field_key[]/etc. array in DOM order, so no separate "position" value
 * needs to be tracked or submitted.
 */
(function () {
    'use strict';

    // ─── Notify-by-email checkbox ───────────────────────────────────────────────
    // Keeps the hidden notify_by_email flag in sync with its paired visible
    // checkbox (see admin/templates/edit.php for why the flag is a hidden
    // input rather than the checkbox itself — an unchecked checkbox is
    // simply absent from $_POST, which admin/actions.php can't tell apart
    // from "field doesn't exist on this form version").
    var notifyCheckbox = document.querySelector('[data-fb-notify-checkbox]');
    var notifyFlag = document.querySelector('[data-fb-notify-flag]');
    if (notifyCheckbox && notifyFlag) {
        notifyCheckbox.addEventListener('change', function () {
            notifyFlag.value = notifyCheckbox.checked ? '1' : '0';
        });
    }

    var list = document.getElementById('fb-fields-list');
    var addBtn = document.getElementById('fb-add-field');
    var rowTemplate = document.getElementById('fb-field-row-template');

    if (!list || !addBtn || !rowTemplate) return;

    var draggedRow = null;

    function bindRow(row) {
        var typeSelect = row.querySelector('[data-fb-field-type]');
        var optionsBlock = row.querySelector('[data-fb-field-options]');
        var separatorBlock = row.querySelector('[data-fb-field-separator]');
        var requiredCheckbox = row.querySelector('[data-fb-required-checkbox]');
        var requiredFlag = row.querySelector('[data-fb-required-flag]');
        var requiredToggle = row.querySelector('.fb-required-toggle');
        var widthSelect = row.querySelector('[data-fb-field-width]');
        var labelInput = row.querySelector('input[name="field_label[]"]');
        var keyInput = row.querySelector('input[name="field_key[]"]');
        var removeBtn = row.querySelector('.fb-remove-field');
        var dragHandle = row.querySelector('[data-fb-drag-handle]');

        function syncType() {
            var isDropdown = typeSelect.value === 'dropdown';
            var isCheckbox = typeSelect.value === 'checkbox';
            var isSeparator = typeSelect.value === 'separator';
            optionsBlock.hidden = !isDropdown;
            if (separatorBlock) separatorBlock.hidden = !isSeparator;

            [labelInput, keyInput, widthSelect].forEach(function (el) {
                if (el) el.classList.toggle('fb-cell-invisible', isSeparator);
            });
            if (requiredToggle) requiredToggle.classList.toggle('fb-cell-invisible', isSeparator);

            // A checkbox (or separator) field is inherently optional —
            // disable and clear the "required" toggle rather than let it
            // submit a meaningless combination the field renderer would
            // ignore anyway.
            requiredCheckbox.disabled = isCheckbox || isSeparator;
            if (isCheckbox || isSeparator) {
                requiredCheckbox.checked = false;
                requiredFlag.value = '0';
            }
        }

        typeSelect.addEventListener('change', syncType);
        syncType();

        requiredCheckbox.addEventListener('change', function () {
            requiredFlag.value = requiredCheckbox.checked ? '1' : '0';
        });

        removeBtn.addEventListener('click', function () {
            row.remove();
        });

        bindDrag(row, dragHandle);
    }

    /**
     * Native HTML5 drag-and-drop, restricted to the handle (dragstart only
     * fires from an element that is itself draggable="true" — the row's
     * text/select/textarea children stay normally interactive since drag
     * never starts from them).
     */
    function bindDrag(row, handle) {
        if (!handle) return;

        handle.addEventListener('dragstart', function (e) {
            draggedRow = row;
            row.classList.add('fb-field-row--dragging');
            e.dataTransfer.effectAllowed = 'move';
            // Firefox requires setData to be called for drag to initiate.
            e.dataTransfer.setData('text/plain', '');
        });

        handle.addEventListener('dragend', function () {
            row.classList.remove('fb-field-row--dragging');
            clearDropMarkers();
            draggedRow = null;
        });
    }

    function clearDropMarkers() {
        list.querySelectorAll('.fb-field-row--drop-before, .fb-field-row--drop-after').forEach(function (el) {
            el.classList.remove('fb-field-row--drop-before', 'fb-field-row--drop-after');
        });
    }

    list.addEventListener('dragover', function (e) {
        if (!draggedRow) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        var overRow = e.target.closest('[data-fb-field-row]');
        if (!overRow || overRow === draggedRow) return;

        clearDropMarkers();

        var rect = overRow.getBoundingClientRect();
        var isAfter = (e.clientY - rect.top) > (rect.height / 2);
        overRow.classList.add(isAfter ? 'fb-field-row--drop-after' : 'fb-field-row--drop-before');
    });

    list.addEventListener('drop', function (e) {
        if (!draggedRow) return;
        e.preventDefault();

        var overRow = e.target.closest('[data-fb-field-row]');
        clearDropMarkers();
        if (!overRow || overRow === draggedRow) return;

        var rect = overRow.getBoundingClientRect();
        var isAfter = (e.clientY - rect.top) > (rect.height / 2);

        if (isAfter) {
            overRow.after(draggedRow);
        } else {
            overRow.before(draggedRow);
        }
    });

    list.querySelectorAll('[data-fb-field-row]').forEach(bindRow);

    addBtn.addEventListener('click', function () {
        var clone = rowTemplate.content.cloneNode(true);
        var row = clone.querySelector('[data-fb-field-row]');
        list.appendChild(clone);
        bindRow(row);
    });
})();
