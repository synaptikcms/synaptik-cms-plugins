/**
 * Cookie Consent Plugin — Frontend Logic
 *
 * Runs after DOMContentLoaded (script is loaded with `defer`). Handles:
 *  1. Reading the stored consent from localStorage and showing/hiding
 *     the banner accordingly.
 *  2. Wiring the accept-all / reject-all / customize / save-preferences
 *     buttons in the banner and modal.
 *  3. Enforcing the visitor's choice: when a category is rejected, any
 *     cookies matching a known pattern for that category are deleted.
 *  4. Exposing window.SynaptikCookies as the public API used by themes
 *     and other plugins to defer their tracking scripts until consent
 *     for a given category is granted.
 *
 * Consent is stored in localStorage (not a cookie itself) to avoid the
 * meta problem of "the consent banner also sets a cookie" — and to keep
 * the plugin fully client-side, so the site's PHP core never needs to
 * know anything about it.
 */
(function () {
    'use strict';

    var config = window.CC_CONFIG || {};
    var STORAGE_KEY    = config.storageKey     || 'synaptik_cookie_consent';
    var CONSENT_VER    = config.consentVersion || 1;
    var EXPIRATION_MS  = (config.expirationDays || 180) * 24 * 60 * 60 * 1000;
    var CATS_ENABLED   = config.categoriesEnabled || [];

    // ── Known third-party cookie patterns per category ───────────────────
    // Best-effort mapping used by purge() to actively delete cookies from
    // trackers the visitor rejected. Regex-tested against document.cookie.
    // This is not exhaustive — it covers the mainstream trackers most
    // small sites use, which is the 99 % case for Synaptik CMS users.
    var COOKIE_MAP = {
        analytics: [
            /^_ga$/, /^_ga_/, /^_gid$/, /^_gat/, /^_gac_/,       // Google Analytics
            /^_pk_/, /^piwik_/, /^matomo_/,                       // Matomo / Piwik
            /^plausible/,                                         // Plausible (rare, mostly cookie-less)
            /^_hjid$/, /^_hjSessionUser_/, /^_hjSession_/,        // Hotjar
            /^_clck$/, /^_clsk$/, /^CLID$/, /^ANONCHK$/, /^SM$/   // Microsoft Clarity
        ],
        marketing: [
            /^_gcl_/, /^__gads$/, /^__gpi$/,                      // Google Ads
            /^_fbp$/, /^_fbc$/, /^fr$/,                           // Meta / Facebook Pixel
            /^IDE$/, /^test_cookie$/, /^DSID$/,                   // DoubleClick
            /^MUID$/, /^MUIDB$/, /^_uetsid/, /^_uetvid/,          // Microsoft Advertising
            /^personalization_id$/,                               // Twitter/X ads
            /^li_sugr$/, /^bcookie$/, /^lidc$/, /^UserMatchHistory$/, // LinkedIn Insight
            /^_pin_unauth$/, /^_pinterest_/                       // Pinterest
        ]
    };

    // ── Storage helpers ─────────────────────────────────────────────────

    function readConsent() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            if (!parsed || parsed.version !== CONSENT_VER) return null;
            if (typeof parsed.expires === 'number' && Date.now() > parsed.expires) return null;
            return parsed;
        } catch (e) {
            return null;
        }
    }

    function writeConsent(state) {
        var payload = {
            version:   CONSENT_VER,
            timestamp: Date.now(),
            expires:   Date.now() + EXPIRATION_MS,
            necessary: true,
            analytics: !!state.analytics,
            marketing: !!state.marketing
        };
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
        } catch (e) {
            // localStorage disabled (private mode on old browsers) — silently
            // fall back to session-only consent. The banner will reappear
            // on the next visit but the visitor's current choice is honored.
        }
        // Also set a small "strictly necessary" cookie so the server-side
        // PHP can detect that consent already exists and skip injecting the
        // banner assets entirely on subsequent page loads (huge win for the
        // 99 % of page loads that come from returning visitors who already
        // chose). GDPR explicitly permits cookies whose sole purpose is
        // remembering the visitor's consent choice — no meta-consent needed.
        writeConsentCookie(payload.expires);
        currentConsent = payload;
        return payload;
    }

    function clearConsent() {
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
        // Delete the server-facing consent cookie too, so the PHP layer
        // starts re-injecting the banner on the next request.
        deleteConsentCookie();
        currentConsent = null;
    }

    function writeConsentCookie(expiresMs) {
        try {
            var expires = new Date(expiresMs).toUTCString();
            document.cookie = 'synaptik_cc_v1=1; expires=' + expires + '; path=/; SameSite=Lax';
        } catch (e) {}
    }

    function deleteConsentCookie() {
        try {
            document.cookie = 'synaptik_cc_v1=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
        } catch (e) {}
    }

    // ── Cookie purge ────────────────────────────────────────────────────
    // Delete cookies belonging to any category the visitor rejected. Runs
    // right after the visitor saves their choice, so a previously-active
    // tracker's cookies do not survive a category switch from on to off.
    // Best-effort: cookies scoped to a different domain/path may not be
    // deletable from JS, but the mainstream trackers use the current
    // host + root path, which this covers.

    function deleteCookie(name) {
        // Cookies must be deleted with the exact same domain/path they
        // were set with. Try a handful of common combinations rather
        // than parse the actual attributes (impossible from JS).
        var host  = location.hostname;
        var parts = host.split('.');
        var candidates = [host, '.' + host];
        // Also try the eTLD+1 for cookies set on the parent domain
        // (common for _ga_ etc. — set on ".example.com").
        if (parts.length > 1) {
            candidates.push('.' + parts.slice(-2).join('.'));
        }
        var expired = 'Thu, 01 Jan 1970 00:00:01 GMT';
        for (var i = 0; i < candidates.length; i++) {
            document.cookie = name + '=; expires=' + expired + '; path=/; domain=' + candidates[i];
        }
        document.cookie = name + '=; expires=' + expired + '; path=/';
    }

    function purgeRejectedCategories(consent) {
        for (var category in COOKIE_MAP) {
            if (!COOKIE_MAP.hasOwnProperty(category)) continue;
            if (consent && consent[category]) continue; // category still granted, nothing to purge
            purgeCategory(category);
        }
    }

    function purgeCategory(category) {
        var patterns = COOKIE_MAP[category] || [];
        var pairs = document.cookie ? document.cookie.split(';') : [];
        for (var i = 0; i < pairs.length; i++) {
            var name = pairs[i].split('=')[0].trim();
            if (!name) continue;
            for (var j = 0; j < patterns.length; j++) {
                if (patterns[j].test(name)) {
                    deleteCookie(name);
                    break;
                }
            }
        }
    }

    // ── DOM / UI ────────────────────────────────────────────────────────

    var currentConsent = readConsent();
    var pendingCallbacks = { analytics: [], marketing: [] };

    var root, bannerEl, modalEl, backdropEl, checkboxes;

    function cacheDom() {
        root       = document.getElementById('cc-root');
        if (!root) return false;
        bannerEl   = root.querySelector('.cc-banner');
        modalEl    = root.querySelector('.cc-modal');
        backdropEl = root.querySelector('.cc-modal-backdrop');
        checkboxes = root.querySelectorAll('[data-cc-category]');
        return true;
    }

    function showRoot() {
        root.hidden = false;
    }

    // Hides the root element once neither the banner nor the modal are
    // visible. Critical for the 'center' position mode, whose backdrop is
    // a CSS ::before pseudo-element attached to .cc-root.cc-pos-center —
    // if root stays visible after the visitor commits, the dark overlay
    // stays on screen even though banner + modal are gone.
    function maybeHideRoot() {
        if (bannerEl.hidden && modalEl.hidden) {
            root.hidden = true;
        }
    }

    function showBanner() {
        bannerEl.hidden = false;
    }
    function hideBanner() {
        bannerEl.hidden = true;
        maybeHideRoot();
    }
    function showModal() {
        // Sync the checkboxes with the current consent (or fresh defaults).
        var c = currentConsent || {};
        for (var i = 0; i < checkboxes.length; i++) {
            var cat = checkboxes[i].getAttribute('data-cc-category');
            checkboxes[i].checked = !!c[cat];
        }
        modalEl.hidden = false;
        backdropEl.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function hideModal() {
        modalEl.hidden = true;
        backdropEl.hidden = true;
        document.body.style.overflow = '';
        maybeHideRoot();
    }

    function acceptAll() {
        var state = { analytics: true, marketing: true };
        commit(state);
    }
    function rejectAll() {
        var state = { analytics: false, marketing: false };
        commit(state);
    }
    function saveFromModal() {
        var state = {};
        for (var i = 0; i < checkboxes.length; i++) {
            var cat = checkboxes[i].getAttribute('data-cc-category');
            state[cat] = checkboxes[i].checked;
        }
        commit(state);
    }

    function commit(state) {
        var previous = currentConsent;
        var consent  = writeConsent(state);
        hideBanner();
        hideModal();
        purgeRejectedCategories(consent);
        firePendingCallbacks(previous, consent);
        // Also emit a DOM event so themes/plugins can react without using
        // the SynaptikCookies API — useful for GTM-style tag managers.
        try {
            document.dispatchEvent(new CustomEvent('synaptik:cookie-consent', {
                detail: consent
            }));
        } catch (e) {}
        // If this session was triggered by ?cc_open=1 (visitor clicked the
        // [cookie_settings] link from a consented page), strip that param
        // now so a page refresh doesn't reopen the modal unnecessarily.
        stripAutoOpenParam();
    }

    function stripAutoOpenParam() {
        if (!window.CC_AUTO_OPEN) return;
        try {
            var u = new URL(location.href);
            u.searchParams.delete('cc_open');
            history.replaceState(null, '', u.toString());
        } catch (e) {}
    }

    function firePendingCallbacks(previous, consent) {
        // Run any callbacks that were queued while consent was pending
        // for a category that is now granted. Callbacks that stay ungranted
        // remain in the queue in case the visitor changes their mind later.
        ['analytics', 'marketing'].forEach(function (cat) {
            if (!consent[cat]) return;
            var queue = pendingCallbacks[cat];
            pendingCallbacks[cat] = [];
            for (var i = 0; i < queue.length; i++) {
                try { queue[i](); } catch (e) {}
            }
        });
    }

    // ── Event wiring ────────────────────────────────────────────────────

    function onClick(e) {
        var target = e.target.closest ? e.target.closest('[data-cc-action]') : null;
        if (!target) return;
        var action = target.getAttribute('data-cc-action');
        switch (action) {
            // Bottom-bar quick actions — commit and close immediately.
            case 'accept-all':   acceptAll();  break;
            case 'reject-all':   rejectAll();  break;

            // Modal accept/reject — only toggle the category switches so the
            // visitor can see what they are about to accept or reject before
            // committing. Nothing is saved until they click "Save preferences".
            case 'modal-accept-all': setAllCheckboxes(true);  break;
            case 'modal-reject-all': setAllCheckboxes(false); break;

            case 'open-modal':   showModal();  break;
            case 'close-modal':  hideModal();  break;
            case 'save-modal':   saveFromModal(); break;
        }
    }

    function setAllCheckboxes(checked) {
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = checked;
        }
    }

    function onKeydown(e) {
        // Escape closes the modal (banner stays open — hiding it without
        // an explicit choice would count as silent consent).
        if (e.key === 'Escape' && !modalEl.hidden) {
            hideModal();
        }
    }

    // ── Public API ──────────────────────────────────────────────────────

    window.SynaptikCookies = {
        /**
         * Run a callback when consent is (or was already) granted for the
         * given category. If consent is granted, callback runs immediately;
         * otherwise it is queued and runs the moment the visitor grants
         * consent for that category (this session or a later one via
         * openSettings()).
         */
        onConsent: function (category, callback) {
            if (typeof callback !== 'function') return;
            if (category === 'necessary') { callback(); return; }
            if (currentConsent && currentConsent[category]) {
                try { callback(); } catch (e) {}
                return;
            }
            if (!pendingCallbacks[category]) pendingCallbacks[category] = [];
            pendingCallbacks[category].push(callback);
        },

        /**
         * Returns true if the visitor has granted consent for the given
         * category. 'necessary' is always true.
         */
        hasConsent: function (category) {
            if (category === 'necessary') return true;
            return !!(currentConsent && currentConsent[category]);
        },

        /**
         * Opens the settings modal so the visitor can change their choice.
         * Meant to be called from a "Cookie settings" footer link.
         */
        openSettings: function () {
            if (!root) return;
            showRoot();
            showModal();
        },

        /**
         * Clears the stored consent and re-shows the banner. Meant for
         * "revoke my consent" links or for testing during development.
         */
        resetConsent: function () {
            clearConsent();
            purgeRejectedCategories({ analytics: false, marketing: false });
            if (root) {
                showRoot();
                hideModal();
                showBanner();
            }
        }
    };

    // ── Boot ────────────────────────────────────────────────────────────

    function boot() {
        if (!cacheDom()) return;

        root.addEventListener('click', onClick);
        document.addEventListener('keydown', onKeydown);

        if (window.CC_AUTO_OPEN) {
            // Visitor arrived via ?cc_open=1 (clicked the [cookie_settings]
            // link on a consented page). Skip the banner — open the modal
            // directly, pre-populated with their current choices.
            showRoot();
            showModal();
            purgeRejectedCategories(currentConsent || { analytics: false, marketing: false });
        } else if (!currentConsent) {
            // First visit (or consent expired) → show the banner.
            showRoot();
            showBanner();
        } else {
            // Returning visitor with a valid consent → root stays hidden
            // but purge any cookies for categories they rejected earlier,
            // in case a script set them in the meantime.
            purgeRejectedCategories(currentConsent);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
