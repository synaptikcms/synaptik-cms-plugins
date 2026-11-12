<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

if (defined('VPB_I18N_LOADED')) return;
define('VPB_I18N_LOADED', true);

function vpb_admin_locale(): string
{
    $config = vpb_config();
    $locale = (string)($config['admin_language'] ?? $config['active_language'] ?? 'en');
    return preg_match('/^[a-z]{2}$/', $locale) ? $locale : 'en';
}

function vpb_read_strings(string $dir, string $locale): array
{
    foreach ([$locale, 'en'] as $code) {
        $path = $dir . '/' . $code . '.json';
        if (is_file($path)) {
            $decoded = json_decode((string)@file_get_contents($path), true);
            return is_array($decoded) ? $decoded : [];
        }
    }
    return [];
}

function vpb_admin_strings(): array
{
    if (isset($GLOBALS['_vpb_admin_strings'])) {
        return $GLOBALS['_vpb_admin_strings'];
    }
    $locale  = vpb_admin_locale();
    $strings = vpb_read_strings(__DIR__ . '/lang/admin', $locale);
    $theme   = vpb_read_strings(vpb_theme_dir() . '/builder/lang', $locale);
    return $GLOBALS['_vpb_admin_strings'] = $theme + $strings;
}

function vpb_theme_text($value): string
{
    if (is_array($value)) {
        foreach ([vpb_admin_locale(), 'en', vpb_default_lang()] as $code) {
            if (isset($value[$code]) && is_string($value[$code]) && $value[$code] !== '') return $value[$code];
        }
        $first = reset($value);
        return is_string($first) ? $first : '';
    }
    return is_string($value) ? $value : '';
}

function vpb_t(string $key, string $fallback = ''): string
{
    $strings = vpb_admin_strings();
    return $strings[$key] ?? ($fallback !== '' ? $fallback : $key);
}
