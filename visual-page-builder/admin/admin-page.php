<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

if (defined('VPB_ADMIN_PAGE_LOADED')) return;
define('VPB_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../vpb-functions.php';
require_once __DIR__ . '/../vpb-i18n.php';

function vpb_site_base_url(): string
{
    $https = function_exists('_sl_request_is_https') ? _sl_request_is_https() : !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host  = function_exists('_sl_request_host') && _sl_request_host() !== '' ? _sl_request_host() : (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $docRoot = rtrim(str_replace('\\', '/', (string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''))), '/');
    $cmsRoot = str_replace('\\', '/', (string)realpath(vpb_root()));
    $path    = $docRoot !== '' && strpos($cmsRoot, $docRoot) === 0 ? substr($cmsRoot, strlen($docRoot)) : '';
    return ($https ? 'https' : 'http') . '://' . $host . rtrim($path, '/') . '/';
}

function vpb_plugin_base_url(): string
{
    return vpb_site_base_url() . 'plugins/visual-page-builder/';
}

function vpb_admin_dir(): string
{
    $dir = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)(vpb_config()['admin_dir'] ?? ''));
    return $dir !== '' ? $dir : 'admin';
}

function vpb_admin_page_url(array $params = []): string
{
    $params = array_merge(['action' => 'plugin_page', 'slug' => 'visual-page-builder', 'view' => 'list'], $params);
    return vpb_site_base_url() . vpb_admin_dir() . '/index.php?' . http_build_query($params);
}

function vpb_asset_url(string $relative): string
{
    return vpb_plugin_base_url() . $relative . '?v=' . (@filemtime(dirname(__DIR__) . '/' . $relative) ?: time());
}

function vpb_type_has_buttons(string $name, array $def): bool
{
    if (isset($def['buttons'])) return (bool)$def['buttons'];
    $file = vpb_block_file($name);
    if ($file !== '' && preg_match('/btn|button/i', (string)file_get_contents($file))) return true;
    foreach ($def['fields'] ?? [] as $field) {
        foreach (array_keys($field['types'] ?? []) as $sub) {
            if (preg_match('/button|btn|cta/', (string)$sub)) return true;
        }
    }
    return false;
}

function vpb_client_types(array $manifest): array
{
    $types = [];
    foreach ($manifest['types'] as $name => $def) {
        $types[$name] = [
            'bg'     => !empty($def['bg']),
            'group'  => (string)($def['group'] ?? 'content'),
            'btn'    => vpb_type_has_buttons((string)$name, $def),
            'label'  => vpb_theme_text($def['label'] ?? ''),
            'desc'   => vpb_theme_text($def['description'] ?? ''),
            'fields' => vpb_client_fields($def['fields'] ?? [], $manifest),
        ];
    }
    return $types;
}

function vpb_client_fields(array $defs, array $manifest): array
{
    $out = [];
    foreach ($defs as $def) {
        $row = ['k' => (string)$def['k'], 't' => (string)($def['t'] ?? 'text')];
        if (!empty($def['md'])) {
            $row['md'] = true;
        }
        if ($row['t'] === 'select') {
            $row['o'] = vpb_field_options($def, $manifest);
        }
        if ($row['t'] === 'items') {
            $row['f'] = vpb_client_fields($def['f'] ?? [], $manifest);
        }
        if ($row['t'] === 'blocks') {
            $row['spans'] = array_map('strval', $def['spans'] ?? [12, 9, 8, 6, 4, 3]);
            $row['types'] = [];
            $row['btn']   = [];
            foreach ($def['types'] ?? [] as $name => $sub) {
                if (!empty($sub['buttons']) || preg_match('/button|btn|cta/', (string)$name)) $row['btn'][] = (string)$name;
                $row['types'][$name] = vpb_client_fields($sub['fields'] ?? [], $manifest);
            }
        }
        $out[] = $row;
    }
    return $out;
}

