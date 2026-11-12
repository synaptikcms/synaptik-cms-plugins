<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

if (defined('VPB_FUNCTIONS_LOADED')) return;
define('VPB_FUNCTIONS_LOADED', true);

require_once __DIR__ . '/vpb-i18n.php';

const VPB_MAX_BLOCKS = 80;
const VPB_MAX_ITEMS  = 60;
const VPB_MAX_VERSIONS = 15;
const VPB_DRAFT_TTL = 604800;

function vpb_root(): string
{
    return dirname(__DIR__, 2);
}

function vpb_langs(): array
{
    static $langs = null;
    if ($langs !== null) return $langs;
    $found = [];
    foreach (glob(vpb_root() . '/lang/front/*.json') ?: [] as $file) {
        $code = basename($file, '.json');
        if (preg_match('/^[a-z]{2,3}$/', $code)) $found[] = $code;
    }
    sort($found);
    return $langs = $found ?: ['en'];
}

function vpb_default_lang(): string
{
    $manifest = vpb_manifest();
    $langs    = vpb_langs();
    $wanted   = (string)($manifest['default_lang'] ?? 'en');
    return in_array($wanted, $langs, true) ? $wanted : $langs[0];
}

function vpb_config(): array
{
    if (isset($GLOBALS['_vpb_config'])) {
        return $GLOBALS['_vpb_config'];
    }
    $decoded = json_decode((string)@file_get_contents(vpb_root() . '/config.json'), true);
    return $GLOBALS['_vpb_config'] = is_array($decoded) ? $decoded : [];
}

function vpb_active_lang(): string
{
    $lang = (string)(vpb_config()['active_language'] ?? '');
    return in_array($lang, vpb_langs(), true) ? $lang : vpb_default_lang();
}

function vpb_theme_dir(): string
{
    $theme = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)(vpb_config()['active_theme'] ?? 'default'));
    return vpb_root() . '/theme/' . $theme;
}

function vpb_theme_has_manifest(): bool
{
    return is_file(vpb_theme_dir() . '/builder/manifest.php');
}

function vpb_default_manifest(): array
{
    $t = static fn(string $en, string $fr, string $es): array => ['en' => $en, 'fr' => $fr, 'es' => $es];
    $title = $t('Page title', 'Titre de la page', 'Título de la página');
    $body  = $t('Write your text here. **Bold**, lists and links are supported.', 'Écrivez ici votre texte. Le **gras**, les listes et les liens sont pris en charge.', 'Escriba aquí su texto. Se admiten **negritas**, listas y enlaces.');
    return [
        'generic'       => true,
        'default_lang'  => 'en',
        'page_template' => '',
        'bgs'           => [],
        'targets'       => [],
        'types'         => [],
        'starters'      => [
            'blank' => ['label' => vpb_t('vpb_starter_blank', 'Blank page'), 'description' => vpb_t('vpb_starter_blank_d', 'A title and a text'), 'blocks' => [
                ['type' => 'text', 'v' => ['title' => $title, 'body' => $body]],
            ]],
            'text' => ['label' => vpb_t('vpb_starter_text', 'Text page'), 'description' => vpb_t('vpb_starter_text_d', 'A title, a text and a button'), 'blocks' => [
                ['type' => 'text', 'v' => ['title' => $title, 'body' => $body]],
                ['type' => 'buttons', 'v' => ['label' => $t('Contact us', 'Nous contacter', 'Contáctenos'), 'url' => '/']],
            ]],
            'columns' => ['label' => vpb_t('vpb_starter_columns', 'Three columns'), 'description' => vpb_t('vpb_starter_columns_d', 'A title and three columns of text'), 'blocks' => [
                ['type' => 'columns', 'v' => ['title' => $title, 'columns' => '3', 'items' => [
                    ['title' => $t('First', 'Premier', 'Primero'), 'body' => $body],
                    ['title' => $t('Second', 'Deuxième', 'Segundo'), 'body' => $body],
                    ['title' => $t('Third', 'Troisième', 'Tercero'), 'body' => $body],
                ]]],
            ]],
        ],
    ];
}

function vpb_item_key(string $type, string $slug): string
{
    if ($type === 'page') return vpb_valid_key($slug) ? $slug : '';
    $key = $type . '--' . $slug;
    return in_array($type, ['article', 'project'], true) && vpb_valid_key($key) ? $key : '';
}

function vpb_generic_render_filter($html, $item = null)
{
    static $done = false;
    $type = (string)($GLOBALS['type'] ?? '');
    $allowed = vpb_theme_has_manifest() ? ['article', 'project'] : ['page', 'article', 'project'];
    if ($done || !empty($GLOBALS['_vpb_rendering']) || !is_array($item) || !in_array($type, $allowed, true)) return $html;
    $key = vpb_item_key($type, (string)($item['slug'] ?? ''));
    if ($key === '') return $html;
    $out = vpb_render_layout($key, $item);
    if ($out === null) return $html;
    $done = true;
    return $out;
}

function vpb_manifest(): ?array
{
    if (array_key_exists('_vpb_manifest', $GLOBALS)) {
        return $GLOBALS['_vpb_manifest'];
    }
    $path = vpb_theme_dir() . '/builder/manifest.php';
    $manifest = null;
    if (is_file($path)) {
        $loaded = require $path;
        $manifest = is_array($loaded) && !empty($loaded['types']) ? $loaded : null;
    }
    if ($manifest === null) $manifest = vpb_default_manifest();
    return $GLOBALS['_vpb_manifest'] = vpb_with_core_types($manifest);
}

