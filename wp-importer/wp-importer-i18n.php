<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * WordPress Importer — i18n helper
 */

if (defined('WPI_I18N_LOADED')) return;
define('WPI_I18N_LOADED', true);

/**
 * Returns a translated string from the plugin's own locale files.
 * Falls back to $fallback if the key is not found.
 *
 * @param string $key
 * @param string $fallback
 * @param string $context  'front' or 'admin'
 */
function wpi_t(string $key, string $fallback = '', string $context = 'front'): string
{
    static $cache = [];

    $locale = wpi_current_locale($context);

    if (!isset($cache[$context][$locale])) {
        $path = __DIR__ . '/lang/' . ($context === 'admin' ? 'admin/' : '') . $locale . '.json';
        if (!file_exists($path)) {
            $path = __DIR__ . '/lang/' . ($context === 'admin' ? 'admin/' : '') . 'en.json';
        }
        $raw = file_exists($path) ? file_get_contents($path) : false;
        $cache[$context][$locale] = ($raw !== false) ? (json_decode($raw, true) ?: []) : [];
    }

    return $cache[$context][$locale][$key] ?? $fallback;
}

/**
 * Returns the active locale for the given context, read from config.json.
 */
function wpi_current_locale(string $context = 'front'): string
{
    static $locales = [];

    if (isset($locales[$context])) return $locales[$context];

    $configFile = dirname(__DIR__, 2) . '/config.json';
    if (file_exists($configFile)) {
        $decoded = json_decode(file_get_contents($configFile), true);
        if (is_array($decoded)) {
            if ($context === 'admin' && !empty($decoded['admin_language'])) {
                return $locales[$context] = $decoded['admin_language'];
            }
            if (!empty($decoded['active_language'])) {
                return $locales[$context] = $decoded['active_language'];
            }
        }
    }

    return $locales[$context] = 'en';
}
