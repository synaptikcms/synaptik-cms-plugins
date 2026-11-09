<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Cookie Consent Plugin — Init
 *
 * Two responsibilities:
 * 1. Register the admin sidebar entry ('admin_menu' hook, see plugin-api.php).
 * 2. Inject the banner (CSS + HTML + JS) into every front-end HTML response
 *    via an output-buffer hook — same pattern as the Newsletter plugin.
 *
 * The output-buffer approach is deliberate: theme hooks like 'after_footer'
 * are not called by every default theme (Mono/default omits it), so relying
 * on them would silently do nothing on those themes. The output buffer runs
 * on every front-end response regardless of the theme, guaranteeing the
 * consent banner is universally present as GDPR requires.
 */

if (defined('CC_INIT_LOADED')) return;
define('CC_INIT_LOADED', true);

require_once __DIR__ . '/cookie-consent-functions.php';
require_once __DIR__ . '/cookie-consent-i18n.php';
require_once __DIR__ . '/admin/admin-page.php';

// ── Admin sidebar entry ────────────────────────────────────────────────────
if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        pl_register_admin_menu(
            'cookie-consent',
            cc_t('cc_admin_title', 'Cookie Consent', 'admin'),
            cc_admin_page_url(),
            '<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01"/><path d="M16 15.5v.01"/><path d="M12 12v.01"/><path d="M11 17v.01"/><path d="M7 14v.01"/>'
        );
    });
}

// ── Front-end injection via output buffer ─────────────────────────────────
// Guarded to run only on front-end HTML responses:
//   - Never on the CLI (cron / composer / test runs).
//   - Never on the plugin's own actions.php endpoint.
//   - Never on the admin panel (which already handles its own layout).
//
// strpos(...) !== false rather than === 0 because the CMS may live in a
// sub-directory, so '/admin/' will not necessarily be the first segment.
$__cc_isCli    = PHP_SAPI === 'cli';
$__cc_isPlugin = strpos($_SERVER['REQUEST_URI'] ?? '', '/cookie-consent/') !== false;
$__cc_cfgFile  = dirname(__DIR__, 2) . '/config.json';
$__cc_adminDir = 'admin';
if (file_exists($__cc_cfgFile)) {
    $__cc_cfg = json_decode(file_get_contents($__cc_cfgFile), true);
    if (is_array($__cc_cfg) && !empty($__cc_cfg['admin_dir'])) {
        $__cc_adminDir = $__cc_cfg['admin_dir'];
    }
    unset($__cc_cfg);
}
unset($__cc_cfgFile);
$__cc_isAdmin = strpos($_SERVER['REQUEST_URI'] ?? '', '/' . $__cc_adminDir . '/') !== false;

if (!$__cc_isCli && !$__cc_isPlugin && !$__cc_isAdmin) {
    $__cc_settings = cc_load_settings();
    if (!empty($__cc_settings['enabled'])) {

        // Server-side consent detection. The JS layer writes a small
        // "strictly necessary" cookie (synaptik_cc_v1=1) at the same time
        // it stores the full consent payload in localStorage, so PHP can
        // recognise returning consented visitors and skip injecting the
        // banner assets entirely — keeping their page source clean and
        // shaving a few KB off every subsequent page load.
        //
        // ?cc_open=1 overrides this: it is emitted by the [cookie_settings]
        // shortcode link and forces a fresh injection so the JS can
        // auto-open the settings modal (see boot() in cookie-consent.js).
        $__cc_hasConsent = !empty($_COOKIE['synaptik_cc_v1']) && $_COOKIE['synaptik_cc_v1'] === '1';
        $__cc_forceOpen  = isset($_GET['cc_open']) && $_GET['cc_open'] === '1';
        $__cc_skipAssets = $__cc_hasConsent && !$__cc_forceOpen;

        ob_start(function (string $html) use ($__cc_skipAssets, $__cc_forceOpen): string {
            // Resolve the [cookie_settings] shortcode first, wherever it
            // appears in the page (footer text, menu label, page content).
            // Cheap early-out with strpos() so the str_replace only runs on
            // pages that actually use the shortcode. Renders differently
            // depending on whether the banner assets will be present on
            // this page — see cc_render_settings_link_html().
            if (strpos($html, '[cookie_settings]') !== false) {
                $html = str_replace('[cookie_settings]', cc_render_settings_link_html($__cc_skipAssets), $html);
            }

            // Consented visitor and not asking to reopen — the banner
            // assets are not needed on this response, so return the page
            // untouched from here.
            if ($__cc_skipAssets) {
                return $html;
            }

            // Guard against injecting into non-HTML responses (JSON, images,
            // sitemap XML, etc.) — those responses don't contain </body> so
            // the injection is skipped naturally, but the extra check keeps
            // the callback fast on binary payloads.
            if (stripos($html, '</body>') === false) {
                return $html;
            }

            // front-boot.js (loaded once, in <head>) picks up this data-window-var
            // island via its MutationObserver whenever it's inserted — order
            // relative to cc_render_frontend_assets() below no longer matters.
            $autoOpenScript = $__cc_forceOpen
                ? '<script type="application/json" data-window-var="CC_AUTO_OPEN">1</script>'
                : '';

            $payload = $autoOpenScript . cc_render_frontend_assets() . cc_render_banner_html();

            // Inject just before </body> so the CSS/JS/HTML all live inside
            // the document body — link tags and defer scripts work fine
            // outside the <head>, and injecting here avoids fighting with
            // themes that emit their own scripts after </html>.
            return preg_replace('/<\/body>/i', $payload . '</body>', $html, 1);
        });
    }
    unset($__cc_settings);
}
unset($__cc_isCli, $__cc_isPlugin, $__cc_isAdmin);
