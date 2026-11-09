<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Form Builder Plugin — Core Functions
 *
 * Site/plugin base URLs and sender address resolution, shared by the
 * shortcode renderer, the submission handler, and the admin pages.
 */

if (defined('FB_FUNCTIONS_LOADED')) return;
define('FB_FUNCTIONS_LOADED', true);

/**
 * Returns the site's base URL with a trailing slash, correctly accounting
 * for the CMS living in a sub-directory rather than the domain root.
 * Derived from this file's own filesystem position relative to
 * DOCUMENT_ROOT, so it stays correct regardless of which script in the
 * plugin is currently executing (see booking-functions.php for the same
 * reasoning in more detail).
 */
function fb_site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    $host = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $cmsRootFilesystemPath = dirname(__DIR__, 2); // plugins/formbuilder/ -> plugins/ -> CMS root
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

/**
 * Base URL of the plugin's own folder (CMS base + 'plugins/formbuilder/').
 */
function fb_plugin_base_url(): string
{
    return fb_site_base_url() . 'plugins/formbuilder/';
}

/**
 * Reads config.json directly (same approach as fb_current_locale) to avoid
 * loading the full CMS core from a standalone script context.
 */
function fb_core_config(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $path = dirname(__DIR__, 2) . '/config.json';
    $cache = file_exists($path) ? (json_decode(file_get_contents($path), true) ?? []) : [];
    return $cache;
}

/**
 * Resolves the "From" address/name for outgoing emails, falling back to
 * noreply@{host} and the site title when not explicitly configured.
 */
function fb_from_address(): array
{
    $settings = fb_load_settings();
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $email = $settings['from_email'] !== '' ? $settings['from_email'] : ('noreply@' . $host);

    $name = $settings['from_name'];
    if ($name === '') {
        $coreConfig = fb_core_config();
        $name = $coreConfig['site_title'] ?? 'Form Builder';
    }

    return ['email' => $email, 'name' => $name];
}

/**
 * Strips CR/LF from any value going into a mail header — prevents header
 * injection. Same guard used across the core and every other plugin.
 */
function fb_sanitize_header(string $value): string
{
    return str_replace(["\r", "\n", "\r\n"], '', $value);
}

function fb_get_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
