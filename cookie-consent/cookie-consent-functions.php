<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Cookie Consent Plugin — Core Functions
 *
 * Settings storage, defaults, atomic saves, CSRF (stateless HMAC, same
 * pattern as Redirects and Maintenance plugins), asset URL helpers, and
 * the two render helpers called from cookie-consent-init.php on every
 * front-end page (banner HTML + <link>/<script> tags).
 */

if (defined('CC_FUNCTIONS_LOADED')) return;
define('CC_FUNCTIONS_LOADED', true);

require_once __DIR__ . '/cookie-consent-i18n.php';

// ─── Paths ─────────────────────────────────────────────────────────────────

function cc_data_dir(): string
{
    return __DIR__ . '/data';
}

function cc_settings_path(): string
{
    return cc_data_dir() . '/config.json';
}

function cc_private_dir(): string
{
    $dir = __DIR__ . '/private';

    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $htaccess = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
              . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    @file_put_contents($dir . '/.htaccess', $htaccess);

    return $dir;
}

// ─── Site URL helpers ──────────────────────────────────────────────────────

/**
 * Site base URL with a trailing slash. Independent of the core's
 * getBaseUrl() because this plugin's asset injection runs from theme
 * hooks that may fire before every core helper is fully initialised.
 */
function cc_site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    $host = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $cmsRootFilesystemPath = dirname(__DIR__, 2); // plugins/cookie-consent/ -> plugins/ -> CMS root
    $documentRoot          = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $cmsRootFilesystemPath = str_replace('\\', '/', $cmsRootFilesystemPath);

    $realCmsRoot = realpath($cmsRootFilesystemPath) ?: $cmsRootFilesystemPath;
    $realDocRoot = $documentRoot !== '' ? (realpath($documentRoot) ?: $documentRoot) : '';
    $realCmsRoot = str_replace('\\', '/', $realCmsRoot);
    $realDocRoot = str_replace('\\', '/', $realDocRoot);

    if ($realDocRoot !== '' && strpos($realCmsRoot, $realDocRoot) === 0) {
        $urlPath = substr($realCmsRoot, strlen($realDocRoot));
    } elseif ($documentRoot !== '' && strpos($cmsRootFilesystemPath, $documentRoot) === 0) {
        $urlPath = substr($cmsRootFilesystemPath, strlen($documentRoot));
    } else {
        $urlPath = function_exists('getBaseUrl') ? parse_url(getBaseUrl(), PHP_URL_PATH) : '/';
    }

    $urlPath = '/' . trim($urlPath, '/');
    return $urlPath === '/' ? $host . '/' : $host . $urlPath . '/';
}

function cc_plugin_base_url(): string
{
    return cc_site_base_url() . 'plugins/cookie-consent/';
}

/**
 * Cache-busted URL to one of the plugin's own assets.
 */
function cc_asset_url(string $relativePath): string
{
    $absPath = __DIR__ . '/' . $relativePath;
    $v       = @filemtime($absPath) ?: time();
    return cc_plugin_base_url() . $relativePath . '?v=' . $v;
}

// ─── Settings ──────────────────────────────────────────────────────────────

function cc_default_settings(): array
{
    return [
        // Global toggle. When false, the banner is not injected at all —
        // handy for local development or when temporarily disabling the
        // plugin without deactivating it (deactivation forgets accumulated
        // per-category descriptions).
        'enabled'          => true,

        // Banner position — 'bottom' (full-width bar at the bottom of the
        // viewport) or 'center' (modal-style dialog centered on screen).
        'position'         => 'bottom',

        // Consent lifetime in days. 180 (6 months) matches the CNIL's
        // upper recommendation — after this the banner reappears so the
        // visitor is re-prompted for a fresh consent.
        'expiration_days'  => 180,

        // Colors — falls back to the built-in palette when empty.
        'bg_color'         => '#ffffff',
        'text_color'       => '#1a1a1a',
        'accent_color'     => '#3ea34e',

        // Show the "Reject all" button next to "Accept all". Recommended
        // ON for GDPR compliance (French CNIL requires reject to be as
        // prominent as accept).
        'show_reject_all'  => true,

        // Category opt-ins. 'necessary' is always active and never shown
        // as a toggle to the visitor — it exists purely to declare in the
        // detailed view that some cookies are strictly required.
        'category_analytics' => true,
        'category_marketing' => true,

        // URL to the site's own privacy policy page. Empty = link hidden.
        'privacy_policy_url' => '',
    ];
}

/**
 * Ensures the data directory exists with .htaccess protection.
 */
function cc_ensure_dirs(): void
{
    $dir = cc_data_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $content = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
             . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    @file_put_contents($dir . '/.htaccess', $content);
}

