/**
 * Reviews Plugin — Front-end Carousel
 * Slides one "page" (the visible group of items, matching the CSS
 * breakpoints in reviews-front.css) at a time via a transform translateX.
 * No dependencies, one instance per .rv-wrapper--carousel on the page.
 * Supports click navigation, dot navigation, touch swipe, and recomputes
 * on resize since the number of items per page changes at breakpoints.
 */
(function () {
    'use strict';

    function initCarousel(wrapper) {
        var track = wrapper.querySelector('[data-rv-track]');
        var prevBtn = wrapper.querySelector('[data-rv-prev]');
        var nextBtn = wrapper.querySelector('[data-rv-next]');
        var dotsContainer = wrapper.querySelector('[data-rv-dots]');
        var items = track ? Array.prototype.slice.call(track.children) : [];

        if (!track || items.length === 0) return;

        var currentPage = 0;
        var itemsPerPage = 1;
        var pageCount = 1;

        function computeItemsPerPage() {
            var width = wrapper.getBoundingClientRect().width;
            if (width >= 960) return 3;
            if (width >= 640) return 2;
            return 1;
        }

        function buildDots() {
            dotsContainer.innerHTML = '';
            if (pageCount <= 1) return;

            for (var i = 0; i < pageCount; i++) {
                var dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'rv-dot' + (i === currentPage ? ' rv-dot--active' : '');
                dot.setAttribute('aria-label', 'Slide ' + (i + 1));
                (function (page) {
                    dot.addEventListener('click', function () { goToPage(page); });
                })(i);
                dotsContainer.appendChild(dot);
            }
        }

        function updateDots() {
            var dots = dotsContainer.querySelectorAll('.rv-dot');
            dots.forEach(function (dot, i) {
                dot.classList.toggle('rv-dot--active', i === currentPage);
            });
        }

        function updateNavState() {
            if (prevBtn) prevBtn.disabled = currentPage === 0;
            if (nextBtn) nextBtn.disabled = currentPage >= pageCount - 1;
        }

        function render() {
            itemsPerPage = computeItemsPerPage();
            pageCount = Math.max(1, Math.ceil(items.length / itemsPerPage));
            currentPage = Math.min(currentPage, pageCount - 1);

            var offsetPercent = currentPage * 100;
            track.style.transform = 'translateX(-' + offsetPercent + '%)';

            buildDots();
            updateNavState();
        }

        function goToPage(page) {
            currentPage = Math.max(0, Math.min(page, pageCount - 1));
            var offsetPercent = currentPage * 100;
            track.style.transform = 'translateX(-' + offsetPercent + '%)';
            updateDots();
            updateNavState();
        }

        if (prevBtn) prevBtn.addEventListener('click', function () { goToPage(currentPage - 1); });
        if (nextBtn) nextBtn.addEventListener('click', function () { goToPage(currentPage + 1); });

        // Touch swipe support.
        var touchStartX = 0;
        track.addEventListener('touchstart', function (e) {
            touchStartX = e.touches[0].clientX;
        }, { passive: true });
        track.addEventListener('touchend', function (e) {
            var deltaX = e.changedTouches[0].clientX - touchStartX;
            if (Math.abs(deltaX) < 40) return;
            goToPage(deltaX < 0 ? currentPage + 1 : currentPage - 1);
        }, { passive: true });

        var resizeTimeout;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(render, 150);
        });

        render();
    }

    document.querySelectorAll('.rv-wrapper--carousel').forEach(initCarousel);
})();
