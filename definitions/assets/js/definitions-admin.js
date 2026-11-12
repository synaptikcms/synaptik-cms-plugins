(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-df-wrap-all-toggle]').forEach(function (checkbox) {
            var target = document.getElementById(checkbox.getAttribute('data-df-wrap-all-toggle'));
            if (!target) return;

            function sync() {
                target.disabled = checkbox.checked;
            }

            checkbox.addEventListener('change', sync);
            sync();
        });

        var searchInput  = document.getElementById('df-search-input');
        var statusSelect = document.getElementById('df-status-filter');
        var perPageSelect = document.getElementById('df-per-page-filter');
        var resetBtn     = document.getElementById('df-filter-reset');
        var table        = document.getElementById('df-table');
        var noResults    = document.getElementById('df-no-results');
        var countEl      = document.getElementById('df-count');
        var pagination   = document.getElementById('df-pagination');
        var pagInfo      = document.getElementById('df-pagination-info');
        var pagPages     = document.getElementById('df-pg-pages');
        var prevBtn      = document.getElementById('df-pg-prev');
        var nextBtn      = document.getElementById('df-pg-next');
        var i18nEl       = document.getElementById('df-i18n');
        if (!searchInput || !table) return;

        var i18n = {};
        try { i18n = JSON.parse(i18nEl.textContent) || {}; } catch (e) {}

        var allRows   = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
        var totalRows = allRows.length;
        var page      = 1;
        var defaultPerPage = perPageSelect.value;

        function rangeText(from, to, count) {
            return (i18n.range || '%1$d–%2$d of %3$d')
                .replace('%1$d', from).replace('%2$d', to).replace('%3$d', count);
        }

        function renderPages(totalPages) {
            pagPages.innerHTML = '';
            if (totalPages <= 1) return;
            var toShow = [1, totalPages];
            for (var p = Math.max(1, page - 2); p <= Math.min(totalPages, page + 2); p++) toShow.push(p);
            toShow = toShow.filter(function (v, i, arr) { return arr.indexOf(v) === i; })
                           .sort(function (x, y) { return x - y; });
            var prev = 0;
            toShow.forEach(function (p) {
                if (prev && p - prev > 1) {
                    var dots = document.createElement('span');
                    dots.className = 'df-pg-ellipsis';
                    dots.textContent = '…';
                    pagPages.appendChild(dots);
                }
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'df-pg-page' + (p === page ? ' active' : '');
                btn.textContent = p;
                btn.addEventListener('click', function () { page = p; apply(); });
                pagPages.appendChild(btn);
                prev = p;
            });
        }

        function apply() {
            var q      = searchInput.value.trim().toLowerCase();
            var status = statusSelect.value;
            var perPage = parseInt(perPageSelect.value, 10);
            if (!perPage) perPage = Math.max(1, allRows.length);

            var visible = allRows.filter(function (row) {
                if (status !== 'all' && row.dataset.dfStatus !== status) return false;
                if (q && row.dataset.dfSearch.indexOf(q) === -1) return false;
                return true;
            });

            var totalPages = Math.max(1, Math.ceil(visible.length / perPage));
            if (page > totalPages) page = totalPages;

            var start = (page - 1) * perPage;
            var pageRows = visible.slice(start, start + perPage);

            allRows.forEach(function (row) { row.style.display = 'none'; });
            pageRows.forEach(function (row) { row.style.display = ''; });

            table.style.display = visible.length ? '' : 'none';
            noResults.style.display = visible.length ? 'none' : '';

            countEl.textContent = visible.length !== totalRows
                ? (visible.length + ' / ' + totalRows)
                : String(totalRows);

            if (visible.length > perPage) {
                pagination.style.display = '';
                pagInfo.textContent = rangeText(start + 1, Math.min(start + perPage, visible.length), visible.length);
                renderPages(totalPages);
                prevBtn.disabled = page <= 1;
                nextBtn.disabled = page >= totalPages;
            } else {
                pagination.style.display = 'none';
            }
        }

        searchInput.addEventListener('input', function () { page = 1; apply(); });
        statusSelect.addEventListener('change', function () { page = 1; apply(); });
        perPageSelect.addEventListener('change', function () { page = 1; apply(); });
        prevBtn.addEventListener('click', function () { page--; apply(); });
        nextBtn.addEventListener('click', function () { page++; apply(); });
        resetBtn.addEventListener('click', function () {
            searchInput.value = '';
            statusSelect.value = 'all';
            perPageSelect.value = defaultPerPage;
            page = 1;
            apply();
        });

        apply();
    });
})();