function vpb_core_types(): array
{
    $anchor = ['k' => 'anchor', 't' => 'slug'];
    $link   = ['k' => 'url', 't' => 'link'];
    return [
        'text' => ['group' => 'basic', 'bg' => true, 'fields' => [
            $anchor,
            ['k' => 'eyebrow', 't' => 'text'],
            ['k' => 'title', 't' => 'text'],
            ['k' => 'body', 't' => 'textarea', 'md' => true],
        ]],
        'columns' => ['group' => 'basic', 'fields' => [
            $anchor,
            ['k' => 'title', 't' => 'text'],
            ['k' => 'columns', 't' => 'select', 'o' => ['2' => '2', '3' => '3']],
            ['k' => 'items', 't' => 'items', 'f' => [
                ['k' => 'title', 't' => 'text'],
                ['k' => 'body', 't' => 'textarea', 'md' => true],
            ]],
        ]],
        'image' => ['group' => 'basic', 'fields' => [
            $anchor,
            ['k' => 'src', 't' => 'image'],
            ['k' => 'alt', 't' => 'text'],
            ['k' => 'caption', 't' => 'text'],
            $link,
            ['k' => 'width', 't' => 'select', 'o' => ['narrow' => 'Narrow', 'normal' => 'Normal', 'wide' => 'Wide', 'full' => 'Full']],
        ]],
        'video' => ['group' => 'basic', 'fields' => [
            $anchor,
            ['k' => 'url', 't' => 'video'],
            ['k' => 'title', 't' => 'text'],
            ['k' => 'caption', 't' => 'text'],
        ]],
        'quote' => ['group' => 'basic', 'fields' => [
            ['k' => 'text', 't' => 'textarea'],
            ['k' => 'author', 't' => 'text'],
            ['k' => 'role', 't' => 'text'],
        ]],
        'buttons' => ['group' => 'basic', 'fields' => [
            ['k' => 'label', 't' => 'text'],
            $link,
            ['k' => 'label2', 't' => 'text'],
            ['k' => 'url2', 't' => 'link'],
        ]],
        'intro' => ['group' => 'hero', 'fields' => [
            $anchor,
            ['k' => 'eyebrow', 't' => 'text'],
            ['k' => 'title', 't' => 'text'],
            ['k' => 'lead', 't' => 'textarea'],
            ['k' => 'cta1_label', 't' => 'text'],
            ['k' => 'cta1_url', 't' => 'link'],
            ['k' => 'cta2_label', 't' => 'text'],
            ['k' => 'cta2_url', 't' => 'link'],
            ['k' => 'src', 't' => 'image'],
            ['k' => 'align', 't' => 'select', 'o' => ['' => 'Left', 'center' => 'Center']],
        ]],
        'media_text' => ['group' => 'content', 'fields' => [
            $anchor,
            ['k' => 'eyebrow', 't' => 'text'],
            ['k' => 'title', 't' => 'text'],
            ['k' => 'body', 't' => 'textarea', 'md' => true],
            ['k' => 'cta1_label', 't' => 'text'],
            ['k' => 'cta1_url', 't' => 'link'],
            ['k' => 'src', 't' => 'image'],
            ['k' => 'alt', 't' => 'text'],
            ['k' => 'reverse', 't' => 'check'],
        ]],
        'features' => ['group' => 'cards', 'fields' => [
            $anchor,
            ['k' => 'eyebrow', 't' => 'text'],
            ['k' => 'title', 't' => 'text'],
            ['k' => 'columns', 't' => 'select', 'o' => ['3' => '3', '2' => '2', '4' => '4']],
            ['k' => 'items', 't' => 'items', 'f' => [
                ['k' => 'icon', 't' => 'text'],
                ['k' => 'title', 't' => 'text'],
                ['k' => 'body', 't' => 'textarea'],
            ]],
        ]],
        'stats' => ['group' => 'cards', 'fields' => [
            $anchor,
            ['k' => 'title', 't' => 'text'],
            ['k' => 'items', 't' => 'items', 'f' => [
                ['k' => 'value', 't' => 'text'],
                ['k' => 'label', 't' => 'text'],
            ]],
        ]],
        'process' => ['group' => 'cards', 'fields' => [
            $anchor,
            ['k' => 'eyebrow', 't' => 'text'],
            ['k' => 'title', 't' => 'text'],
            ['k' => 'items', 't' => 'items', 'f' => [
                ['k' => 'title', 't' => 'text'],
                ['k' => 'body', 't' => 'textarea'],
            ]],
        ]],
        'accordion' => ['group' => 'content', 'fields' => [
            $anchor,
            ['k' => 'eyebrow', 't' => 'text'],
            ['k' => 'title', 't' => 'text'],
            ['k' => 'items', 't' => 'items', 'f' => [
                ['k' => 'q', 't' => 'text'],
                ['k' => 'a', 't' => 'textarea', 'md' => true],
            ]],
        ]],
        'testimonial' => ['group' => 'cards', 'fields' => [
            $anchor,
            ['k' => 'title', 't' => 'text'],
            ['k' => 'items', 't' => 'items', 'f' => [
                ['k' => 'text', 't' => 'textarea'],
                ['k' => 'author', 't' => 'text'],
                ['k' => 'role', 't' => 'text'],
                ['k' => 'src', 't' => 'image'],
            ]],
        ]],
        'pricing_table' => ['group' => 'conversion', 'fields' => [
            $anchor,
            ['k' => 'eyebrow', 't' => 'text'],
            ['k' => 'title', 't' => 'text'],
            ['k' => 'items', 't' => 'items', 'f' => [
                ['k' => 'name', 't' => 'text'],
                ['k' => 'price', 't' => 'text'],
                ['k' => 'period', 't' => 'text'],
                ['k' => 'bullets', 't' => 'list'],
                ['k' => 'cta1_label', 't' => 'text'],
                ['k' => 'cta1_url', 't' => 'link'],
                ['k' => 'highlight', 't' => 'check'],
            ]],
        ]],
        'notice' => ['group' => 'content', 'fields' => [
            $anchor,
            ['k' => 'kind', 't' => 'select', 'o' => ['info' => 'Information', 'tip' => 'Tip', 'warning' => 'Warning', 'danger' => 'Important']],
            ['k' => 'title', 't' => 'text'],
            ['k' => 'body', 't' => 'textarea', 'md' => true],
        ]],
        'cta_box' => ['group' => 'conversion', 'fields' => [
            $anchor,
            ['k' => 'title', 't' => 'text'],
            ['k' => 'text', 't' => 'textarea'],
            ['k' => 'cta1_label', 't' => 'text'],
            ['k' => 'cta1_url', 't' => 'link'],
            ['k' => 'cta2_label', 't' => 'text'],
            ['k' => 'cta2_url', 't' => 'link'],
        ]],
        'gallery' => ['group' => 'media', 'fields' => [
            $anchor,
            ['k' => 'title', 't' => 'text'],
            ['k' => 'columns', 't' => 'select', 'o' => ['3' => '3', '2' => '2', '4' => '4']],
            ['k' => 'items', 't' => 'items', 'f' => [
                ['k' => 'src', 't' => 'image'],
                ['k' => 'alt', 't' => 'text'],
                ['k' => 'caption', 't' => 'text'],
            ]],
        ]],
        'logos' => ['group' => 'media', 'fields' => [
            $anchor,
            ['k' => 'title', 't' => 'text'],
            ['k' => 'items', 't' => 'items', 'f' => [
                ['k' => 'src', 't' => 'image'],
                ['k' => 'alt', 't' => 'text'],
                ['k' => 'url', 't' => 'link'],
            ]],
        ]],
        'team' => ['group' => 'cards', 'fields' => [
            $anchor,
            ['k' => 'eyebrow', 't' => 'text'],
            ['k' => 'title', 't' => 'text'],
            ['k' => 'columns', 't' => 'select', 'o' => ['3' => '3', '2' => '2', '4' => '4']],
            ['k' => 'items', 't' => 'items', 'f' => [
                ['k' => 'src', 't' => 'image'],
                ['k' => 'name', 't' => 'text'],
                ['k' => 'role', 't' => 'text'],
                ['k' => 'body', 't' => 'textarea'],
            ]],
        ]],
        'data_table' => ['group' => 'content', 'fields' => [
            $anchor,
            ['k' => 'title', 't' => 'text'],
            ['k' => 'head', 't' => 'list'],
            ['k' => 'rows', 't' => 'list'],
        ]],
        'map' => ['group' => 'media', 'fields' => [
            $anchor,
            ['k' => 'title', 't' => 'text'],
            ['k' => 'embed', 't' => 'video'],
            ['k' => 'height', 't' => 'select', 'o' => ['400' => 'Medium', '300' => 'Small', '550' => 'Large']],
        ]],
        'shortcode' => ['group' => 'basic', 'fields' => [
            ['k' => 'code', 't' => 'shortcode'],
        ]],
        'divider' => ['group' => 'basic', 'fields' => []],
        'spacer'  => ['group' => 'basic', 'fields' => [
            ['k' => 'size', 't' => 'select', 'o' => ['s' => 'S', 'm' => 'M', 'l' => 'L']],
        ]],
    ];
}

