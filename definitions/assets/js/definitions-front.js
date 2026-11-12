(function () {
    'use strict';

    var LANG = window.DF_LANG || { close: 'Close' };
    var tooltip = null;
    var activeTerm = null;
    var hideTimer = null;

    function ensureTooltip() {
        if (tooltip) return tooltip;

        tooltip = document.createElement('div');
        tooltip.className = 'df-tooltip';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.id = 'df-tooltip';

        var termEl = document.createElement('strong');
        termEl.className = 'df-tooltip-term';

        var bodyEl = document.createElement('span');
        bodyEl.className = 'df-tooltip-body';

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'df-tooltip-close';
        closeBtn.textContent = LANG.close || 'Close';
        closeBtn.addEventListener('click', hideTooltip);

        tooltip.appendChild(termEl);
        tooltip.appendChild(bodyEl);
        tooltip.appendChild(closeBtn);
        document.body.appendChild(tooltip);

        return tooltip;
    }

    function positionTooltip(target) {
        var rect = target.getBoundingClientRect();
        var tt = ensureTooltip();

        tt.style.left = '0px';
        tt.style.top = '0px';
        var ttRect = tt.getBoundingClientRect();

        var left = rect.left + rect.width / 2 - ttRect.width / 2;
        left = Math.max(8, Math.min(left, window.innerWidth - ttRect.width - 8));

        var top = rect.top - ttRect.height - 8;
        if (top < 8) {
            top = rect.bottom + 8;
        }

        tt.style.left = left + 'px';
        tt.style.top = top + 'px';
    }

    function showTooltip(target) {
        clearTimeout(hideTimer);

        var tt = ensureTooltip();
        tt.querySelector('.df-tooltip-term').textContent = target.textContent;
        tt.querySelector('.df-tooltip-body').textContent = target.getAttribute('data-df-definition') || '';

        activeTerm = target;
        target.classList.add('df-term--active');
        target.setAttribute('aria-describedby', 'df-tooltip');

        positionTooltip(target);
        tt.classList.add('df-tooltip--visible');
    }

    function hideTooltip() {
        if (activeTerm) {
            activeTerm.classList.remove('df-term--active');
            activeTerm.removeAttribute('aria-describedby');
            activeTerm = null;
        }
        if (tooltip) {
            tooltip.classList.remove('df-tooltip--visible');
        }
    }

    function scheduleHide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(hideTooltip, 120);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var terms = document.querySelectorAll('.df-term');

        terms.forEach(function (term) {
            term.addEventListener('mouseenter', function () { showTooltip(term); });
            term.addEventListener('mouseleave', scheduleHide);
            term.addEventListener('focus', function () { showTooltip(term); });
            term.addEventListener('blur', scheduleHide);
            term.addEventListener('click', function (e) {
                e.preventDefault();
                if (activeTerm === term) {
                    hideTooltip();
                } else {
                    showTooltip(term);
                }
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') hideTooltip();
        });

        document.addEventListener('click', function (e) {
            if (!activeTerm) return;
            if (e.target === activeTerm || (tooltip && tooltip.contains(e.target))) return;
            hideTooltip();
        });

        window.addEventListener('resize', function () {
            if (activeTerm) positionTooltip(activeTerm);
        });
        window.addEventListener('scroll', function () {
            if (activeTerm) positionTooltip(activeTerm);
        }, true);

        document.addEventListener('mouseover', function (e) {
            if (tooltip && tooltip.contains(e.target)) clearTimeout(hideTimer);
        });
    });
})();
