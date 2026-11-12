<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

if (defined('DF_DATA_LAYER_LOADED')) return;
define('DF_DATA_LAYER_LOADED', true);

function df_data_dir(): string
{
    return __DIR__ . '/data';
}

function df_terms_path(): string
{
    return df_data_dir() . '/definitions.json';
}

function df_settings_path(): string
{
    return df_data_dir() . '/settings.json';
}

function df_default_settings(): array
{
    return [
        'wrap_all_by_default'     => false,
        'max_occurrences_default' => 1,
        'skip_headings'           => false,
    ];
}

function df_load_settings(): array
{
    if (isset($GLOBALS['_df_settings_cache'])) {
        return $GLOBALS['_df_settings_cache'];
    }

    $settings = df_default_settings();
    $path     = df_settings_path();

    if (file_exists($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded)) {
            $settings = array_merge($settings, $decoded);
        }
    }

    $GLOBALS['_df_settings_cache'] = $settings;
    return $settings;
}

function df_save_settings(array $settings): bool
{
    df_ensure_dirs();
    $success = _df_write_json(df_settings_path(), $settings);
    if ($success) {
        unset($GLOBALS['_df_settings_cache']);
    }
    return $success;
}

function df_ensure_dirs(): void
{
    $dir = df_data_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        $content = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                 . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        @file_put_contents($htaccess, $content);
    }
}

function _df_write_json(string $path, $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;

    return rename($tmp, $path);
}

function _df_read_json(string $path): array
{
    if (!file_exists($path)) return [];
    $raw     = file_get_contents($path);
    $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function df_load_terms(): array
{
    if (isset($GLOBALS['_df_terms_cache'])) {
        return $GLOBALS['_df_terms_cache'];
    }
    $result = _df_read_json(df_terms_path());
    $GLOBALS['_df_terms_cache'] = $result;
    return $result;
}

function df_write_terms(array $terms): bool
{
    df_ensure_dirs();
    $success = _df_write_json(df_terms_path(), $terms);
    if ($success) {
        unset($GLOBALS['_df_terms_cache']);
    }
    return $success;
}

function df_generate_id(): string
{
    return bin2hex(random_bytes(8));
}

function df_load_terms_sorted(): array
{
    $all = df_load_terms();
    uasort($all, fn($a, $b) => strcasecmp($a['term'] ?? '', $b['term'] ?? ''));
    return $all;
}

function df_get_term(string $id): ?array
{
    $all = df_load_terms();
    return $all[$id] ?? null;
}

function df_create_term(string $term, string $definition, bool $enabled, array $variants = [], bool $wrapAll = false, int $maxOccurrences = 1): string
{
    $terms = df_load_terms();
    $id = df_generate_id();

    $terms[$id] = [
        'term'            => $term,
        'definition'      => $definition,
        'enabled'         => $enabled,
        'variants'        => $variants,
        'wrap_all'        => $wrapAll,
        'max_occurrences' => $maxOccurrences,
        'created_at'      => date('Y-m-d H:i:s'),
    ];

    df_write_terms($terms);
    return $id;
}

function df_update_term(string $id, string $term, string $definition, bool $enabled, array $variants = [], bool $wrapAll = false, int $maxOccurrences = 1): bool
{
    $terms = df_load_terms();
    if (!isset($terms[$id])) return false;

    $terms[$id]['term']            = $term;
    $terms[$id]['definition']      = $definition;
    $terms[$id]['enabled']         = $enabled;
    $terms[$id]['variants']        = $variants;
    $terms[$id]['wrap_all']        = $wrapAll;
    $terms[$id]['max_occurrences'] = $maxOccurrences;

    return df_write_terms($terms);
}

function df_delete_term(string $id): bool
{
    $terms = df_load_terms();
    if (!isset($terms[$id])) return true;

    unset($terms[$id]);
    return df_write_terms($terms);
}

function df_term_exists(string $term, string $excludeId = ''): bool
{
    foreach (df_load_terms() as $id => $record) {
        if ($id === $excludeId) continue;
        if (strcasecmp($record['term'] ?? '', $term) === 0) return true;
    }
    return false;
}

function df_load_enabled_terms(): array
{
    return array_filter(df_load_terms(), fn($record) => !empty($record['enabled']) && trim($record['term'] ?? '') !== '');
}

// ─── Render cache ────────────────────────────────────────────────────────
// Term-wrapping (tokenizing the whole <body> and regex-matching every
// enabled term) is the one operation this plugin runs on every front-end
// request, cache hit or miss — see df_render_page() in
// definitions-functions.php for why. Caching its result keyed on a hash of
// the raw input HTML + the current term list means a request for content
// this exact combination has already produced is a file read, not a
// re-scan of the whole page.

const DF_RENDER_CACHE_MAX_FILES = 500;

function df_render_cache_dir(): string
{
    return df_data_dir() . '/render-cache';
}

function df_ensure_render_cache_dir(): void
{
    $dir = df_render_cache_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        $content = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                 . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        @file_put_contents($htaccess, $content);
    }
}

function df_render_cache_get(string $key): ?string
{
    $path = df_render_cache_dir() . '/' . $key . '.html';
    if (!file_exists($path)) return null;

    $content = file_get_contents($path);
    return $content !== false ? $content : null;
}

function df_render_cache_set(string $key, string $html): void
{
    df_ensure_render_cache_dir();
    $dir = df_render_cache_dir();

    $entries = @scandir($dir);
    if ($entries !== false && (count($entries) - 2) >= DF_RENDER_CACHE_MAX_FILES) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess') continue;
            @unlink($dir . '/' . $entry);
        }
    }

    $path = $dir . '/' . $key . '.html';
    $tmp  = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $html, LOCK_EX) !== false) {
        rename($tmp, $path);
    }
}