function vpb_with_core_types(array $manifest): array
{
    $allow = $manifest['core_blocks'] ?? true;
    if ($allow === false) return $manifest;
    $dir = vpb_theme_dir() . '/builder/';
    foreach (vpb_core_types() as $name => $def) {
        if (isset($manifest['types'][$name])) {
            $manifest['types'][$name]['group'] = 'basic';
            foreach ($manifest['types'][$name]['fields'] ?? [] as $i => $field) {
                if (($field['k'] ?? '') === 'body' && ($field['t'] ?? '') === 'textarea') $manifest['types'][$name]['fields'][$i]['md'] = true;
            }
            continue;
        }
        if (is_array($allow) && !in_array($name, $allow, true)) continue;
        $def['bg']   = !empty($def['bg']) && !empty($manifest['bgs']) && is_file($dir . $name . '.php');
        $def['core'] = true;
        if (empty($manifest['generic'])) $def['group'] = 'basic';
        $manifest['types'][$name] = $def;
    }
    return $manifest;
}

function vpb_anchor_attr(array $b): string
{
    return ($b['anchor'] ?? '') !== '' ? ' id="' . hsc($b['anchor']) . '"' : '';
}

function vpb_btn(string $label, string $url, bool $ghost = false): string
{
    if ($label === '' || $url === '') return '';
    return '<a class="vpb-c-btn' . ($ghost ? ' vpb-c-btn--ghost' : '') . '" href="' . hsc($url) . '">' . hsc($label) . '</a>';
}

function vpb_img_url(string $src): string
{
    return preg_match('#^https?://#i', $src) ? $src : getBaseUrl() . ltrim($src, '/');
}

function vpb_block_file(string $type): string
{
    if (!preg_match('/^[a-z0-9_-]+$/', $type)) return '';
    $themed = vpb_theme_dir() . '/builder/' . $type . '.php';
    if (is_file($themed)) return $themed;
    $core = __DIR__ . '/blocks/' . $type . '.php';
    return isset(vpb_core_types()[$type]) && is_file($core) ? $core : '';
}

function vpb_valid_key(string $key): bool
{
    return (bool)preg_match('/^[a-z0-9_-]{1,60}$/', $key);
}

function vpb_protect_dirs(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $rules = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    foreach (['data', 'private'] as $name) {
        $dir = __DIR__ . '/' . $name;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) continue;
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', $rules, LOCK_EX);
    }
}

function vpb_layouts_dir(): string
{
    vpb_protect_dirs();
    return __DIR__ . '/data/layouts';
}

function vpb_layout_path(string $key): string
{
    return vpb_layouts_dir() . '/' . $key . '.json';
}

function vpb_load_layout(string $key): ?array
{
    if (!vpb_valid_key($key)) return null;
    $path = vpb_layout_path($key);
    if (!is_file($path)) return null;
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) && !empty($decoded['blocks']) && is_array($decoded['blocks']) ? $decoded : null;
}

function vpb_layout_exists(string $key): bool
{
    return vpb_valid_key($key) && is_file(vpb_layout_path($key));
}

