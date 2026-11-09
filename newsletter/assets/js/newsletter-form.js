/**
 * Newsletter Plugin — Front-end Signup Form
 * Submits [newsletter_signup] via fetch(), shows inline success/error text.
 * No dependencies, one widget instance per .nl-signup element on the page.
 */
(function () {
    'use strict';

    function initWidget(widget) {
        var endpoint = widget.getAttribute('data-nl-endpoint') + 'newsletter-submit.php';
        var submittingLabel = widget.getAttribute('data-nl-submitting-label') || 'Sending…';
        var form = widget.querySelector('.nl-form');
        var messageEl = widget.querySelector('.nl-message');
        var submitBtn = widget.querySelector('.nl-submit');
        var submitLabel = submitBtn ? submitBtn.textContent : '';

        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = submittingLabel;
            }
            messageEl.hidden = true;

            var formData = new FormData(form);

            fetch(endpoint, { method: 'POST', body: formData })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    messageEl.hidden = false;
                    messageEl.textContent = data.message || (window.NL_LANG && window.NL_LANG.error_generic) || '';
                    messageEl.classList.toggle('nl-message--success', !!data.success);
                    messageEl.classList.toggle('nl-message--error', !data.success);

                    if (data.success) {
                        form.reset();
                    }
                })
                .catch(function () {
                    messageEl.hidden = false;
                    messageEl.textContent = (window.NL_LANG && window.NL_LANG.error_generic) || 'An error occurred.';
                    messageEl.classList.add('nl-message--error');
                })
                .finally(function () {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = submitLabel;
                    }
                });
        });
    }

    document.querySelectorAll('.nl-signup').forEach(initWidget);
})();
