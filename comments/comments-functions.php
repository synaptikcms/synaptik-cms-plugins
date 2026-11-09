<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Comments Plugin — Core Functions
 *
 * Plugin settings (moderation mode, enabled content types, blacklist,
 * hCaptcha toggle) and small validation helpers shared by the shortcode,
 * submit endpoint, and admin pages.
 */

if (defined('CM_FUNCTIONS_LOADED')) return;
define('CM_FUNCTIONS_LOADED', true);

require_once __DIR__ . '/comments-data-layer.php';

function cm_settings_path(): string
{
    return __DIR__ . '/data/config.json';
}

function cm_default_settings(): array
{
    return [
        // 'manual' — every new comment starts as 'pending' and must be
        // approved from the admin dashboard before it appears publicly.
        // 'auto'   — comments are stored as 'approved' immediately, unless
        // caught by the spam checks in comments-submit.php.
        'moderation_mode'  => 'manual',
        'enabled_types'    => [
            'article' => true,
            'page'    => false,
            'project' => false,
        ],
        // One keyword/phrase per array entry, matched as a case-insensitive
        // substring against the submitted comment text — see cm_is_spam().
        'blacklist'        => [],
        // Reuses the core's own hCaptcha keys (config.json at the CMS
        // root) rather than storing a second, separate configuration —
        // this only toggles whether the widget is required for comments
        // specifically, on top of whatever the core already has set up.
        'use_hcaptcha'     => false,
        // Whether the pending-comments widget appears on the main admin
        // dashboard (see comments-init.php, 'admin_dashboard' hook).
        'show_dashboard_widget' => true,
    ];
}

function cm_load_settings(): array
{
    if (isset($GLOBALS['_cm_config_cache'])) {
        return $GLOBALS['_cm_config_cache'];
    }

    $settings = cm_default_settings();
    $path     = cm_settings_path();

    if (file_exists($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded)) {
            // array_merge alone would replace 'enabled_types' wholesale if
            // the stored settings ever predate a new type being added —
            // merge that sub-array explicitly so new types default sanely.
            if (isset($decoded['enabled_types']) && is_array($decoded['enabled_types'])) {
                $decoded['enabled_types'] = array_merge($settings['enabled_types'], $decoded['enabled_types']);
            }
            $settings = array_merge($settings, $decoded);
        }
    }

    $GLOBALS['_cm_config_cache'] = $settings;
    return $settings;
}

function cm_save_settings(array $settings): bool
{
    cm_ensure_dirs();
    $success = _cm_write_json(cm_settings_path(), $settings);
    if ($success) {
        unset($GLOBALS['_cm_config_cache']);
    }
    return $success;
}

/**
 * Whether comments are enabled site-wide for a given content type.
 */
function cm_type_enabled(string $type): bool
{
    $settings = cm_load_settings();
    return !empty($settings['enabled_types'][$type]);
}

function cm_is_valid_type(string $type): bool
{
    return in_array($type, ['article', 'page', 'project'], true);
}

/**
 * Reads the core's own hCaptcha secret key from the CMS root config.json.
 * Comments never store their own hCaptcha credentials — see
 * cm_default_settings()'s 'use_hcaptcha' note.
 */
function cm_core_hcaptcha_secret(): string
{
    $settingsFile = dirname(__DIR__, 2) . '/config.json';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded)) {
            return trim($decoded['hcaptcha_secret_key'] ?? '');
        }
    }
    return '';
}

function cm_core_hcaptcha_site_key(): string
{
    $settingsFile = dirname(__DIR__, 2) . '/config.json';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded)) {
            return trim($decoded['hcaptcha_site_key'] ?? '');
        }
    }
    return '';
}

/**
 * Verifies an hCaptcha token server-side. Same call shape as the core
 * contact form's _contact_verify_hcaptcha() — duplicated rather than
 * shared, since the core function is not part of the plugin API surface
 * and plugins must not depend on core internals that could change.
 */
function cm_verify_hcaptcha(string $token, string $secretKey): bool
{
    $url     = 'https://hcaptcha.com/siteverify';
    $payload = http_build_query([
        'secret'   => $secretKey,
        'response' => $token,
        'remoteip' => cm_get_ip(),
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                       . "Content-Length: " . strlen($payload) . "\r\n",
            'content' => $payload,
            'timeout' => 5,
        ],
    ]);

    $result = @file_get_contents($url, false, $ctx);
    if ($result === false) {
        // hCaptcha API unreachable — fail closed (reject), same policy as
        // the core contact form.
        return false;
    }

    $data = json_decode($result, true);
    return isset($data['success']) && $data['success'] === true;
}

// ─── Shared URL helpers (front-end + admin) ──────────────────────────────────────────
// Defined here rather than in admin/admin-page.php: cm_plugin_base_url()
// is needed by the front-end shortcode renderer (comments-shortcode.php,
// to build the submit.php endpoint and asset URLs) as much as by the
// admin pages, and comments-shortcode.php never loads anything under
// admin/ on a genuine front-end request. Keeping these in the file every
// context already requires avoids a load-order dependency between
// comments-init.php's two require_once calls.

/**
 * Returns the site's base URL with a trailing slash, correctly accounting
 * for the CMS living in a sub-directory rather than the domain root.
 *
 * Cannot delegate to the core's getBaseUrl(): that function derives the
 * path from $_SERVER['SCRIPT_NAME'], which is only correct when the
 * currently-executing script is the CMS root's index.php — wrong when
 * called from comments-submit.php, admin/actions.php, or the
 * plugin_page-rendered admin view, each at a different depth. Derived
 * instead from this file's own filesystem position relative to
 * DOCUMENT_ROOT — matches the Redirects plugin's identical helper.
 */
function cm_site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    $host = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $cmsRootFilesystemPath = dirname(__DIR__, 2); // comments/ -> plugins/ -> CMS root
    $documentRoot          = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $cmsRootFilesystemPath = str_replace('\\', '/', $cmsRootFilesystemPath);

    // realpath() resolves symlinks on both sides before comparing — see
    // the Redirects plugin's rd_site_base_url() for why this matters on
    // hosts where DOCUMENT_ROOT and the plugin's own __DIR__ can each
    // contain a different symlinked alias to the same physical directory.
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
        error_log(sprintf(
            '[Comments] cm_site_base_url() could not match DOCUMENT_ROOT against the plugin path — falling back to getBaseUrl(). cmsRootFilesystemPath=%s documentRoot=%s realCmsRoot=%s realDocRoot=%s fallbackUrlPath=%s',
            $cmsRootFilesystemPath,
            $documentRoot,
            $realCmsRoot,
            $realDocRoot,
            (string)$urlPath
        ));
    }

    $urlPath = '/' . trim($urlPath, '/');
    if ($urlPath === '/') {
        return $host . '/';
    }
    return $host . $urlPath . '/';
}

/**
 * Base URL for the plugin's own folder (CMS root + 'plugins/comments/').
 * Use for the plugin's static assets and its submit.php endpoint.
 */
function cm_plugin_base_url(): string
{
    return cm_site_base_url() . 'plugins/comments/';
}

/**
 * Cache-busted URL to one of the plugin's own assets.
 */
function cm_plugin_asset_url(string $relativePath): string
{
    $absPath = __DIR__ . '/' . $relativePath;
    $v       = @filemtime($absPath) ?: time();

    return cm_plugin_base_url() . $relativePath . '?v=' . $v;
}