function vpb_front_type_slug(string $type): string
{
    $config   = vpb_config();
    $override = (string)($config['type_labels'][$type]['singular'] ?? '');
    $slug     = $override;
    if ($slug === '') {
        $lang = preg_match('/^[a-z]{2}$/', (string)($config['active_language'] ?? '')) ? $config['active_language'] : 'en';
        foreach ([$lang, 'en'] as $code) {
            $file = vpb_root() . '/lang/front/' . $code . '.json';
            $strings = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
            if (is_array($strings) && !empty($strings['url_slug_' . $type])) {
                $slug = (string)$strings['url_slug_' . $type];
                break;
            }
        }
    }
    $slug = trim((string)preg_replace('/[^a-z0-9-]/', '', strtolower(function_exists('sanitizeSlug') ? sanitizeSlug($slug) : $slug)), '-');
    return $slug !== '' ? $slug : $type;
}

function vpb_target_url(array $target): string
{
    $type = (string)($target['type'] ?? 'page');
    if ($type === 'page') {
        return vpb_site_base_url() . ltrim((string)($target['path'] ?? ''), '/');
    }
    $slug     = (string)($target['slug'] ?? '');
    $category = (string)($target['category'] ?? '');
    $catPath  = '';
    if ($category !== '' && function_exists('getCategoryPath') && function_exists('sl_load_categories')) {
        $catPath = (string)getCategoryPath(sanitizeSlug($category), ['categories' => sl_load_categories()]);
    }
    $parts = [];
    if ($type === 'project' || $catPath === '') $parts[] = vpb_front_type_slug($type);
    if ($catPath !== '') $parts[] = $catPath;
    $parts[] = $slug;
    return vpb_site_base_url() . implode('/', $parts) . '/';
}

function vpb_ensure_item_content(array $target): void
{
    $type = (string)($target['type'] ?? 'page');
    if ($type === 'page' || !function_exists('sl_load_item') || !function_exists('sl_admin_save_item')) return;
    $entry = function_exists('sl_find_in_index') ? sl_find_in_index($type, (string)($target['slug'] ?? '')) : null;
    $file  = is_array($entry) ? (string)($entry['_file'] ?? $entry['slug'] ?? '') : '';
    if ($file === '') return;
    $item = sl_load_item($type, $file);
    if (!is_array($item) || trim((string)($item['content'] ?? '')) !== '') return;
    $item['content'] = '<!-- vpb -->';
    sl_admin_save_item($type, $file, $item);
    if (function_exists('sl_invalidate_index_cache')) sl_invalidate_index_cache($type);
}

