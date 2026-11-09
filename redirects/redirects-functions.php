<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Redirects Plugin — Core Functions
 *
 * Settings, path normalization, and the matching/redirect logic called
 * from redirects-init.php on every front-end request.
 */

if (defined('RD_FUNCTIONS_LOADED')) return;
define('RD_FUNCTIONS_LOADED', true);

require_once __DIR__ . '/redirects-data-layer.php';

function rd_settings_path(): string
{
    return __DIR__ . '/data/config.json';
}

function rd_default_settings(): array
{
    return [
        // When true, a request that resolves to a genuine 404 (no manual
        // redirect matched either) is sent to the homepage instead of
        // rendering the theme's 404.php. Always 302 — see rd_maybe_redirect().
        'fallback_404_home' => false,
    ];
}

function rd_load_settings(): array
{
    if (isset($GLOBALS['_rd_config_cache'])) {
        return $GLOBALS['_rd_config_cache'];
    }

    $settings = rd_default_settings();
    $path     = rd_settings_path();

    if (file_exists($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded)) {
            $settings = array_merge($settings, $decoded);
        }
    }

    $GLOBALS['_rd_config_cache'] = $settings;
    return $settings;
}

function rd_save_settings(array $settings): bool
{
    rd_ensure_dirs();
    $success = _rd_write_json(rd_settings_path(), $settings);
    if ($success) {
        unset($GLOBALS['_rd_config_cache']);
    }
    return $success;
}

/**
 * Normalizes a path for storage and comparison: strips scheme/host/query
 * if a full URL was pasted in by mistake, ensures a single leading slash
 * and a single trailing slash (matching cleanUrl()'s own convention), and
 * collapses any double slashes.
 *
 * Both the "from" path typed in the admin form and the incoming request
 * path are run through this same function, so a match is a plain string
 * comparison — no regex, no trailing-slash edge cases at lookup time.
 */
function rd_normalize_path(string $path): string
{
    // Strip scheme + host if a full URL was pasted (e.g. "https://site.com/foo")
    $parsedPath = parse_url($path, PHP_URL_PATH);
    $path = $parsedPath !== null ? $parsedPath : $path;

    $path = trim($path);
    $path = '/' . trim($path, '/');
    $path = preg_replace('#/+#', '/', $path);

    // Root path stays "/"; everything else gets a trailing slash to match
    // the CMS's own URL convention (cleanUrl() always trails with '/').
    if ($path !== '/') {
        $path = rtrim($path, '/') . '/';
    }

    return $path;
}

/**
 * Returns the current request path, normalized, with the CMS's own
 * base directory stripped (same logic as parseRequestUri()) so redirects
 * work identically whether the CMS lives at the domain root or in a
 * sub-directory.
 */
function rd_current_request_path(): string
{
    $uri      = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $basePath = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');

    if ($basePath !== '/' && strpos($uri, $basePath) === 0) {
        $uri = substr($uri, strlen($basePath));
    }

    return rd_normalize_path($uri);
}

/**
 * Paths that must never be redirected, regardless of what an admin
 * configures — guards against a broad or mistaken "from" entry (or the
 * 404 fallback) breaking the admin panel, other plugins, or static assets.
 */
function rd_is_exempt_path(string $normalizedPath): bool
{
    static $exemptPrefixes = null;

    if ($exemptPrefixes === null) {
        $settingsFile = dirname(__DIR__, 2) . '/config.json';
        $adminDir     = 'admin';
        if (file_exists($settingsFile)) {
            $decoded = json_decode(file_get_contents($settingsFile), true);
            if (is_array($decoded) && !empty($decoded['admin_dir'])) {
                $adminDir = $decoded['admin_dir'];
            }
        }
        $exemptPrefixes = ['/' . trim($adminDir, '/') . '/', '/plugins/'];
    }

    foreach ($exemptPrefixes as $prefix) {
        if (strpos($normalizedPath, $prefix) === 0) return true;
    }

    // Static assets and files with an extension (images, CSS, JS, feed,
    // sitemap, etc.) are never redirect targets.
    if (preg_match('#\.[a-z0-9]{1,5}$#i', rtrim($normalizedPath, '/'))) {
        return true;
    }

    return false;
}

/**
 * Resolves a redirect's stored "to" value into the final absolute URL to
 * send in the Location header.
 *
 * External URLs (https://...) pass through unchanged. Internal paths are
 * stored relative (e.g. "/nouvel-article/") and must be prefixed with the
 * site's own base URL before being sent — a bare "Location: /path/" is
 * resolved by the browser against the domain root, not against the CMS's
 * install sub-directory (e.g. /synaptikcms/), which silently drops the site's
 * base path for any install not living at the domain root.
 */
function rd_resolve_redirect_target(string $to): string
{
    if (preg_match('#^https?://#i', $to)) {
        return $to;
    }

    $base = function_exists('getBaseUrl') ? getBaseUrl() : '/';
    return rtrim($base, '/') . $to;
}

/**
 * Registered on the 'after_routing' hook by redirects-init.php — see
 * plugin-api.php. Fired from index.php right after parseRequestUri()
 * resolves the route and before any HTTP header or HTML output.
 *
 * 1. A matching manual redirect always takes priority, 404 or not (e.g.
 *    redirecting a moved-but-still-existing category listing).
 * 2. If nothing matched and $isGenuine404 is true, apply the optional
 *    fallback to the homepage — always 302, since the "old" 404 URL never
 *    genuinely was the homepage and permanently mapping it as one would
 *    be a false signal to search engines.
 *
 * @param bool $isGenuine404 Whether parseRequestUri() resolved to '404'.
 */
function rd_maybe_redirect(bool $isGenuine404): void
{
    $currentPath = rd_current_request_path();

    if (rd_is_exempt_path($currentPath)) {
        return;
    }

    $match = rd_find_by_from($currentPath);
    if ($match !== null) {
        $code = in_array((int)$match['status_code'], [301, 302], true) ? (int)$match['status_code'] : 302;
        header('Location: ' . rd_resolve_redirect_target($match['to'] ?? ''), true, $code);
        exit;
    }

    if ($isGenuine404) {
        $settings = rd_load_settings();
        if (!empty($settings['fallback_404_home'])) {
            $home = function_exists('getBaseUrl') ? getBaseUrl() : '/';
            header('Location: ' . $home, true, 302);
            exit;
        }
    }
}

// ─── CSRF (admin forms only — no public-facing endpoint in this plugin) ────
// Stateless HMAC token, independent of the core's $_SESSION['csrf_token']
// so this plugin has no dependency on session state being initialized in
// whatever order the core admin bootstraps it.

function rd_private_dir(): string
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

function rd_get_secret(): string
{
    $secretFile = rd_private_dir() . '/redirects.secret';

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

/**
 * Generates a stateless CSRF token: "{timestamp}.{HMAC-SHA256}", 2 h TTL —
 * matches the Newsletter plugin's own token shape/lifetime for consistency.
 */
function rd_generate_csrf(): string
{
    $ts     = time();
    $secret = rd_get_secret();
    $mac    = hash_hmac('sha256', (string)$ts, $secret);
    return $ts . '.' . $mac;
}

function rd_verify_csrf(string $token): bool
{
    if (empty($token) || substr_count($token, '.') !== 1) return false;

    [$ts, $mac] = explode('.', $token, 2);
    if (!is_numeric($ts)) return false;
    if ((time() - (int)$ts) > 7200) return false;

    $expected = hash_hmac('sha256', $ts, rd_get_secret());
    return hash_equals($expected, $mac);
}
