/**
 * Comments Plugin — Front-end Behavior
 *
 * Handles: reply-form toggling (one level only), AJAX submission of both
 * the root and reply forms, and inline success/error messaging. No
 * framework dependency — vanilla DOM, consistent with the rest of the
 * CMS's front-end JS (main.js).
 */
(function () {
    'use strict';

    var LANG = window.CM_LANG || {};

    function t(key, fallback) {
        return LANG[key] || fallback;
    }

    // ── Reply toggles ───────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('.cm-reply-toggle');
        if (toggle) {
            var form = document.getElementById(toggle.dataset.target);
            if (form) {
                form.hidden = !form.hidden;
                if (!form.hidden) {
                    var firstInput = form.querySelector('input[name="author_name"]');
                    if (firstInput) firstInput.focus();
                }
            }
            return;
        }

        var cancel = e.target.closest('.cm-cancel-reply');
        if (cancel) {
            var replyForm = cancel.closest('.cm-reply-form');
            if (replyForm) replyForm.hidden = true;
        }
    });

    // ── Form submission ─────────────────────────────────────────────────────
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('[data-cm-form]');
        if (!form) return;

        e.preventDefault();
        submitCommentForm(form);
    });

    function submitCommentForm(form) {
        var button    = form.querySelector('.cm-submit');
        var messageEl = form.querySelector('.cm-message');
        var endpoint  = resolveEndpoint(form);

        if (button) {
            button.disabled = true;
            button.textContent = button.dataset.labelSending || t('sending', 'Sending…');
        }
        hideMessage(messageEl);

        var formData = new FormData(form);

        fetch(endpoint, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        })
            .then(function (response) { return response.json().catch(function () { return {}; }); })
            .then(function (data) {
                if (data && data.ok) {
                    handleSuccess(form, data, messageEl);
                } else {
                    handleError(form, data, messageEl);
                }
            })
            .catch(function () {
                showMessage(messageEl, t('error_generic', 'An error occurred. Please try again.'), 'error');
                resetButton(button);
            });
    }

    /**
     * The enclosing .cm-comments section carries the absolute endpoint URL
     * (data-cm-endpoint, set server-side by cm_render_comments_html() via
     * cm_plugin_base_url()) so this never has to guess the site's base path
     * — correct whether the CMS lives at the domain root or a sub-directory.
     */
    function resolveEndpoint(form) {
        var section = form.closest('.cm-comments');
        return section ? section.getAttribute('data-cm-endpoint') : '';
    }

    function handleSuccess(form, data, messageEl) {
        var button = form.querySelector('.cm-submit');

        if (data.status === 'pending') {
            showMessage(messageEl, t('pending_notice', 'Thank you — your comment is awaiting moderation.'), 'success');
            form.reset();
            resetButton(button);
            return;
        }

        // Approved immediately (auto-moderation) — reload so the new
        // comment appears in the thread. A full reload is simpler and more
        // reliable here than reconstructing the nested-reply DOM client-side.
        window.location.reload();
    }

    function handleError(form, data, messageEl) {
        var button = form.querySelector('.cm-submit');
        var reason = data && data.error;
        var text;

        switch (reason) {
            case 'validation':
                text = t('error_validation', 'Please fill in all fields.');
                break;
            case 'rate':
                text = t('error_rate', 'Too many comments submitted. Please try again later.');
                break;
            default:
                text = t('error_generic', 'An error occurred. Please try again.');
        }

        showMessage(messageEl, text, 'error');
        resetButton(button);
    }

    function showMessage(el, text, type) {
        if (!el) return;
        el.textContent = text;
        el.className = 'cm-message cm-message--' + type;
        el.hidden = false;
    }

    function hideMessage(el) {
        if (!el) return;
        el.hidden = true;
    }

    function resetButton(button) {
        if (!button) return;
        button.disabled = false;
        button.textContent = button.dataset.labelDefault || 'Send';
    }
})();
