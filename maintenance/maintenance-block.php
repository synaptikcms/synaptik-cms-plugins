<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Maintenance Plugin — Front-End Block
 *
 * mt_maybe_block() is registered on the core's generic 'early_request'
 * hook (see maintenance-init.php and plugin-api.php), fired from
 * index.php as early as possible — right after functions.php loads,
 * before the data layer or routing run — so a blocked visitor never
 * pays the cost of loading site content at all.
 *
 * A logged-in admin (same $_SESSION['admin'] flag the core itself uses)
 * always passes through untouched, so they can keep working on the site
 * while everyone else sees the maintenance page.
 */

if (defined('MT_BLOCK_LOADED')) return;
define('MT_BLOCK_LOADED', true);

/**
 * Paths that must always be reachable, even in maintenance mode — the
 * admin panel itself (so the admin can log in and turn the mode back off)
 * and any plugin's own public endpoint (mirrors the exemption pattern used
 * by the Redirects plugin, for the same reason: a broad block must never
 * lock out the tools needed to undo it).
 */
function mt_is_exempt_path(string $requestUri): bool
{
    static $exemptPrefixes = null;

    if ($exemptPrefixes === null) {
        $settingsFile = dirname(__DIR__, 2) . '/config.json';
        $adminDir     = 'admin';
        if (file_exists($settingsFile)) {
            $decoded = json_decode(file_get_contents($settingsFile), true);
            // Validated against the same charset install.php itself enforces
            // for admin_dir — an unexpected value here must fall back to the
            // default rather than silently widen (or corrupt) the exemption
            // prefix built from it below.
            if (is_array($decoded) && !empty($decoded['admin_dir'])
                && preg_match('/^[a-z0-9_-]+$/i', $decoded['admin_dir'])) {
                $adminDir = $decoded['admin_dir'];
            }
        }
        $exemptPrefixes = ['/' . trim($adminDir, '/') . '/', '/plugins/'];
    }

    $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';

    // Strip the install sub-directory (e.g. '/synaptikcms') so the check below is
    // a true "starts with", not a substring match anywhere in the path — a
    // category or tag slug literally named "admin" or "plugins" must never
    // be treated as the real admin/plugins folder (see getBaseUrl() for the
    // same CMS_ROOT-vs-DOCUMENT_ROOT technique this mirrors).
    if (function_exists('getBaseUrl')) {
        $baseSubDir = rtrim((string)parse_url(getBaseUrl(), PHP_URL_PATH), '/');
        if ($baseSubDir !== '' && strpos($path, $baseSubDir) === 0) {
            $path = substr($path, strlen($baseSubDir));
            if ($path === '') $path = '/';
        }
    }

    foreach ($exemptPrefixes as $prefix) {
        if (strpos($path, $prefix) === 0) return true;
    }

    // Static assets (CSS/JS/images/fonts) always pass through — the
    // maintenance page itself needs the theme's stylesheet to render, and
    // there is no meaningful "content" to protect in a static file anyway.
    // Matched against a real extension whitelist, not "ends in 1-5 chars
    // after a dot" — that pattern also matched ordinary content slugs like
    // /articles/version-1.2/ or /what-is-node.js/.
    static $assetExtensions = ['css','js','mjs','png','jpg','jpeg','gif','webp','avif','svg','ico','woff','woff2','ttf','otf','eot','map'];
    $ext = strtolower(pathinfo(rtrim($path, '/'), PATHINFO_EXTENSION));
    if ($ext !== '' && in_array($ext, $assetExtensions, true)) {
        return true;
    }

    return false;
}

/**
 * Renders the maintenance page and stops execution entirely. Deliberately
 * self-contained HTML (no theme header/footer) — a maintenance page must
 * still render correctly even if the reason for maintenance is a broken
 * theme file.
 *
 * Colors and an optional logo are read directly from settings and inlined
 * as CSS custom properties, keeping the page fully self-contained (no
 * external stylesheet request) while still being admin-customizable.
 */
function mt_render_and_exit(string $message): void
{
    http_response_code(503);
    header('Retry-After: 3600');

    $settings = mt_load_settings();
    $defaults = mt_default_settings();

    $pageTitle = $settings['title'] !== '' ? $settings['title'] : mt_t('mt_page_title', 'Under maintenance');
    $title = hsc($pageTitle);
    $safeMessage = nl2br(hsc($message));

    $bgColor     = hsc($settings['bg_color']     ?: $defaults['bg_color']);
    $textColor   = hsc($settings['text_color']   ?: $defaults['text_color']);
    $accentColor = hsc($settings['accent_color'] ?: $defaults['accent_color']);

    // Logo, if configured, replaces the default wrench icon entirely — sized
    // via object-fit so any source image (any dimensions, any aspect ratio)
    // fits a consistent frame without distortion or a separate crop step.
    $logoPath = $settings['logo_image'] ?? '';
    if ($logoPath !== '') {
        $logoUrl = hsc(mt_site_base_url() . ltrim($logoPath, '/'));
        $iconHtml = '<img class="mt-icon mt-icon--logo" src="' . $logoUrl . '" alt="">';
    } else {
        $iconHtml = '<svg class="mt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>';
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<meta name="robots" content="noindex">'
       . '<title>' . $title . '</title>'
       . '<style>'
       . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'background:' . $bgColor . ';color:' . $textColor . ';font-family:system-ui,-apple-system,sans-serif;padding:24px;box-sizing:border-box;}'
       . '.mt-card{max-width:480px;text-align:center;}'
       . '.mt-icon{width:56px;height:56px;margin:0 auto 20px;color:' . $accentColor . ';}'
       . '.mt-icon--logo{width:auto;height:auto;max-width:240px;max-height:120px;object-fit:contain;}'
       . 'h1{font-size:1.4rem;margin:0 0 12px;color:' . $textColor . ';}'
       . 'p{font-size:1rem;line-height:1.6;color:' . $textColor . ';opacity:0.75;margin:0;}'
       . '</style></head><body>'
       . '<div class="mt-card">'
       . $iconHtml
       . '<h1>' . $title . '</h1>'
       . '<p>' . $safeMessage . '</p>'
       . '</div></body></html>';
    exit;
}

/**
 * Entry point registered on the 'early_request' hook by maintenance-init.php.
 * No-op unless maintenance mode is enabled; logged-in admins and exempt
 * paths always pass through.
 */
function mt_maybe_block(): void
{
    $settings = mt_load_settings();
    if (empty($settings['enabled'])) return;

    if (mt_is_exempt_path($_SERVER['REQUEST_URI'] ?? '/')) return;

    // Same session flag the core itself sets on admin login — an admin
    // browsing the front end while logged in always sees the real site.
    // Mirrors core's admin_is_logged_in() 2-hour inactivity timeout (this
    // hook fires before admin-functions.php loads, so it can't call that
    // function directly) — otherwise a stale admin session would bypass
    // maintenance mode indefinitely instead of expiring like it does
    // everywhere else in the admin panel.
    if (isset($_SESSION['admin']) && $_SESSION['admin'] === true) {
        $expired = isset($_SESSION['admin_last_activity'])
            && (time() - $_SESSION['admin_last_activity']) > 7200;
        if (!$expired) return;
    }

    $message = !empty($settings['message']) ? $settings['message'] : mt_t('mt_default_message', mt_default_settings()['message']);
    mt_render_and_exit($message);
}
