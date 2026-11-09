<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Analytics Plugin — i18n
 *
 * Isolated translation loader. Follows the site's active_language /
 * admin_language from config.json without touching the CMS core's lang system.
 */

if (defined('AN_I18N_LOADED')) return;
define('AN_I18N_LOADED', true);

function an_current_locale(string $context = 'front'): string
{
    $file = dirname(__DIR__, 2) . '/config.json';
    if (file_exists($file)) {
        $d = json_decode(file_get_contents($file), true);
        if (is_array($d)) {
            if ($context === 'admin') {
                return $d['admin_language'] ?? $d['active_language'] ?? 'en';
            }
            return $d['active_language'] ?? 'en';
        }
    }
    return 'en';
}

function an_load_strings(string $context = 'front'): array
{
    $cacheKey = '_an_strings_' . $context;
    if (isset($GLOBALS[$cacheKey])) return $GLOBALS[$cacheKey];

    $locale = an_current_locale($context);
    $dir    = __DIR__ . '/lang/' . ($context === 'admin' ? 'admin/' : '');
    $path   = $dir . $locale . '.json';
    if (!file_exists($path)) $path = $dir . 'en.json';

    $strings = [];
    if (file_exists($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded)) $strings = $decoded;
    }

    $GLOBALS[$cacheKey] = $strings;
    return $strings;
}

function an_t(string $key, string $fallback = '', string $context = 'front'): string
{
    $strings = an_load_strings($context);
    return $strings[$key] ?? ($fallback !== '' ? $fallback : $key);
}