function vpb_create_page(string $title, string $starter, array $manifest, string $type = 'page'): ?string
{
    if (!in_array($type, ['page', 'article', 'project'], true) || !function_exists('sl_admin_save_item')) return null;
    $template = (string)($manifest['page_template'] ?? '');
    if ($type === 'page' && $template === '' && empty($manifest['generic'])) return null;
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    if ($title === '') return null;
    $base = function_exists('url_slug') ? url_slug($title) : strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    $base = trim((string)preg_replace('/[^a-z0-9-]/', '', strtolower($base)), '-');
    if ($base === '') $base = $type;
    $base = substr($base, 0, 40);
    $slug = $base;
    $n    = 2;
    while (sl_find_in_index($type, $slug) || vpb_layout_exists(vpb_item_key($type, $slug)) || file_exists(sl_item_path($type, $slug))) {
        $slug = $base . '-' . $n++;
    }
    $key = vpb_item_key($type, $slug);
    if ($key === '') return null;
    $today = date('Y-m-d');
    $item  = [
        'title' => $title, 'slug' => $slug, 'custom_slug' => $type === 'page' ? $slug : '', 'content' => $type === 'page' ? '' : '<!-- vpb -->',
        'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '', 'canonical_url' => '',
        'schema_type' => $type === 'article' ? 'Article' : ($type === 'page' ? 'WebPage' : ''),
        'og_title' => '', 'og_description' => '', 'og_image' => '',
        'show_featured_image' => false, 'show_date' => $type === 'article', 'show_title' => $type !== 'page', 'gallery_layout' => 'grid',
        'category' => '', 'tags' => [], 'show_tags_at_bottom' => false, 'content_format' => 'html',
        'date' => $type === 'page' ? $today : date('Y-m-d H:i'), 'show_in_menu' => false, 'menu_order' => 99,
        'status' => 'published', 'publish_at' => '', 'last_modified' => $today,
    ];
    if ($type === 'page') $item['page_template'] = $template;
    if ($type === 'article') $item += ['summary' => '', 'show_on_homepage' => false, 'show_related_items' => false];
    if ($type === 'project') $item += ['description' => '', 'show_on_homepage' => false, 'show_related_items' => false];
    $fileSlug = sl_unique_file_slug($type, $slug);
    if (!sl_admin_save_item($type, $fileSlug, $item)) return null;
    $entry          = sl_admin_extract_index_entry($type, $item);
    $entry['_file'] = $fileSlug;
    sl_admin_update_index($type, $entry);
    if (function_exists('sl_invalidate_index_cache')) sl_invalidate_index_cache($type);

    $blocks = $manifest['starters'][$starter]['blocks'] ?? [];
    if (is_array($blocks) && $blocks) {
        vpb_save_layout($key, vpb_clean_layout(['blocks' => $blocks], $manifest));
    }
    vpb_bump_cache();
    return $key;
}

function vpb_page_file(string $slug): ?string
{
    if (!vpb_valid_key($slug)) return null;
    $index = json_decode((string)@file_get_contents(vpb_root() . '/data/pages/_index.json'), true);
    foreach (is_array($index) ? $index : [] as $entry) {
        if (($entry['slug'] ?? '') === $slug) return (string)($entry['_file'] ?? $slug);
    }
    return null;
}

function vpb_trash_page(string $slug): bool
{
    $file = vpb_page_file($slug);
    if ($file === null || !function_exists('sl_admin_trash_item') || !sl_admin_trash_item('page', basename($file))) return false;
    vpb_delete_layout($slug);
    vpb_delete_draft($slug);
    if (function_exists('sl_invalidate_index_cache')) sl_invalidate_index_cache('page');
    vpb_bump_cache();
    return true;
}