function cc_load_settings(): array
{
    if (isset($GLOBALS['_cc_config_cache'])) {
        return $GLOBALS['_cc_config_cache'];
    }

    $settings = cc_default_settings();
    $path     = cc_settings_path();

    if (file_exists($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded)) {
            $settings = array_merge($settings, $decoded);
        }
    }

    $GLOBALS['_cc_config_cache'] = $settings;
    return $settings;
}

/**
 * Atomic write (tmp + rename) — prevents a partial write from corrupting
 * config.json if the request is interrupted mid-save.
 */
function cc_save_settings(array $settings): bool
{
    cc_ensure_dirs();

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $path = cc_settings_path();
    $tmp  = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;

    $success = rename($tmp, $path);
    if ($success) {
        unset($GLOBALS['_cc_config_cache']);
    }
    return $success;
}

// ─── CSRF (admin form only) ────────────────────────────────────────────────
// Stateless HMAC token, same shape/lifetime as the Redirects, Newsletter
// and Maintenance plugins — independent of $_SESSION so this plugin has
// no ordering dependency on core session bootstrap.

function cc_get_secret(): string
{
    $secretFile = cc_private_dir() . '/cookie-consent.secret';

    if (file_exists($secretFile)) {
        $secret = trim(file_get_contents($secretFile));
        if (strlen($secret) === 64) {
            return $secret;
        }
    }

    $secret = bin2hex(random_bytes(32));
    @file_put_contents($secretFile, $secret, LOCK_EX);
    return $secret;
}

function cc_generate_csrf(): string
{
    $ts     = time();
    $secret = cc_get_secret();
    $mac    = hash_hmac('sha256', (string)$ts, $secret);
    return $ts . '.' . $mac;
}

function cc_verify_csrf(string $token): bool
{
    if (empty($token) || substr_count($token, '.') !== 1) return false;

    [$ts, $mac] = explode('.', $token, 2);
    if (!is_numeric($ts)) return false;
    if ((time() - (int)$ts) > 7200) return false;

    $expected = hash_hmac('sha256', $ts, cc_get_secret());
    return hash_equals($expected, $mac);
}

// ─── Front-end rendering ───────────────────────────────────────────────────

/**
 * Returns the banner + detailed-settings modal HTML injected at the end of
 * every front-end page. All visible strings pass through cc_t() so themes
 * see the site's active locale automatically.
 *
 * The banner is hidden by default via inline `hidden` attribute — the JS
 * removes it after checking localStorage, so the banner never flashes for
 * visitors who have already made their choice.
 */
