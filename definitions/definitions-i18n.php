<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

if (defined('DF_I18N_LOADED')) return;
define('DF_I18N_LOADED', true);

function df_current_locale(string $context = 'front'): string
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

function df_load_strings(string $context = 'front'): array
{
    $cacheKey = '_df_strings_' . $context;
    if (isset($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }

    $locale = df_current_locale($context);
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

function df_t(string $key, string $fallback = '', string $context = 'front'): string
{
    $strings = df_load_strings($context);
    return $strings[$key] ?? ($fallback !== '' ? $fallback : $key);
}