function vpb_save_layout(string $key, array $layout): bool
{
    if (!vpb_valid_key($key)) return false;
    $dir = vpb_layouts_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $path = vpb_layout_path($key);
    $tmp  = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

function vpb_layout_rev(string $key): string
{
    return vpb_valid_key($key) && is_file(vpb_layout_path($key)) ? (string)md5_file(vpb_layout_path($key)) : '';
}

function vpb_versions_dir(string $key): string
{
    vpb_protect_dirs();
    return __DIR__ . '/private/versions/' . $key;
}

function vpb_snapshot_version(string $key): void
{
    if (!vpb_valid_key($key) || !is_file(vpb_layout_path($key))) return;
    $dir = vpb_versions_dir($key);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
    $files = glob($dir . '/*.json') ?: [];
    sort($files);
    $last = $files ? end($files) : null;
    if ($last !== null && md5_file($last) === md5_file(vpb_layout_path($key))) return;
    $name = gmdate('Ymd-His');
    $n = 0;
    while (is_file($dir . '/' . $name . ($n ? '-' . $n : '') . '.json')) $n++;
    @copy(vpb_layout_path($key), $dir . '/' . $name . ($n ? '-' . $n : '') . '.json');
    $files = glob($dir . '/*.json') ?: [];
    sort($files);
    foreach (array_slice($files, 0, max(0, count($files) - VPB_MAX_VERSIONS)) as $old) @unlink($old);
}

function vpb_list_versions(string $key): array
{
    if (!vpb_valid_key($key)) return [];
    $files = glob(vpb_versions_dir($key) . '/*.json') ?: [];
    rsort($files);
    $out = [];
    foreach ($files as $file) {
        $id = basename($file, '.json');
        $decoded = json_decode((string)file_get_contents($file), true);
        $out[] = ['id' => $id, 'time' => (int)filemtime($file), 'blocks' => is_array($decoded['blocks'] ?? null) ? count($decoded['blocks']) : 0];
    }
    return $out;
}

function vpb_load_version(string $key, string $id): ?array
{
    if (!vpb_valid_key($key) || !preg_match('/^\d{8}-\d{6}(?:-\d+)?$/', $id)) return null;
    $path = vpb_versions_dir($key) . '/' . $id . '.json';
    if (!is_file($path)) return null;
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) && is_array($decoded['blocks'] ?? null) ? $decoded : null;
}

function vpb_delete_layout(string $key): bool
{
    if (!vpb_valid_key($key)) return false;
    $path = vpb_layout_path($key);
    return !is_file($path) || unlink($path);
}

function vpb_bump_cache(): void
{
    $dir = vpb_root() . '/cache';
    if (!is_dir($dir)) return;
    $path = $dir . '/.signature';
    $tmp  = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, bin2hex(random_bytes(16)), LOCK_EX) !== false) {
        @rename($tmp, $path);
    }
}

function vpb_new_id(): string
{
    return bin2hex(random_bytes(4));
}

function vpb_field_options(array $def, array $manifest): array
{
    $options = $def['o'] ?? [];
    if (is_string($options)) {
        $options = $manifest[$options] ?? [];
    }
    return is_array($options) ? $options : [];
}

function vpb_is_lang_field(array $def): bool
{
    return in_array($def['t'] ?? 'text', ['text', 'textarea', 'list'], true);
}

function vpb_clean_string($value, int $max): string
{
    if (!is_string($value) && !is_numeric($value)) return '';
    $value = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], (string)$value);
    return mb_substr(trim($value), 0, $max);
}

function vpb_clean_link($value): string
{
    $value = vpb_clean_string($value, 300);
    if ($value === '') return '';
    return preg_match('#^(@[a-z0-9_:-]{1,60}(\#[a-z0-9_-]{1,60})?|/[^\s]*|\#[^\s]*|https?://[^\s]+|mailto:[^\s]+|tel:[^\s]+)$#i', $value) ? $value : '';
}

function vpb_clean_field(array $def, $input, array $manifest)
{
    $type = $def['t'] ?? 'text';

    if ($type === 'text' || $type === 'textarea') {
        $max = $type === 'text' ? 300 : 4000;
        $out = [];
        foreach (vpb_langs() as $lang) {
            $out[$lang] = vpb_clean_string(is_array($input) ? ($input[$lang] ?? '') : $input, $max);
        }
        return $out;
    }

    if ($type === 'list') {
        $out = [];
        $plain = is_array($input) && array_is_list($input);
        foreach (vpb_langs() as $lang) {
            $rows = $plain ? $input : (is_array($input) && is_array($input[$lang] ?? null) ? $input[$lang] : []);
            $clean = [];
            foreach (array_slice($rows, 0, VPB_MAX_ITEMS) as $row) {
                $row = vpb_clean_string($row, 700);
                if ($row !== '') $clean[] = $row;
            }
            $out[$lang] = $clean;
        }
        return $out;
    }

    if ($type === 'items') {
        $out = [];
        foreach (array_slice(is_array($input) ? array_values($input) : [], 0, VPB_MAX_ITEMS) as $row) {
            if (is_array($row)) $out[] = vpb_clean_fields($def['f'] ?? [], $row, $manifest);
        }
        return $out;
    }

    if ($type === 'blocks') {
        $out   = [];
        $types = is_array($def['types'] ?? null) ? $def['types'] : [];
        $spans = array_map('strval', $def['spans'] ?? [12, 9, 8, 6, 4, 3]);
        foreach (array_slice(is_array($input) ? array_values($input) : [], 0, VPB_MAX_BLOCKS) as $row) {
            if (!is_array($row)) continue;
            $type = (string)($row['type'] ?? '');
            if (!isset($types[$type])) continue;
            $span = (string)($row['span'] ?? '');
            $out[] = [
                'type'   => $type,
                'span'   => in_array($span, $spans, true) ? $span : $spans[0],
                'align'  => in_array($row['align'] ?? '', ['center', 'right'], true) ? $row['align'] : '',
                'valign' => in_array($row['valign'] ?? '', ['center', 'end'], true) ? $row['valign'] : '',
                'st'     => vpb_clean_style(is_array($row['st'] ?? null) ? $row['st'] : []),
                'v'      => vpb_clean_fields($types[$type]['fields'] ?? [], is_array($row['v'] ?? null) ? $row['v'] : [], $manifest),
            ];
        }
        return $out;
    }

    if ($type === 'image') {
        $value = vpb_clean_string($input, 300);
        if ($value === '' || strpos($value, '..') !== false) return '';
        return preg_match('#^(https?://[^\s]+|[A-Za-z0-9_./-]+\.(jpe?g|png|webp|gif|svg|avif))$#i', $value) ? $value : '';
    }

    if ($type === 'select') {
        $value = vpb_clean_string($input, 80);
        return array_key_exists($value, vpb_field_options($def, $manifest)) ? $value : '';
    }

    if ($type === 'check') {
        return empty($input) ? '' : '1';
    }

    if ($type === 'link') {
        return vpb_clean_link($input);
    }

    if ($type === 'video') {
        $value = trim(is_string($input) ? $input : '');
        if (preg_match('#<iframe[^>]+src=["\']([^"\']+)["\']#i', $value, $m)) $value = html_entity_decode($m[1]);
        if (strpos($value, '//') === 0) $value = 'https:' . $value;
        $value = vpb_clean_string($value, 500);
        if ($value === '' || strpos($value, '..') !== false) return '';
        return preg_match('#^(https?://[^\s]+|[A-Za-z0-9_./-]+\.(mp4|webm|ogg))$#i', $value) ? $value : '';
    }

    if ($type === 'shortcode') {
        $value = vpb_clean_string($input, 500);
        $tag   = '\[[a-z0-9_-]{1,60}(?:\s+[a-z0-9_-]+\s*=\s*(?:"[^"\]<>]*"|\'[^\'\]<>]*\'|[^\s"\'\]<>]+))*\s*\]';
        return preg_match('#^' . $tag . '(?:\s*' . $tag . ')*$#i', $value) ? $value : '';
    }

    if ($type === 'slug') {
        return mb_substr(trim((string)preg_replace('/[^a-z0-9-]/', '', strtolower(vpb_clean_string($input, 60))), '-'), 0, 60);
    }

    return '';
}