function cc_render_banner_html(): string
{
    $settings   = cc_load_settings();
    $showReject = !empty($settings['show_reject_all']);
    $privacyUrl = trim($settings['privacy_policy_url'] ?? '');

    // Build the categories block. Necessary is always shown as read-only.
    $categoriesHtml = '';
    $catNecessaryTitle = htmlspecialchars(cc_t('cc_cat_necessary_title', 'Strictly necessary'));
    $catNecessaryDesc  = htmlspecialchars(cc_t('cc_cat_necessary_desc', 'Required for the site to work (session, security). Cannot be disabled.'));
    $catAlwaysOn       = htmlspecialchars(cc_t('cc_always_on', 'Always on'));

    $categoriesHtml .= <<<HTML
    <div class="cc-cat">
        <div class="cc-cat-head">
            <span class="cc-cat-title">{$catNecessaryTitle}</span>
            <span class="cc-cat-always">{$catAlwaysOn}</span>
        </div>
        <p class="cc-cat-desc">{$catNecessaryDesc}</p>
    </div>
HTML;

    if (!empty($settings['category_analytics'])) {
        $catAnalyticsTitle = htmlspecialchars(cc_t('cc_cat_analytics_title', 'Analytics'));
        $catAnalyticsDesc  = htmlspecialchars(cc_t('cc_cat_analytics_desc', 'Anonymous usage statistics to help improve the site (page views, referrers).'));
        $categoriesHtml .= <<<HTML
    <div class="cc-cat">
        <div class="cc-cat-head">
            <span class="cc-cat-title">{$catAnalyticsTitle}</span>
            <label class="cc-switch"><input type="checkbox" data-cc-category="analytics"><span class="cc-switch-slider"></span></label>
        </div>
        <p class="cc-cat-desc">{$catAnalyticsDesc}</p>
    </div>
HTML;
    }

    if (!empty($settings['category_marketing'])) {
        $catMarketingTitle = htmlspecialchars(cc_t('cc_cat_marketing_title', 'Marketing'));
        $catMarketingDesc  = htmlspecialchars(cc_t('cc_cat_marketing_desc', 'Advertising and personalization cookies from third parties (retargeting, conversion tracking).'));
        $categoriesHtml .= <<<HTML
    <div class="cc-cat">
        <div class="cc-cat-head">
            <span class="cc-cat-title">{$catMarketingTitle}</span>
            <label class="cc-switch"><input type="checkbox" data-cc-category="marketing"><span class="cc-switch-slider"></span></label>
        </div>
        <p class="cc-cat-desc">{$catMarketingDesc}</p>
    </div>
HTML;
    }

    // i18n for banner and modal
    $bannerTitle    = htmlspecialchars(cc_t('cc_banner_title', 'We value your privacy'));
    $bannerText     = htmlspecialchars(cc_t('cc_banner_text', 'We use cookies to enhance your browsing experience and analyze site traffic. By clicking "Accept all", you consent to our use of cookies. You can also customize your preferences or reject non-essential cookies.'));
    $btnAccept      = htmlspecialchars(cc_t('cc_btn_accept', 'Accept all'));
    $btnReject      = htmlspecialchars(cc_t('cc_btn_reject', 'Reject all'));
    $btnCustomize   = htmlspecialchars(cc_t('cc_btn_customize', 'Customize'));
    $btnSave        = htmlspecialchars(cc_t('cc_btn_save', 'Save preferences'));
    $btnClose       = htmlspecialchars(cc_t('cc_btn_close', 'Close'));
    $modalTitle     = htmlspecialchars(cc_t('cc_modal_title', 'Cookie preferences'));
    $modalIntro     = htmlspecialchars(cc_t('cc_modal_intro', 'Choose which categories of cookies you allow. You can withdraw your consent later at any time.'));
    $privacyLabel   = htmlspecialchars(cc_t('cc_privacy_link', 'Privacy policy'));

    $rejectButton = $showReject
        ? '<button type="button" class="cc-btn cc-btn--danger" data-cc-action="reject-all">' . $btnReject . '</button>'
        : '';

    $privacyLink = $privacyUrl !== ''
        ? '<a class="cc-privacy-link" href="' . htmlspecialchars(cc_resolve_url($privacyUrl)) . '" target="_blank" rel="noopener">' . $privacyLabel . '</a>'
        : '';

    $position = htmlspecialchars($settings['position'] === 'center' ? 'center' : 'bottom');

    return <<<HTML
<div id="cc-root" class="cc-root cc-pos-{$position}" hidden>

    <div class="cc-banner" role="dialog" aria-labelledby="cc-banner-title" aria-describedby="cc-banner-desc" data-cc-view="banner" hidden>
        <div class="cc-banner-body">
            <h2 class="cc-banner-title" id="cc-banner-title">{$bannerTitle}</h2>
            <p class="cc-banner-text" id="cc-banner-desc">{$bannerText} {$privacyLink}</p>
        </div>
        <div class="cc-banner-actions">
            <button type="button" class="cc-btn cc-btn--outline" data-cc-action="open-modal">{$btnCustomize}</button>
            {$rejectButton}
            <button type="button" class="cc-btn cc-btn--primary" data-cc-action="accept-all">{$btnAccept}</button>
        </div>
    </div>

    <div class="cc-modal-backdrop" data-cc-action="close-modal" data-cc-view="modal" hidden></div>
    <div class="cc-modal" role="dialog" aria-modal="true" aria-labelledby="cc-modal-title" data-cc-view="modal" hidden>
        <div class="cc-modal-head">
            <h2 class="cc-modal-title" id="cc-modal-title">{$modalTitle}</h2>
            <button type="button" class="cc-modal-close" aria-label="{$btnClose}" data-cc-action="close-modal">&times;</button>
        </div>
        <div class="cc-modal-body">
            <p class="cc-modal-intro">{$modalIntro} {$privacyLink}</p>
            {$categoriesHtml}
        </div>
        <div class="cc-modal-actions">
            <button type="button" class="cc-btn cc-btn--danger" data-cc-action="modal-reject-all">{$btnReject}</button>
            <button type="button" class="cc-btn cc-btn--outline" data-cc-action="modal-accept-all">{$btnAccept}</button>
            <button type="button" class="cc-btn cc-btn--primary" data-cc-action="save-modal">{$btnSave}</button>
        </div>
    </div>

</div>
HTML;
}

/**
 * Returns the inline <style> tag with the palette variables (colors are
 * user-configurable, so they cannot live in the static CSS file), plus
 * the <link> and <script> tags for the plugin's own assets and the JS
 * config bridge.
 */
