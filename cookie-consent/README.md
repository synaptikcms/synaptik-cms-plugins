# Cookie Consent

Cookie consent banner for Synaptik CMS with per-category opt-in, stateless
customizable UI, and a public JavaScript API so themes and other plugins
can defer their tracking scripts until consent is granted for the
matching category.

## Why

Any site with visitors in the EU/UK is legally required to obtain
informed consent before setting non-essential cookies (GDPR, ePrivacy
Directive, French CNIL guidelines). This plugin gives Synaptik CMS a
zero-configuration, out-of-the-box consent banner that ticks the main
UI-side compliance boxes:

- Explicit opt-in per category (analytics, marketing).
- **Reject-all button as prominent as accept-all** (required by the CNIL).
- Consent expires and is re-prompted after a configurable delay (default
  180 days, the CNIL's recommended maximum).
- No pre-checked boxes for non-essential categories.
- Persistent access to change or withdraw consent later via a shortcode.

## ⚠️ Important — read this before assuming you are compliant

**Installing this plugin does NOT, by itself, make your site GDPR-compliant.**
It handles the consent UI and does a best-effort cookie purge, but it
cannot magically prevent third-party tracking scripts already present in
your theme from running. If you have GA / Meta Pixel / anything else
loaded as a plain `<script src="...">` in your theme, those scripts
will run on every page load regardless of what the visitor clicks.

**What this plugin does:**

1. Shows a legally-styled consent UI and stores the visitor's choice in
   `localStorage`.
2. **After the fact**, deletes cookies matching known tracker patterns
   (GA, Matomo, Meta Pixel, etc.) when the visitor rejects a category.
   Runs on every page load, so returning visitors stay purged.
3. Exposes `window.SynaptikCookies.onConsent(...)` so scripts can opt
   into being deferred until consent is given.

**What this plugin does NOT do automatically:**

1. It does **not** intercept `<script>` tags already in your theme. If
   your `footer.php` contains `<script src="gtag.js"></script>`, that
   script will load, execute, and set cookies on **every** page load,
   independently of consent. The plugin's purge kicks in after that,
   which means the cookies get set and then deleted — the initial
   requests to Google's servers still happen (IP, User-Agent, referer
   leaked), and the cookies exist for a brief moment before being cleared.
2. It does **not** stop server-side tracking (server logs, CDN analytics,
   backend Meta Conversions API, etc.). Those are outside the browser's
   reach entirely.

**For real strict compliance you must, in your theme:**

- Wrap every tracking script in `SynaptikCookies.onConsent('category', ...)`
  (see the JavaScript API section below).
- Or replace `<script src="...">` with the deferred `type="text/plain"`
  pattern once the `data-consent-category` v2 feature ships.
- Test with your browser DevTools → Application → Cookies to confirm that
  no analytics/marketing cookies appear before the visitor clicks Accept.

If you are OK with the plugin only doing "consent UI + post-hoc purge"
without the theme-side effort, that is a defensible middle ground for
most non-critical sites, but do not tell your legal team you are
"fully GDPR-compliant" without the theme modifications.

## How it works

The banner, CSS, and JS are injected into every front-end HTML response
via an output-buffer hook — no theme integration required, works with
every Synaptik CMS theme regardless of whether the theme calls specific
`do_theme_action` hooks.

**Once the visitor has made their choice**, the plugin skips injection
entirely on every subsequent page load: the page source stays clean, no
CSS/JS assets are shipped, and there is zero DOM footprint from the
plugin. Achieved through a small strictly-necessary cookie (`synaptik_cc_v1`)
written alongside the localStorage payload, so the server-side PHP can
detect that consent already exists before deciding whether to inject.
This cookie is GDPR-exempt by design — its sole purpose is remembering
the visitor's own consent choice, which is explicitly permitted without
prior consent under Article 5(3) of the ePrivacy Directive.

The full consent payload (which categories, when it expires) lives in
`localStorage` so the plugin stays fully client-side for the actual
choice logic. When the visitor rejects a category, cookies matching
known tracker patterns for that category are actively deleted from
`document.cookie` — best-effort, but covers Google Analytics, GA4,
Matomo, Hotjar, Microsoft Clarity, Google Ads, Meta Pixel, DoubleClick,
Microsoft Advertising, LinkedIn Insight, Pinterest, Twitter/X ads.

## Admin

Admin → Cookie Consent. One page with a top-level on/off toggle
(disable the banner without deactivating the plugin, e.g. for local
development), plus panels for banner position, colors, consent
lifetime, category toggles, and the optional privacy-policy link.

## Reopening the settings modal — `[cookie_settings]` shortcode

GDPR requires visitors to be able to change or withdraw their consent
at any time. Paste the following shortcode anywhere on your site — the
footer text, a menu item, your privacy policy page — and it renders as
a plain text link that opens the preferences modal when clicked:

```
[cookie_settings]
```

The link text is customizable via the `cc_settings_link_label` key in
`lang/en.json` / `lang/fr.json` / `lang/es.json`. Default is
"Cookie settings" (`en`), "Préférences cookies" (`fr`), and
"Preferencias de cookies" (`es`).

**On consented pages** (where the plugin normally skips injection to keep
the source clean), the shortcode still works: it renders as a link to
`?cc_open=1` on the current URL. When clicked, the server sees that
param, injects the banner assets on that one request, and the JS boots
directly into the settings modal. Once the visitor saves their new
choice, the `cc_open` param is silently stripped from the URL via
`history.replaceState`, so a page refresh does not reopen the modal.

## JavaScript API — for theme and plugin developers

The banner exposes a public API as `window.SynaptikCookies`. Use it to
defer any tracking script until the visitor has granted consent for the
matching category:

```javascript
SynaptikCookies.onConsent('analytics', function () {
    // Load Plausible / Umami / GA / Matomo here.
    // Runs immediately if consent was already given, or the moment the
    // visitor grants consent for 'analytics' later.
});

SynaptikCookies.hasConsent('marketing'); // → true|false, synchronous check
SynaptikCookies.openSettings();          // reopen the modal
SynaptikCookies.resetConsent();          // clear stored choice, re-show banner
```

### Concrete example: wrapping a Google Analytics script

Before (**loads unconditionally, sets cookies without consent**):

```html
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXX"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-XXXXXX');
</script>
```

After (**only loads once analytics consent is granted**):

```html
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());

if (window.SynaptikCookies) {
    SynaptikCookies.onConsent('analytics', function () {
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=G-XXXXXX';
        document.head.appendChild(s);
        gtag('config', 'G-XXXXXX');
    });
}
</script>
```

Same pattern applies to any other tracker: wrap the loading code in
`SynaptikCookies.onConsent(category, callback)`.

### `synaptik:cookie-consent` DOM event

Every time the visitor saves their choice, a custom event is dispatched
on `document` — useful for GTM-style tag managers or event-driven code:

```javascript
document.addEventListener('synaptik:cookie-consent', function (e) {
    // e.detail contains { necessary, analytics, marketing, expires, timestamp }
});
```

## Data

**Server-side files:**

- `data/config.json` — plugin configuration written from the admin
  page (enabled state, position, colors, expiration, category toggles,
  privacy URL).
- `private/cookie-consent.secret` — HMAC secret used by the admin CSRF
  system. Stateless, independent from the core session, generated on
  first admin save.

Both `data/` and `private/` are protected by an auto-generated
`.htaccess` denying all direct HTTP access.

**Visitor-side storage:**

- `localStorage['synaptik_cookie_consent']` — the full consent payload:
  chosen categories, timestamp, expiration. Read by the JS at boot to
  decide what to show and which pending scripts to release.
- Cookie `synaptik_cc_v1=1` — tiny strictly-necessary marker cookie
  (7 bytes value, ~30 bytes total on the wire) that lets the server
  detect consent without a JS round-trip. Same expiration as the
  localStorage entry. Exempt from consent under ePrivacy Article 5(3)
  since it exists solely to remember the visitor's own consent choice.
  Automatically deleted when `SynaptikCookies.resetConsent()` is called.

## Known cookie patterns (best-effort purge)

The purge logic recognises the following third-party trackers and
categorises them automatically. If the site uses a tracker not listed,
it will not be actively deleted on rejection — but its own script will
still be blocked as long as the theme/plugin loading it uses
`SynaptikCookies.onConsent(...)` properly.

- **Analytics** — Google Analytics (`_ga*`, `_gid`, `_gat`, `_gac_`),
  Matomo/Piwik (`_pk_*`, `piwik_*`, `matomo_*`), Plausible (`plausible*`),
  Hotjar (`_hjid`, `_hjSessionUser_*`, `_hjSession_*`), Microsoft Clarity
  (`_clck`, `_clsk`, `CLID`, `ANONCHK`, `SM`).
- **Marketing** — Google Ads (`_gcl_*`, `__gads`, `__gpi`), Meta/Facebook
  Pixel (`_fbp`, `_fbc`, `fr`), DoubleClick (`IDE`, `test_cookie`,
  `DSID`), Microsoft Advertising (`MUID`, `MUIDB`, `_uetsid*`,
  `_uetvid*`), Twitter/X (`personalization_id`), LinkedIn Insight
  (`li_sugr`, `bcookie`, `lidc`, `UserMatchHistory`), Pinterest
  (`_pin_unauth`, `_pinterest_*`).

Reminder: the purge deletes cookies **after** they were set by an
already-loaded script. To prevent cookies from being set in the first
place, wrap the script itself using the `onConsent` API above.
