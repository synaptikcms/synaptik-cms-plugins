<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Reviews Plugin — Core Functions
 *
 * Settings, URL helpers, and avatar upload handling.
 */

if (defined('RV_FUNCTIONS_LOADED')) return;
define('RV_FUNCTIONS_LOADED', true);

require_once __DIR__ . '/reviews-data-layer.php';

function rv_settings_path(): string
{
    return rv_data_dir() . '/config.json';
}

function rv_default_settings(): array
{
    return [
        'display_style'   => 'carousel', // 'carousel' | 'grid' — used when a shortcode omits the style attribute
        'default_limit'   => 10,
        'show_source_tag' => false, // reserved: shown once a non-manual source exists
    ];
}

function rv_load_settings(): array
{
    if (isset($GLOBALS['_rv_config_cache'])) {
        return $GLOBALS['_rv_config_cache'];
    }

    $settings = rv_default_settings();
    $path     = rv_settings_path();

    if (file_exists($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded)) {
            $settings = array_merge($settings, $decoded);
        }
    }

    $GLOBALS['_rv_config_cache'] = $settings;
    return $settings;
}

function rv_save_settings(array $settings): bool
{
    rv_ensure_dirs();
    $success = _rv_write_json(rv_settings_path(), $settings);
    if ($success) {
        unset($GLOBALS['_rv_config_cache']);
    }
    return $success;
}

/**
 * Returns the site's base URL with a trailing slash, correctly accounting
 * for the CMS living in a sub-directory rather than the domain root.
 *
 * Derived from this file's own filesystem position relative to
 * DOCUMENT_ROOT rather than the core's getBaseUrl() — this plugin's admin
 * assets are served through admin/index.php (SCRIPT_NAME resolves fine
 * there), but avatar uploads and the front-end shortcode may run from
 * contexts where that assumption doesn't hold, so the same
 * filesystem-relative approach used by the other plugins is kept here for
 * consistency.
 */
function rv_site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    $host = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $cmsRootFilesystemPath = dirname(__DIR__, 2); // plugins/reviews/ -> plugins/ -> CMS root
    $documentRoot          = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $cmsRootFilesystemPath = str_replace('\\', '/', $cmsRootFilesystemPath);

    if ($documentRoot !== '' && strpos($cmsRootFilesystemPath, $documentRoot) === 0) {
        $urlPath = substr($cmsRootFilesystemPath, strlen($documentRoot));
    } else {
        $urlPath = function_exists('getBaseUrl') ? parse_url(getBaseUrl(), PHP_URL_PATH) : '/';
    }

    $urlPath = '/' . trim($urlPath, '/');
    if ($urlPath === '/') {
        return $host . '/';
    }
    return $host . $urlPath . '/';
}

function rv_plugin_base_url(): string
{
    return rv_site_base_url() . 'plugins/reviews/';
}

// ─── Avatar uploads ─────────────────────────────────────────────────────────

function rv_uploads_dir(): string
{
    return __DIR__ . '/assets/uploads';
}

function rv_ensure_uploads_dir(): void
{
    $dir = rv_uploads_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "<FilesMatch \"\\.(php|phtml|phar|pht|php[0-9]|cgi|pl|py|sh|htm|html|svg)$\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n    <IfModule !mod_authz_core.c>\n        Deny from all\n    </IfModule>\n</FilesMatch>\n");
    }
}

/**
 * Validates and stores an uploaded avatar image, returning the stored
 * filename on success or null on any failure (caller falls back to no
 * avatar — an optional field, never worth failing the whole review save
 * over).
 *
 * MIME type is verified via fileinfo against the actual file content, not
 * the client-supplied Content-Type header — mirrors the core media
 * manager's own upload validation.
 */
function rv_handle_avatar_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;

    $maxSize = 2 * 1024 * 1024; // 2 MB — an avatar has no legitimate reason to be larger
    if (($file['size'] ?? 0) > $maxSize || ($file['size'] ?? 0) === 0) return null;

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) return null;

    rv_ensure_uploads_dir();

    $filename = rv_generate_id() . '.' . $allowed[$mime];
    $destination = rv_uploads_dir() . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) return null;

    return $filename;
}

/**
 * Returns the public URL for a stored avatar filename, or an empty string
 * if none is set.
 */
function rv_avatar_url(string $filename): string
{
    if ($filename === '') return '';
    return rv_plugin_base_url() . 'assets/uploads/' . rawurlencode($filename);
}