function vpb_clean_style($input): array
{
    $input = is_array($input) ? $input : [];
    $out   = [];
    foreach (['bg', 'color', 'hcolor', 'btnbg', 'btncolor'] as $key) {
        $value = vpb_clean_string($input[$key] ?? '', 7);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) $out[$key] = strtolower($value);
    }
    $pad = (string)($input['pad'] ?? '');
    if (in_array($pad, ['none', 's', 'm', 'l', 'xl'], true)) $out['pad'] = $pad;
    if (isset($input['rpx']) && is_numeric($input['rpx'])) {
        $out['rpx'] = max(0, min(80, (int)$input['rpx']));
    } else {
        $legacy = ['none' => 0, 'soft' => 12, 'round' => 28][(string)($input['rad'] ?? '')] ?? null;
        if ($legacy !== null) $out['rpx'] = $legacy;
    }
    if (isset($input['bw']) && is_numeric($input['bw'])) {
        $bw = max(30, min(100, (int)$input['bw']));
        if ($bw < 100) $out['bw'] = $bw;
    }
    if (isset($input['imgr']) && is_numeric($input['imgr'])) $out['imgr'] = max(0, min(100, (int)$input['imgr']));
    if (isset($input['imgw']) && is_numeric($input['imgw'])) {
        $imgw = max(20, min(100, (int)$input['imgw']));
        if ($imgw < 100) $out['imgw'] = $imgw;
    }
    if (in_array((string)($input['imgsh'] ?? ''), ['s', 'm', 'l'], true)) $out['imgsh'] = $input['imgsh'];
    if (in_array((string)($input['imgasp'] ?? ''), ['1/1', '4/3', '3/2', '16/9', '21/9', '3/4'], true)) $out['imgasp'] = $input['imgasp'];
    if (in_array((string)($input['imgfit'] ?? ''), ['contain'], true)) $out['imgfit'] = $input['imgfit'];
    if (in_array((string)($input['imgal'] ?? ''), ['left', 'center', 'right'], true)) $out['imgal'] = $input['imgal'];
    if (in_array((string)($input['imgpos'] ?? ''), ['top', 'bottom'], true)) $out['imgpos'] = $input['imgpos'];
    if (isset($input['btnr']) && is_numeric($input['btnr'])) $out['btnr'] = max(0, min(60, (int)$input['btnr']));
    if (in_array((string)($input['btnsz'] ?? ''), ['s', 'l', 'xl'], true)) $out['btnsz'] = $input['btnsz'];
    $talign = (string)($input['talign'] ?? '');
    if (in_array($talign, ['left', 'center', 'right'], true)) $out['talign'] = $talign;
    foreach (['size', 'hsz'] as $key) {
        if (in_array((string)($input[$key] ?? ''), ['s', 'l', 'xl'], true)) $out[$key] = $input[$key];
    }
    if (in_array((string)($input['width'] ?? ''), ['narrow', 'wide', 'full'], true)) $out['width'] = $input['width'];
    $tcolor = vpb_clean_string($input['tcolor'] ?? '', 7);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $tcolor)) $out['tcolor'] = strtolower($tcolor);
    if (isset($input['tsz']) && is_numeric($input['tsz'])) $out['tsz'] = max(12, min(96, (int)$input['tsz']));
    return $out;
}

function vpb_readable_color(string $hex): string
{
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#14231b' : '#ffffff';
}

