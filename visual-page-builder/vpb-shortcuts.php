<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

function vpb_shortcut_applies(): bool
{
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return false;
    if (($_GET['action'] ?? '') !== 'edit' || ($_GET['type'] ?? '') !== 'page') return false;
    $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    return $script !== false && $script === realpath(vpb_root() . '/' . vpb_admin_dir() . '/index.php');
}

function vpb_shortcut_inject(string $html, ?array $item = null): string
{
    if (strpos($html, 'id="page_template"') === false) return $html;
    $item = $item ?? ($GLOBALS['editItem'] ?? null);
    $slug = is_array($item) ? (string)($item['slug'] ?? '') : '';
    $manifest = vpb_manifest();
    if ($slug === '' || $manifest === null || !vpb_valid_key($slug)) return $html;
    $targets = vpb_targets($manifest);
    $template = (string)($manifest['page_template'] ?? '');
    $eligible = isset($targets[$slug]) || vpb_layout_exists($slug) || ($template !== '' && ($item['page_template'] ?? '') === $template);
    if (!$eligible) return $html;
    $link = '<a class="btn btn-outline btn-sm vpb-shortcut" style="margin-top:8px" href="' . hsc(vpb_admin_page_url(['view' => 'edit', 'key' => $slug])) . '">' . hsc(vpb_t('vpb_open_builder', 'Open in Visual Page Builder')) . '</a>';
    $count = 0;
    $out = preg_replace('~(<select id="page_template".*?</select>)~s', '$1' . str_replace('$', '\\$', $link), $html, 1, $count);
    return $count === 1 && is_string($out) ? $out : $html;
}

if (function_exists('pl_add_hook') && vpb_shortcut_applies()) {
    ob_start('vpb_shortcut_inject_buffer');
}

function vpb_shortcut_inject_buffer(string $html): string
{
    return vpb_shortcut_inject($html);
}
