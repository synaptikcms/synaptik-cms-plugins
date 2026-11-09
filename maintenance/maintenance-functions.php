<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Maintenance Plugin — Core Functions
 *
 * A single JSON file holds the whole plugin state: whether maintenance
 * mode is on, and the message shown to visitors. No index, no split-file
 * layer needed — this is the simplest possible plugin data shape.
 */

if (defined('MT_FUNCTIONS_LOADED')) return;
define('MT_FUNCTIONS_LOADED', true);

function mt_data_dir(): string
{
    return __DIR__ . '/data';
}

function mt_settings_path(): string
{
    return mt_data_dir() . '/config.json';
}

/**
 * Returns the site's base URL with a trailing slash, correctly accounting
 * for the CMS living in a sub-directory rather than the domain root.
 *
 * Deliberately independent of the core's getBaseUrl(): mt_maybe_block()
 * runs on the 'early_request' hook, fired as early as possible in
 * index.php (right after functions.php loads, before routing) — too early
 * to safely assume every core helper is in a usable state. The CMS root is
 * derived from this plugin file's own filesystem position instead
 * (plugins/maintenance/maintenance-functions.php, two levels below the CMS
 * root) relative to DOCUMENT_ROOT, which needs nothing from the core.
 */
function mt_site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    $host = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $cmsRootFilesystemPath = dirname(__DIR__, 2); // plugins/maintenance/ -> plugins/ -> CMS root
    $documentRoot          = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $cmsRootFilesystemPath = str_replace('\\', '/', $cmsRootFilesystemPath);

    if ($documentRoot !== '' && strpos($cmsRootFilesystemPath, $documentRoot) === 0) {
        $urlPath = substr($cmsRootFilesystemPath, strlen($documentRoot));
    } else {
        $urlPath = '/';
    }

    $urlPath = '/' . trim($urlPath, '/');
    if ($urlPath === '/') {
        return $host . '/';
    }
    return $host . $urlPath . '/';
}

function mt_default_settings(): array
{
    return [
        'enabled'      => false,
        'title'        => '',
        'message'      => 'We are currently performing scheduled maintenance. We will be back online shortly.',
        // Empty colors fall back to the built-in dark palette hardcoded in
        // mt_render_and_exit() — see the CSS defaults there.
        'bg_color'     => '#0f172a',
        'text_color'   => '#e2e8f0',
        'accent_color' => '#4fa75c',
        // Relative path under /files/ (e.g. 'files/logo.png'), same shape as
        // the core's site_logo/site_favicon settings. Empty = no logo shown,
        // falls back to the default wrench icon.
        'logo_image'   => '',
    ];
}

/**
 * Ensures the data directory exists with .htaccess protection, same
 * pattern as every other plugin's own data store.
 */
function mt_ensure_dirs(): void
{
    $dir = mt_data_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $content = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
             . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    @file_put_contents($dir . '/.htaccess', $content);
}

function mt_load_settings(): array
{
    if (isset($GLOBALS['_mt_config_cache'])) {
        return $GLOBALS['_mt_config_cache'];
    }

    $settings = mt_default_settings();
    $path     = mt_settings_path();

    if (file_exists($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded)) {
            $settings = array_merge($settings, $decoded);
        }
    }

    $GLOBALS['_mt_config_cache'] = $settings;
    return $settings;
}

/**
 * Atomic write (tmp file + rename) — prevents a partial write from
 * corrupting config.json if the request is interrupted mid-save.
 */
function mt_save_settings(array $settings): bool
{
    mt_ensure_dirs();

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $path = mt_settings_path();
    $tmp  = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;

    $success = rename($tmp, $path);
    if ($success) {
        unset($GLOBALS['_mt_config_cache']);
    }
    return $success;
}

// ─── CSRF (admin form only — no public-facing endpoint in this plugin) ─────
// Stateless HMAC token, same shape/lifetime as the Redirects and Newsletter
// plugins, for consistency and to avoid any dependency on session state.

function mt_private_dir(): string
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

function mt_get_secret(): string
{
    $secretFile = mt_private_dir() . '/maintenance.secret';

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

function mt_generate_csrf(): string
{
    $ts     = time();
    $secret = mt_get_secret();
    $mac    = hash_hmac('sha256', (string)$ts, $secret);
    return $ts . '.' . $mac;
}

function mt_verify_csrf(string $token): bool
{
    if (empty($token) || substr_count($token, '.') !== 1) return false;

    [$ts, $mac] = explode('.', $token, 2);
    if (!is_numeric($ts)) return false;
    if ((time() - (int)$ts) > 7200) return false;

    $expected = hash_hmac('sha256', $ts, mt_get_secret());
    return hash_equals($expected, $mac);
}

// ─── Logo image (upload / library picker) ───────────────────────────────────────
// Mirrors the core admin's own _settings_upload_image() /
// _settings_sanitize_image_path() pattern (admin/settings.php) so the logo
// picker behaves identically to the site logo/favicon fields — same allowed
// types, same destination folder, same collision handling. Duplicated here
// (rather than reused) because those are file-scoped helper functions in
// the core admin, not part of any shared/public API.

/**
 * Uploads an image from $_FILES to the CMS's shared /files/ directory and
 * returns its relative path (files/name.ext). Returns empty string on
 * failure or an unrecognised file type.
 */
function mt_upload_logo_image(string $inputName): string
{
    $file = $_FILES[$inputName] ?? [];
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '';

    $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMime, true)) return '';

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) return '';

    // CMS root is two levels up from this plugin file (plugins/maintenance/ -> plugins/ -> root).
    $filesDir = dirname(__DIR__, 2) . '/files/';
    if (!is_dir($filesDir)) {
        mkdir($filesDir, 0755, true);
    }

    $base     = preg_replace('/[^a-z0-9_\-]/', '-', strtolower(pathinfo($file['name'], PATHINFO_FILENAME)));
    $base     = trim($base, '-') ?: 'maintenance-logo';
    $destName = $base . '.' . $ext;
    $destPath = $filesDir . $destName;
    $i = 1;
    while (file_exists($destPath)) {
        $destName = $base . '-' . $i . '.' . $ext;
        $destPath = $filesDir . $destName;
        $i++;
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) return '';
    return 'files/' . $destName;
}

/**
 * Validates and sanitizes a files/-relative image path submitted from the
 * "browse existing files" picker. Returns empty string if the path fails
 * validation (traversal attempt, wrong prefix, disallowed extension).
 */
function mt_sanitize_logo_path(string $path): string
{
    $path = ltrim(str_replace(['..', "\0"], '', $path), '/');
    if (!preg_match('/^files\/[a-zA-Z0-9_\-\/\.]+\.(jpe?g|png|gif|webp|svg)$/i', $path)) return '';
    return $path;
}