function vpb_style_parts(array $st, bool $sub): array
{
    $css = [];
    if (isset($st['bg'])) $css[] = 'background:' . $st['bg'];
    $cls  = [];
    $text = $st['color'] ?? (isset($st['bg']) ? vpb_readable_color($st['bg']) : '');
    if ($text !== '') {
        $cls[] = 'vpb-tx';
        $css[] = 'color:' . $text;
        $css[] = '--vpb-t:' . $text;
    }
    $heading = $st['hcolor'] ?? $text;
    if ($heading !== '') {
        $cls[] = 'vpb-hc';
        $css[] = '--vpb-h:' . $heading;
    }
    if (isset($st['pad'])) {
        $px = $sub ? ['none' => 0, 's' => 12, 'm' => 24, 'l' => 40, 'xl' => 64] : ['none' => 0, 's' => 24, 'm' => 48, 'l' => 80, 'xl' => 120];
        $css[] = ($sub ? 'padding:' : 'padding-block:') . $px[$st['pad']] . 'px';
    }
    if (isset($st['rpx'])) {
        $css[] = 'border-radius:' . (int)$st['rpx'] . 'px';
        if (!$sub && (int)$st['rpx'] > 0) $css[] = 'overflow:hidden';
    }
    if (!$sub && isset($st['bw'])) {
        $css[] = 'max-width:' . (int)$st['bw'] . '%';
        $css[] = 'margin-inline:auto';
    }
    if (isset($st['talign'])) {
        $cls[] = 'vpb-ta';
        $css[] = 'text-align:' . $st['talign'];
        $css[] = '--vpb-ta:' . $st['talign'];
    }
    if (isset($st['size'])) {
        $cls[] = 'vpb-fs';
        $css[] = '--vpb-fs:' . ['s' => '.9', 'l' => '1.15', 'xl' => '1.3'][$st['size']] . 'rem';
    }
    if (isset($st['hsz'])) {
        $cls[] = 'vpb-hz';
        $css[] = '--vpb-hz:' . ['s' => '.8', 'l' => '1.2', 'xl' => '1.45'][$st['hsz']];
    }
    if (isset($st['tsz'])) {
        $cls[] = 'vpb-ts';
        $css[] = '--vpb-ts:' . (int)$st['tsz'] . 'px';
    }
    if (isset($st['tcolor'])) {
        $cls[] = 'vpb-tc';
        $css[] = '--vpb-tc:' . $st['tcolor'];
    }
    if (isset($st['imgr'])) {
        $cls[] = 'vpb-ir';
        $css[] = '--vpb-ir:' . (int)$st['imgr'] . 'px';
    }
    if (isset($st['imgsh'])) {
        $cls[] = 'vpb-is';
        $css[] = '--vpb-is:' . ['s' => '0 2px 8px rgba(0,0,0,.15)', 'm' => '0 8px 24px rgba(0,0,0,.2)', 'l' => '0 18px 48px rgba(0,0,0,.3)'][$st['imgsh']];
    }
    if (isset($st['imgasp'])) {
        $cls[] = 'vpb-ia';
        $css[] = '--vpb-ia:' . $st['imgasp'];
        $css[] = '--vpb-if:' . ($st['imgfit'] ?? 'cover');
        $css[] = '--vpb-ip:' . ($st['imgpos'] ?? 'center');
    }
    if (isset($st['imgw'])) {
        $cls[] = 'vpb-iw';
        $css[] = '--vpb-iw:' . (int)$st['imgw'] . '%';
    }
    if (isset($st['imgal'])) {
        $cls[] = 'vpb-ig';
        $css[] = '--vpb-ig:' . ['left' => '0 auto', 'center' => 'auto', 'right' => 'auto 0'][$st['imgal']];
    }
    if (isset($st['btnbg'])) {
        $cls[] = 'vpb-bb';
        $css[] = '--vpb-bb:' . $st['btnbg'];
        if (!isset($st['btncolor'])) {
            $cls[] = 'vpb-bc';
            $css[] = '--vpb-bc:' . vpb_readable_color($st['btnbg']);
        }
    }
    if (isset($st['btncolor'])) {
        $cls[] = 'vpb-bc';
        $css[] = '--vpb-bc:' . $st['btncolor'];
    }
    if (isset($st['btnr'])) {
        $cls[] = 'vpb-br';
        $css[] = '--vpb-br:' . (int)$st['btnr'] . 'px';
    }
    if (isset($st['btnsz'])) {
        $cls[] = 'vpb-bs';
        $css[] = '--vpb-bs:' . ['s' => '.85', 'l' => '1.15', 'xl' => '1.35'][$st['btnsz']] . 'rem';
    }
    if (isset($st['width'])) {
        $cls[] = 'vpb-w';
        $css[] = '--vpb-w:' . ['narrow' => '720px', 'wide' => '1320px', 'full' => '100%'][$st['width']];
    }
    return ['class' => $css ? trim('vpb-st ' . implode(' ', $cls)) : '', 'style' => implode(';', $css)];
}

function vpb_default_palette(): array
{
    return ['#ffffff', '#f2f5f4', '#e4efe9', '#14231b', '#12261f', '#1f6f5c', '#f59e0b', '#2563eb', '#dc2626'];
}

function vpb_default_style_presets(): array
{
    return [
        'light'  => ['bg' => '#ffffff', 'color' => '#14231b', 'hcolor' => '#14231b'],
        'soft'   => ['bg' => '#f2f5f4', 'color' => '#14231b', 'hcolor' => '#14231b'],
        'dark'   => ['bg' => '#12261f', 'color' => '#eef4f1', 'hcolor' => '#ffffff'],
        'brand'  => ['bg' => '#1f6f5c', 'color' => '#ffffff', 'hcolor' => '#ffffff'],
        'accent' => ['bg' => '#f59e0b', 'color' => '#1a1203', 'hcolor' => '#1a1203'],
    ];
}

function vpb_attrs(array $b, string $class = ''): string
{
    $classes = trim($class . ' ' . ($b['_class'] ?? ''));
    $out = $classes !== '' ? 'class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '"' : '';
    if (($b['_style'] ?? '') !== '') $out .= ' style="' . htmlspecialchars($b['_style'], ENT_QUOTES, 'UTF-8') . '"';
    if (($b['_path'] ?? '') !== '') $out .= ' data-vpb-path="' . htmlspecialchars($b['_path'], ENT_QUOTES, 'UTF-8') . '"';
    return trim($out);
}

function vpb_clean_fields(array $defs, array $input, array $manifest): array
{
    $out = [];
    foreach ($defs as $def) {
        $k = (string)($def['k'] ?? '');
        if ($k === '') continue;
        $out[$k] = vpb_clean_field($def, $input[$k] ?? null, $manifest);
    }
    return $out;
}