function visual_page_builder_render_admin_page(string $view): array
{
    $manifest = vpb_manifest();
    $title    = vpb_t('vpb_admin_title', 'Page builder');
    $head     = '<link rel="stylesheet" href="' . hsc(vpb_asset_url('assets/css/vpb-admin.css')) . '">';

    if ($manifest === null) {
        return ['title' => $title, 'html' => '<div class="vpb-wrap"><p class="vpb-empty">' . hsc(vpb_t('vpb_no_manifest')) . '</p></div>', 'extra_head' => $head];
    }

    $targets = vpb_targets($manifest);
    $key     = preg_replace('/[^a-z0-9_-]/', '', (string)($_GET['key'] ?? ''));
    $error   = '';

    if ($view === 'list' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['vpb_action'] ?? '') === 'delete_page') {
        $slug = preg_replace('/[^a-z0-9_-]/', '', (string)($_POST['key'] ?? ''));
        $ok   = vpb_verify_csrf(trim((string)($_POST['_csrf'] ?? ''))) && isset($targets[$slug]) && vpb_page_file($slug) !== null && ($targets[$slug]['path'] ?? 'x') !== '' && vpb_trash_page($slug);
        header('Location: ' . vpb_admin_page_url(['deleted' => $ok ? '1' : '0']));
        exit;
    }

    if ($view === 'new') {
        $ctype = (string)($_REQUEST['ctype'] ?? 'page');
        if (!in_array($ctype, ['page', 'article', 'project'], true)) $ctype = 'page';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $starter = (string)($_POST['starter'] ?? '');
            $created = vpb_verify_csrf(trim((string)($_POST['_csrf'] ?? ''))) && isset($manifest['starters'][$starter])
                ? vpb_create_page((string)($_POST['title'] ?? ''), $starter, $manifest, $ctype)
                : null;
            if ($created !== null) {
                header('Location: ' . vpb_admin_page_url(['view' => 'edit', 'key' => $created]));
                exit;
            }
            $error = vpb_t('vpb_new_error');
        }
        ob_start();
        ?>
        <div class="vpb-wrap vpb-wrap--narrow">
            <?php if ($error !== ''): ?><div class="message error"><?php echo hsc($error); ?></div><?php endif; ?>
            <form method="post" class="vpb-new">
                <input type="hidden" name="_csrf" value="<?php echo hsc(vpb_generate_csrf()); ?>">
                <input type="hidden" name="ctype" value="<?php echo hsc($ctype); ?>">
                <label class="vpb-label" for="vpb-new-title"><?php echo hsc(vpb_t($ctype === 'page' ? 'vpb_new_title' : 'vpb_new_title_' . $ctype)); ?></label>
                <input type="text" id="vpb-new-title" name="title" maxlength="120" required value="<?php echo hsc((string)($_POST['title'] ?? '')); ?>">
                <p class="vpb-label"><?php echo hsc(vpb_t('vpb_new_starter')); ?></p>
                <div class="vpb-starters">
                    <?php $first = true; foreach ($manifest['starters'] ?? [] as $sk => $starter): ?>
                    <label class="vpb-starter">
                        <input type="radio" name="starter" value="<?php echo hsc((string)$sk); ?>"<?php echo $first ? ' checked' : ''; ?>>
                        <strong><?php echo hsc((string)($starter['label'] ?? $sk)); ?></strong>
                        <span><?php echo hsc((string)($starter['description'] ?? '')); ?></span>
                    </label>
                    <?php $first = false; endforeach; ?>
                </div>
                <p><button type="submit" class="btn btn-primary"><?php echo hsc(vpb_t('vpb_create')); ?></button>
                <a class="btn btn-outline" href="<?php echo hsc(vpb_admin_page_url(['ctype' => $ctype])); ?>"><?php echo hsc(vpb_t('vpb_type_' . $ctype)); ?></a></p>
            </form>
        </div>
        <?php
        return ['title' => $title . ' — ' . vpb_t('vpb_new_' . $ctype), 'html' => ob_get_clean(), 'extra_head' => $head];
    }

    if ($view === 'edit' && isset($targets[$key])) {
        vpb_prune_drafts();
        vpb_ensure_item_content($targets[$key]);
        $layout = vpb_load_layout($key);
        $config = [
            'key'        => $key,
            'label'      => (string)($targets[$key]['label'] ?? $key),
            'layout'     => $layout ?? ['blocks' => []],
            'hasLayout'  => $layout !== null,
            'rev'        => vpb_layout_rev($key),
            'draft'      => vpb_pending_draft($key, $manifest),
            'types'      => vpb_client_types($manifest),
            'bgs'        => $manifest['bgs'] ?? [],
            'links'      => $manifest['links'] ?? [],
            'langs'      => array_values(array_unique(array_merge([vpb_default_lang()], vpb_langs()))),
            'lang'       => vpb_active_lang(),
            'csrf'       => vpb_generate_csrf(),
            'actionsUrl' => vpb_plugin_base_url() . 'admin/actions.php',
            'listUrl'    => vpb_admin_page_url(['ctype' => (string)($targets[$key]['type'] ?? 'page')]),
            'previewUrl' => vpb_target_url($targets[$key]),
            'strings'    => vpb_admin_strings(),
            'palette'    => is_array($manifest['palette'] ?? null) ? array_values($manifest['palette']) : vpb_default_palette(),
            'presets'    => is_array($manifest['style_presets'] ?? null) ? $manifest['style_presets'] : vpb_default_style_presets(),
            'mediaUrl'   => vpb_site_base_url() . vpb_admin_dir() . '/get-files.php',
            'siteBase'   => vpb_site_base_url(),
        ];
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        ob_start();
        ?>
        <div class="vpb-wrap vpb-wrap--edit" id="vpb-app"></div>
        <script type="application/json" id="vpb-config"><?php echo $json; ?></script>
        <script src="<?php echo hsc(vpb_asset_url('assets/js/vpb-editor.js')); ?>"></script>
        <?php
        return ['title' => $title . ' — ' . (string)($targets[$key]['label'] ?? $key), 'html' => ob_get_clean(), 'extra_head' => $head];
    }

    $ctype = (string)($_GET['ctype'] ?? 'page');
    if (!in_array($ctype, ['page', 'article', 'project'], true)) $ctype = 'page';
    ob_start();
    ?>
    <div class="vpb-wrap">
        <div class="vpb-listbar">
            <p class="vpb-hint"><?php echo hsc(vpb_t('vpb_list_hint')); ?></p>
            <?php if ($ctype !== 'page' || ($manifest['page_template'] ?? '') !== '' || !empty($manifest['generic'])): ?>
            <a class="btn btn-primary" href="<?php echo hsc(vpb_admin_page_url(['view' => 'new', 'ctype' => $ctype])); ?>">+ <?php echo hsc(vpb_t('vpb_new_' . $ctype)); ?></a>
            <?php endif; ?>
        </div>
        <?php if (isset($_GET['deleted'])): ?><div class="message <?php echo $_GET['deleted'] === '1' ? 'success' : 'error'; ?>"><?php echo hsc(vpb_t($_GET['deleted'] === '1' ? 'vpb_page_deleted' : 'vpb_page_delete_error')); ?></div><?php endif; ?>
        <div class="vpb-tabs-list">
            <?php foreach (['page', 'article', 'project'] as $ct): ?>
            <a class="btn btn-sm <?php echo $ct === $ctype ? 'btn-primary' : 'btn-outline'; ?>" href="<?php echo hsc(vpb_admin_page_url(['ctype' => $ct])); ?>"><?php echo hsc(vpb_t('vpb_type_' . $ct)); ?></a>
            <?php endforeach; ?>
        </div>
        <div class="table-wrap">
        <table>
            <thead><tr><th><?php echo hsc(vpb_t('vpb_col_page')); ?></th><th><?php echo hsc(vpb_t('vpb_col_state')); ?></th><th></th></tr></thead>
            <tbody>
            <?php $csrf = vpb_generate_csrf(); foreach ($targets as $tk => $target): if ((string)($target['type'] ?? 'page') !== $ctype) continue; $custom = vpb_load_layout((string)$tk) !== null; $deletable = ($target['path'] ?? 'x') !== '' && vpb_page_file((string)$tk) !== null; ?>
                <tr>
                    <td><?php echo hsc((string)($target['label'] ?? $tk)); ?></td>
                    <td><span class="badge <?php echo $custom ? 'badge-published' : 'vpb-badge-muted'; ?>"><?php echo hsc($custom ? vpb_t('vpb_state_custom') : vpb_t('vpb_state_theme')); ?></span></td>
                    <td class="vpb-right">
                        <a class="table-btn edit-btn" href="<?php echo hsc(vpb_admin_page_url(['view' => 'edit', 'key' => $tk])); ?>"><?php echo hsc(vpb_t('vpb_edit')); ?></a>
                        <?php if ($deletable): ?>
                        <form method="post" class="vpb-inline" data-confirm="<?php echo hsc(vpb_t('vpb_confirm_delete_page')); ?>">
                            <input type="hidden" name="vpb_action" value="delete_page">
                            <input type="hidden" name="key" value="<?php echo hsc((string)$tk); ?>">
                            <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                            <button type="submit" class="table-btn delete-btn"><?php echo hsc(vpb_t('vpb_delete_page')); ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
    return ['title' => $title, 'html' => ob_get_clean(), 'extra_head' => $head];
}
