<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Comments Plugin — i18n
 *
 * Minimal translation loader, isolated from the CMS core's lang-cache system.
 * Reads the site's active_language (front) / admin_language (admin) from the
 * core config.json so the plugin follows the same locale automatically,
 * without writing into the core's /lang/ directories.
 */

if (defined('CM_I18N_LOADED')) return;
define('CM_I18N_LOADED', true);

/**
 * Resolves the locale to use, matching the core CMS logic:
 * admin context → admin_language ?? active_language; front → active_language.
 */
function cm_current_locale(string $context = 'front'): string
{
    $settingsFile = dirname(__DIR__, 2) . '/config.json';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded)) {
            if ($context === 'admin') {
                return $decoded['admin_language'] ?? $decoded['active_language'] ?? 'en';
            }
            return $decoded['active_language'] ?? 'en';
        }
    }
    return 'en';
}

/**
 * Loads the plugin's own locale strings for the given context (front/admin).
 * Falls back to English when the requested locale file is missing, and to
 * the key itself when a string is entirely absent.
 */
function cm_load_strings(string $context = 'front'): array
{
    $cacheKey = '_cm_strings_' . $context;
    if (isset($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }

    $locale = cm_current_locale($context);
    $dir    = __DIR__ . '/lang/' . ($context === 'admin' ? 'admin/' : '');
    $path   = $dir . $locale . '.json';

    if (!file_exists($path)) {
        $path = $dir . 'en.json';
    }

    $strings = [];
    if (file_exists($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded)) {
            $strings = $decoded;
        }
    }

    $GLOBALS[$cacheKey] = $strings;
    return $strings;
}

/**
 * Translates a key for the given context. Returns $fallback (or the key
 * itself) when the string is not found in either the active locale or English.
 */
function cm_t(string $key, string $fallback = '', string $context = 'front'): string
{
    $strings = cm_load_strings($context);
    return $strings[$key] ?? ($fallback !== '' ? $fallback : $key);
}