function vpb_clean_layout(array $input, array $manifest): array
{
    $blocks = [];
    $seen   = [];
    foreach (array_slice(is_array($input['blocks'] ?? null) ? array_values($input['blocks']) : [], 0, VPB_MAX_BLOCKS) as $row) {
        if (!is_array($row)) continue;
        $type = (string)($row['type'] ?? '');
        $def  = $manifest['types'][$type] ?? null;
        if ($def === null) continue;
        $id = preg_match('/^[a-f0-9]{8}$/', (string)($row['id'] ?? '')) && !isset($seen[$row['id']]) ? $row['id'] : vpb_new_id();
        $seen[$id] = true;
        $bg = '';
        if (!empty($def['bg'])) {
            $candidate = vpb_clean_string($row['bg'] ?? '', 40);
            $bg = array_key_exists($candidate, $manifest['bgs'] ?? []) ? $candidate : '';
        }
        $blocks[] = [
            'id'   => $id,
            'type' => $type,
            'bg'   => $bg,
            'st'   => vpb_clean_style(is_array($row['st'] ?? null) ? $row['st'] : []),
            'v'    => vpb_clean_fields($def['fields'] ?? [], is_array($row['v'] ?? null) ? $row['v'] : [], $manifest),
        ];
    }
    return ['blocks' => $blocks];
}

function vpb_pick_lang($map, string $lang)
{
    if (!is_array($map)) return null;
    foreach (array_unique(array_merge([$lang, vpb_default_lang()], vpb_langs())) as $candidate) {
        $value = $map[$candidate] ?? null;
        if ($value !== null && $value !== '' && $value !== []) return $value;
    }
    return null;
}

function vpb_resolve_fields(array $defs, array $values, string $lang, string $parentPath = ''): array
{
    $out = [];
    foreach ($defs as $def) {
        $k = (string)($def['k'] ?? '');
        if ($k === '') continue;
        $type  = $def['t'] ?? 'text';
        $value = $values[$k] ?? null;
        if ($type === 'text' || $type === 'textarea') {
            $out[$k] = (string)(vpb_pick_lang($value, $lang) ?? '');
        } elseif ($type === 'list') {
            $out[$k] = (array)(vpb_pick_lang($value, $lang) ?? []);
        } elseif ($type === 'blocks') {
            $out[$k] = [];
            foreach (is_array($value) ? $value : [] as $j => $row) {
                $sub = $def['types'][$row['type'] ?? ''] ?? null;
                if (!is_array($row) || $sub === null) continue;
                $parts = vpb_style_parts(is_array($row['st'] ?? null) ? $row['st'] : [], true);
                $out[$k][] = [
                    'type' => $row['type'], 'span' => (string)($row['span'] ?? '12'), 'align' => (string)($row['align'] ?? ''), 'valign' => (string)($row['valign'] ?? ''),
                    '_class' => $parts['class'], '_style' => $parts['style'],
                    '_path' => vpb_is_preview() ? $parentPath . ':' . $k . ':' . $j : '',
                ] + vpb_resolve_fields($sub['fields'] ?? [], is_array($row['v'] ?? null) ? $row['v'] : [], $lang, $parentPath . ':' . $k . ':' . $j);
            }
        } elseif ($type === 'items') {
            $out[$k] = [];
            foreach (is_array($value) ? $value : [] as $row) {
                $out[$k][] = vpb_resolve_fields($def['f'] ?? [], is_array($row) ? $row : [], $lang, $parentPath);
            }
        } else {
            $out[$k] = (string)($value ?? '');
        }
    }
    return $out;
}

function vpb_render_block(array $block, array $manifest, string $lang, int $index): string
{
    $type = (string)($block['type'] ?? '');
    $def  = $manifest['types'][$type] ?? null;
    $file = vpb_block_file($type);
    if ($def === null || $file === '') return '';
    $b = vpb_resolve_fields($def['fields'] ?? [], is_array($block['v'] ?? null) ? $block['v'] : [], $lang, (string)$index);
    $parts = vpb_style_parts(is_array($block['st'] ?? null) ? $block['st'] : [], false);
    $b['_id']    = (string)($block['id'] ?? '');
    $b['_bg']    = (string)($block['bg'] ?? '');
    $b['_n']     = $index;
    $b['_class'] = $parts['class'];
    $b['_style'] = $parts['style'];
    $b['_path']  = vpb_is_preview() ? (string)$index : '';
    $render = static function (array $b, string $lang, string $file): string {
        ob_start();
        include $file;
        return (string)ob_get_clean();
    };
    return $render($b, $lang, $file);
}

function vpb_is_preview(): bool
{
    return !empty($GLOBALS['_vpb_preview_active']);
}

function vpb_draft_dir(): string
{
    vpb_protect_dirs();
    return __DIR__ . '/private/drafts';
}

function vpb_draft_path(string $key): string
{
    return vpb_draft_dir() . '/' . $key . '.json';
}

function vpb_save_draft(string $key, array $layout): bool
{
    if (!vpb_valid_key($key)) return false;
    $dir = vpb_draft_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $path = vpb_draft_path($key);
    $tmp  = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

function vpb_prune_drafts(): void
{
    foreach (glob(vpb_draft_dir() . '/*.json') ?: [] as $file) {
        if (filemtime($file) < time() - VPB_DRAFT_TTL) @unlink($file);
    }
}

function vpb_pending_draft(string $key, array $manifest): ?array
{
    if (!vpb_valid_key($key) || !is_file(vpb_draft_path($key))) return null;
    $layoutFile = vpb_layout_path($key);
    if (is_file($layoutFile) && filemtime(vpb_draft_path($key)) <= filemtime($layoutFile)) return null;
    $decoded = json_decode((string)file_get_contents(vpb_draft_path($key)), true);
    if (!is_array($decoded) || empty($decoded['blocks']) || !is_array($decoded['blocks'])) return null;
    $draft   = vpb_clean_layout($decoded, $manifest);
    $current = vpb_load_layout($key) ?? ['blocks' => []];
    return $draft['blocks'] !== vpb_clean_layout($current, $manifest)['blocks'] ? $draft : null;
}

function vpb_delete_draft(string $key): void
{
    if (vpb_valid_key($key) && is_file(vpb_draft_path($key))) @unlink(vpb_draft_path($key));
}

function vpb_preview_token(string $key): string
{
    $ts = time();
    return $ts . '.' . hash_hmac('sha256', 'preview|' . $key . '|' . $ts, vpb_secret());
}

function vpb_verify_preview_token(string $key, string $token): bool
{
    if (substr_count($token, '.') !== 1) return false;
    [$ts, $mac] = explode('.', $token, 2);
    if (!ctype_digit($ts) || (time() - (int)$ts) > 7200) return false;
    return hash_equals(hash_hmac('sha256', 'preview|' . $key . '|' . $ts, vpb_secret()), $mac);
}

function vpb_preview_layout(string $key): ?array
{
    $token = $_GET['vpb_preview'] ?? '';
    if (!is_string($token) || $token === '' || !vpb_valid_key($key) || !vpb_verify_preview_token($key, $token)) return null;
    $path = vpb_draft_path($key);
    if (!is_file($path)) return null;
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded) || !is_array($decoded['blocks'] ?? null)) return null;
    $GLOBALS['_vpb_preview_active'] = true;
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
    }
    return $decoded;
}