function cc_render_frontend_assets(): string
{
    $settings    = cc_load_settings();
    $expDays     = (int)($settings['expiration_days'] ?? 180);
    if ($expDays < 1 || $expDays > 3650) $expDays = 180;

    $bg      = cc_sanitize_hex($settings['bg_color']     ?? '#ffffff', '#ffffff');
    $text    = cc_sanitize_hex($settings['text_color']   ?? '#1a1a1a', '#1a1a1a');
    $accent  = cc_sanitize_hex($settings['accent_color'] ?? '#3ea34e', '#3ea34e');

    $categoriesEnabled = [];
    if (!empty($settings['category_analytics'])) $categoriesEnabled[] = 'analytics';
    if (!empty($settings['category_marketing'])) $categoriesEnabled[] = 'marketing';

    $cssUrl = htmlspecialchars(cc_asset_url('assets/css/cookie-consent.css'));
    $jsUrl  = htmlspecialchars(cc_asset_url('assets/js/cookie-consent.js'));

    // Inline palette override — a tiny <style> block is cheaper than a
    // separate PHP-CSS endpoint and avoids an extra HTTP round-trip.
    $paletteCss = <<<CSS
:root{--cc-bg:{$bg};--cc-text:{$text};--cc-accent:{$accent};}
CSS;

    $jsConfig = json_encode([
        'expirationDays'    => $expDays,
        'categoriesEnabled' => $categoriesEnabled,
        'storageKey'        => 'synaptik_cookie_consent',
        'consentVersion'    => 1,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return <<<HTML
<link rel="stylesheet" href="{$cssUrl}">
<style>{$paletteCss}</style>
<script type="application/json" data-window-var="CC_CONFIG">{$jsConfig}</script>
<script src="{$jsUrl}" defer></script>
HTML;
}

/**
 * Validates a #RRGGBB color string. Falls back to $default on any
 * malformed input rather than injecting user-supplied text into a
 * <style> block.
 */
function cc_sanitize_hex(string $value, string $default): string
{
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : $default;
}

/**
 * Resolves a stored URL (as entered by the admin) into a browser-usable
 * absolute URL. Full https:// / http:// URLs pass through unchanged;
 * bare paths (e.g. "/privacy-policy") are prefixed with the site's base
 * URL so they resolve correctly when the CMS lives in a sub-directory
 * (e.g. localhost:8888/synaptikcms/). Without this, the browser would resolve
 * a bare "/privacy-policy" href against the domain root and silently
 * drop the /synaptikcms/ segment — same failure mode fixed in the Redirects
 * plugin's rd_resolve_redirect_target().
 */
function cc_resolve_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    return rtrim(cc_site_base_url(), '/') . '/' . ltrim($url, '/');
}

// ─── Admin URL helper ─────────────────────────────────────────────────────

/**
 * Absolute URL of the plugin's admin page (core admin/index.php with the
 * plugin_page router). Absolute path because actions.php lives at a
 * different depth from admin/index.php.
 */
function cc_admin_page_url(array $extraParams = []): string
{
    $params = array_merge(['action' => 'plugin_page', 'slug' => 'cookie-consent', 'view' => 'dashboard'], $extraParams);

    $settingsFile = dirname(__DIR__, 2) . '/config.json';
    $adminDir     = 'admin';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['admin_dir'])) {
            $adminDir = $decoded['admin_dir'];
        }
    }

    return cc_site_base_url() . $adminDir . '/index.php?' . http_build_query($params);
}

function cc_admin_actions_url(): string
{
    return cc_plugin_base_url() . 'admin/actions.php';
}

// ─── Shortcode ───────────────────────────────────────────────────────────

/**
 * Returns the HTML for the [cookie_settings] shortcode: a plain text link
 * that opens the settings modal when clicked. Intended for the site's
 * footer text, a menu item, or the privacy policy page — gives the
 * visitor a persistent way to change or withdraw their consent, as
 * required by GDPR, without imposing a floating button on the theme's UI.
 *
 * Two rendering modes:
 * - $assetsSkipped = false (default): the banner JS is present on this
 *   page, so the click can call SynaptikCookies.openSettings() directly
 *   via a delegated inline handler.
 * - $assetsSkipped = true: the visitor has already consented and the
 *   plugin skipped injecting its assets on this page, so window
 *   .SynaptikCookies does not exist. The link instead navigates to
 *   ?cc_open=1, which the PHP init code sees and uses to force a fresh
 *   injection with a CC_AUTO_OPEN flag — the JS then auto-opens the
 *   modal on boot (see boot() in cookie-consent.js).
 */
function cc_render_settings_link_html(bool $assetsSkipped = false): string
{
    $label = htmlspecialchars(cc_t('cc_settings_link_label', 'Cookie settings'));

    if ($assetsSkipped) {
        // Preserve any existing query string on the current URL when
        // appending ?cc_open=1 — stripping utm_ / referral params on a
        // Cookie settings click would be a surprising side effect. The
        // href is a static fallback; front-boot.js's delegated
        // .cc-open-settings handler (data-cc-fallback) merges it properly
        // for JS-enabled visitors.
        return '<a href="?cc_open=1" class="cc-open-settings" data-cc-fallback>' . $label . '</a>';
    }

    return '<a href="#" class="cc-open-settings">' . $label . '</a>';
}
