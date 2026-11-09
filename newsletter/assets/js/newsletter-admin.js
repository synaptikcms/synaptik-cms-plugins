/**
 * Newsletter Plugin — Admin Batch Sender
 * Drives admin/actions.php's send_digest_batch / send_custom_batch actions
 * in a loop, one small batch at a time, so a large subscriber list never
 * risks a single long-running request timing out or tripping the host's
 * mail rate limit. Handles both the dashboard's quick digest button and
 * the composer's send button — distinguished by data-batch-action.
 */
(function () {
    'use strict';

    var btn = document.getElementById('nl-send-digest') || document.getElementById('nl-send-custom');
    if (!btn) return;

    var isCustom = btn.dataset.batchAction === 'send_custom_batch';

    var progressWrap = document.getElementById('nl-digest-progress');
    var progressFill = progressWrap ? progressWrap.querySelector('.nl-progress-fill') : null;
    var progressLabel = progressWrap ? progressWrap.querySelector('.nl-progress-label') : null;
    var resultEl = document.getElementById('nl-digest-result');
    var lang = window.NL_ADMIN_LANG || {};

    function t(key, vars) {
        var str = lang[key] || key;
        if (vars) {
            Object.keys(vars).forEach(function (k) {
                str = str.replace('{' + k + '}', vars[k]);
            });
        }
        return str;
    }

    function showResult(text, isError) {
        resultEl.hidden = false;
        resultEl.textContent = text;
        resultEl.classList.toggle('nl-digest-result--error', !!isError);
        resultEl.classList.toggle('nl-digest-result--success', !isError);
    }

    function sendBatch(endpoint, action, csrf, offset, subject, body) {
        var formData = new FormData();
        formData.append('action', action);
        formData.append('_csrf', csrf);
        formData.append('offset', offset);
        formData.append('subject', subject || '');
        formData.append('body', body || '');

        return fetch(endpoint, { method: 'POST', body: formData }).then(function (res) {
            return res.json();
        });
    }

    btn.addEventListener('click', function () {
        var endpoint = btn.getAttribute('data-endpoint');
        var action = btn.getAttribute('data-batch-action');
        var csrf = btn.getAttribute('data-csrf');

        var initialSubject = '';
        var initialBody = '';

        if (isCustom) {
            var subjectInput = document.getElementById('nl-subject');
            // #content-hidden is created by editor.js and mirrors the live
            // WYSIWYG content; falls back to the raw textarea if the editor
            // script hasn't finished initializing yet.
            var contentHidden = document.getElementById('content-hidden');
            var contentTextarea = document.getElementById('content');

            initialSubject = subjectInput ? subjectInput.value.trim() : '';
            initialBody = contentHidden ? contentHidden.value : (contentTextarea ? contentTextarea.value : '');

            if (!initialSubject || !initialBody || initialBody === '<p><br></p>') {
                showResult(t('empty_composer'), true);
                return;
            }
            if (!confirm(t('confirm_send'))) return;
        }

        btn.disabled = true;
        progressWrap.hidden = false;
        resultEl.hidden = true;
        progressFill.style.width = '0%';
        progressLabel.textContent = t('sending');

        function step(offset, subject, body) {
            sendBatch(endpoint, action, csrf, offset, subject, body)
                .then(function (data) {
                    if (!data.success) {
                        var key = data.error && lang[data.error] ? data.error : (
                            data.error === 'no_new_articles' ? 'no_new_articles' :
                            data.error === 'no_subscribers' ? 'no_subscribers' :
                            data.error === 'empty_composer' ? 'empty_composer' : 'error'
                        );
                        showResult(t(key), true);
                        progressWrap.hidden = true;
                        btn.disabled = false;
                        return;
                    }

                    var pct = data.total > 0 ? Math.round((data.sent / data.total) * 100) : 100;
                    progressFill.style.width = pct + '%';
                    progressLabel.textContent = t('sent_progress', { sent: data.sent, total: data.total });

                    if (data.done) {
                        progressWrap.hidden = true;
                        showResult(t('done', { total: data.total }), false);
                        btn.disabled = false;
                    } else {
                        step(data.sent, data.subject, data.body);
                    }
                })
                .catch(function () {
                    showResult(t('error'), true);
                    progressWrap.hidden = true;
                    btn.disabled = false;
                });
        }

        step(0, initialSubject, initialBody);
    });
})();