function vpb_targets(array $manifest): array
{
    $targets  = is_array($manifest['targets'] ?? null) ? $manifest['targets'] : [];
    $template = (string)($manifest['page_template'] ?? '');
    $index = json_decode((string)@file_get_contents(vpb_root() . '/data/pages/_index.json'), true);
    foreach (is_array($index) ? $index : [] as $entry) {
        $slug = (string)($entry['slug'] ?? '');
        if (!vpb_valid_key($slug) || isset($targets[$slug])) continue;
        if (empty($manifest['generic']) && ($entry['page_template'] ?? '') !== $template && !vpb_layout_exists($slug)) continue;
        $path = trim((string)($entry['custom_slug'] ?? ''), '/');
        $targets[$slug] = ['label' => (string)($entry['title'] ?? $slug), 'path' => $path !== '' ? $path : $slug, 'page' => true, 'type' => 'page'];
    }
    foreach (['article', 'project'] as $contentType) {
        $rows = json_decode((string)@file_get_contents(vpb_root() . '/data/' . $contentType . 's/_index.json'), true);
        foreach (is_array($rows) ? $rows : [] as $entry) {
            $itemSlug = (string)($entry['slug'] ?? '');
            $key      = vpb_item_key($contentType, $itemSlug);
            if ($key === '' || isset($targets[$key])) continue;
            $targets[$key] = [
                'label'    => (string)($entry['title'] ?? $itemSlug),
                'path'     => '',
                'type'     => $contentType,
                'slug'     => $itemSlug,
                'category' => is_string($entry['category'] ?? null) ? $entry['category'] : '',
            ];
        }
    }
    return $targets;
}

function vpb_render_layout(string $key, ?array $item = null): ?string
{
    $GLOBALS['_vpb_item'] = $item;
    $layout   = vpb_preview_layout($key) ?? vpb_load_layout($key);
    $manifest = $layout !== null ? vpb_manifest() : null;
    if ($layout === null || $manifest === null) return null;
    $GLOBALS['_vpb_rendering'] = true;
    try {
        return vpb_render_layout_html($layout, $manifest);
    } finally {
        $GLOBALS['_vpb_rendering'] = false;
    }
}

function vpb_render_layout_html(array $layout, array $manifest): string
{
    $lang = vpb_active_lang();
    $html = '';
    foreach ($layout['blocks'] as $i => $block) {
        if (!is_array($block)) continue;
        $out = vpb_render_block($block, $manifest, $lang, $i);
        if (vpb_is_preview()) {
            $type = (string)($block['type'] ?? '');
            $bare = empty($_GET['vpb_view'])
                && !in_array($type, ['spacer', 'divider'], true)
                && trim(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $out))) === ''
                && !preg_match('#<(img|iframe|video|svg|hr|picture|canvas)\b#i', $out);
            if ($bare) {
                $label = vpb_t('vpb_t_' . $type, $type);
                $out = '<div data-vpb-path="' . $i . '" style="display:block;box-sizing:border-box;margin:12px 16px;padding:28px 16px;border:2px dashed #94a3b8;border-radius:8px;background:rgba(148,163,184,.12);color:#475569;font:600 14px system-ui,sans-serif;text-align:center">' . hsc($label) . ' · ' . hsc(vpb_t('vpb_empty_block')) . '</div>';
            } elseif (strpos($out, 'data-vpb-path="' . $i . '"') === false) {
                $out = '<div data-vpb-path="' . $i . '" style="display:contents">' . $out . '</div>';
            }
        }
        $html .= $out;
    }
    if (strpos($html, 'vpb-st') !== false || strpos($html, 'vpb-c') !== false) {
        $css  = __DIR__ . '/assets/css/vpb-front.css';
        $html = '<link rel="stylesheet" href="' . hsc(vpb_plugin_base_url() . 'assets/css/vpb-front.css?v=' . (@filemtime($css) ?: 1)) . '">' . $html;
    }
    return $html;
}

function vpb_secret(): string
{
    vpb_protect_dirs();
    $dir  = __DIR__ . '/private';
    $file = $dir . '/vpb.secret';
    if (is_file($file)) {
        $secret = trim((string)file_get_contents($file));
        if (strlen($secret) === 64) return $secret;
    }
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $secret = bin2hex(random_bytes(32));
    @file_put_contents($file, $secret, LOCK_EX);
    return $secret;
}

function vpb_generate_csrf(): string
{
    $ts = time();
    return $ts . '.' . hash_hmac('sha256', 'save|' . $ts . '|' . session_id(), vpb_secret());
}

function vpb_verify_csrf(string $token): bool
{
    if (substr_count($token, '.') !== 1) return false;
    [$ts, $mac] = explode('.', $token, 2);
    if (!ctype_digit($ts) || (time() - (int)$ts) > 7200) return false;
    return hash_equals(hash_hmac('sha256', 'save|' . $ts . '|' . session_id(), vpb_secret()), $mac);
}
