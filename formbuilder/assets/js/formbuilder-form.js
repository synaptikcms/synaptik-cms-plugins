/**
 * Form Builder — Front-end submission handler.
 * Progressive enhancement over a plain HTML form: intercepts submit,
 * posts via fetch(), and swaps in the success/error message without a
 * full page reload. Falls back to a normal POST if JS fails to load,
 * since the <form> already has a working action/method.
 */
(function () {
    'use strict';

    var strings = window.FB_LANG || {};

    document.querySelectorAll('[data-fb-form]').forEach(function (form) {
        var submitBtn = form.querySelector('.fb-submit');
        var messageEl = form.querySelector('.fb-message');
        var successEl = form.querySelector('.fb-success');

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = submitBtn.getAttribute('data-label-sending') || submitBtn.textContent;
            messageEl.hidden = true;

            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.ok) {
                        form.reset();
                        form.hidden = true;
                        successEl.hidden = false;
                    } else {
                        showError(data.error);
                    }
                })
                .catch(function () {
                    showError('generic');
                })
                .finally(function () {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.getAttribute('data-label-default') || submitBtn.textContent;
                });
        });

        function showError(code) {
            var text = strings.error_generic || 'An error occurred. Please try again.';
            if (code === 'validation') text = strings.error_validation || text;
            if (code === 'rate') text = strings.error_rate || text;

            messageEl.textContent = text;
            messageEl.className = 'fb-message fb-message--error';
            messageEl.hidden = false;
        }
    });
})();
