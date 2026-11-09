<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Comments Plugin — Init
 *
 * Three independent responsibilities, all guarded so they only do work in
 * the right context:
 *
 * 1. Admin menu registration ('admin_menu' hook, see plugin-api.php).
 *
 * 2. Dashboard widget ('admin_dashboard' hook) — pending comments count +
 *    the most recent pending comments, same pattern as the Booking and
 *    Analytics dashboard widgets. Toggled by the 'show_dashboard_widget'
 *    setting (Settings tab).
 *
 * 3. Front-end [comments] shortcode resolution — the core's
 *    render_content_html() shortcode pipeline is closed (no filter hook
 *    runs inside it), so this plugin buffers the entire page output and
 *    resolves the shortcode once the page is fully rendered, mirroring
 *    the Form Builder / Booking plugins' approach.
 */

if (defined('CM_INIT_LOADED')) return;
define('CM_INIT_LOADED', true);

require_once __DIR__ . '/comments-functions.php';
require_once __DIR__ . '/comments-i18n.php';

// Only needed when the plugin's admin page is actually being rendered.
require_once __DIR__ . '/admin/admin-page.php';

// ─── 1. Admin menu entry ────────────────────────────────────────────────────
if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        $pending = cm_count_pending();
        $label   = cm_t('cm_admin_title', 'Comments', 'admin');

        pl_register_admin_menu(
            'comments',
            $label,
            cm_admin_page_url(),
            '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'
        );

        // The sidebar label is HTML-escaped by admin/includes/sidebar.php,
        // so a real <span> badge can't be injected through the label
        // parameter above. A <link> element (unlike a bare <style> block)
        // is valid anywhere in the body, including in the middle of the
        // sidebar's <ul><li> markup where this hook fires — so the badge
        // rule is served as a tiny data: URI stylesheet instead, targeting
        // this plugin's sidebar link by its unique data-label attribute.
        // Matches the shape of the core's own data-badge pill (see
        // .sidebar-subitem[data-badge]::after in admin-sidebar.css).
        if ($pending > 0) {
            $labelAttr = addslashes($label);
            $css = 'a[data-label="' . $labelAttr . '"]{position:relative}'
                 . 'a[data-label="' . $labelAttr . '"]::after{content:"' . (int)$pending . '";'
                 . 'margin-left:auto;padding:1px 7px;line-height:1.4;background:var(--primary);color:#fff;'
                 . 'font-size:.78em;font-weight:700;border-radius:999px}';
            echo '<link rel="stylesheet" href="data:text/css,' . rawurlencode($css) . '">';
        }
    });
}

// ─── 2. Dashboard widget ────────────────────────────────────────────────────
// Reuses .cm-a-* design tokens from comments-admin-embed.css (linked here
// since admin_dashboard has no extra_head slot). Reads the same cross-item
// index the moderation dashboard uses — cheap enough for an admin page load.
if (function_exists('pl_on_admin_dashboard')) {
    pl_on_admin_dashboard(function () {
        if (empty(cm_load_settings()['show_dashboard_widget'])) return;

        require_once __DIR__ . '/comments-data-layer.php';

        $pending = array_values(array_filter(cm_load_all_comments(), fn($c) => ($c['status'] ?? '') === 'pending'));
        $recent  = array_slice($pending, 0, 3);

        $cssPath = __DIR__ . '/assets/css/comments-admin-embed.css';
        $cssV    = @filemtime($cssPath) ?: time();
        ?>
        <link rel="stylesheet" href="<?php echo hsc(cm_plugin_base_url() . 'assets/css/comments-admin-embed.css'); ?>?v=<?php echo $cssV; ?>">
        <div class="dashboard-panel dashboard-panel--sm cm-dash-widget">
            <div class="cm-dash-widget-header">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <?php echo hsc(cm_t('cm_dashboard_widget_title', 'Comments', 'admin')); ?>
                </h3>
                <a href="<?php echo hsc(cm_admin_page_url(['status' => 'pending'])); ?>" class="cm-dash-more"><?php echo hsc(cm_t('cm_view_all', 'View all →', 'admin')); ?></a>
            </div>
            <div class="cm-dash-kpi">
                <div class="cm-dash-kpi-value"><?php echo (int)count($pending); ?></div>
                <div class="cm-dash-kpi-label"><?php echo hsc(cm_t('cm_dashboard_kpi_pending', 'Comments awaiting moderation', 'admin')); ?></div>
            </div>
            <?php if (empty($recent)): ?>
            <p class="cm-dash-empty"><?php echo hsc(cm_t('cm_dashboard_empty', 'No comments awaiting moderation.', 'admin')); ?></p>
            <?php else: ?>
            <div class="cm-dash-list">
                <?php foreach ($recent as $c):
                    $excerpt = trim(strip_tags($c['content'] ?? ''));
                    $excerpt = mb_strlen($excerpt) > 60 ? mb_substr($excerpt, 0, 60) . '…' : $excerpt;
                ?>
                <a class="cm-dash-row" href="<?php echo hsc(cm_admin_page_url(['status' => 'pending'])); ?>">
                    <span class="cm-dash-row-author"><?php echo hsc($c['author_name'] ?? ''); ?></span>
                    <span class="cm-dash-row-slug">· <?php echo hsc($c['_slug'] ?? ''); ?></span>
                    <div class="cm-dash-row-excerpt"><?php echo hsc($excerpt); ?></div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    });
}

// ─── 3. Front-end shortcode resolution ─────────────────────────────────────
$__cm_isCli    = PHP_SAPI === 'cli';
$__cm_isPlugin = strpos($_SERVER['REQUEST_URI'] ?? '', '/comments/') !== false;
$__cm_cfgFile  = dirname(__DIR__, 2) . '/config.json';
$__cm_adminDir = 'admin';
if (file_exists($__cm_cfgFile)) {
    $__cm_cfg = json_decode(file_get_contents($__cm_cfgFile), true);
    if (is_array($__cm_cfg) && !empty($__cm_cfg['admin_dir'])) {
        $__cm_adminDir = $__cm_cfg['admin_dir'];
    }
    unset($__cm_cfg);
}
unset($__cm_cfgFile);
$__cm_isAdmin = strpos($_SERVER['REQUEST_URI'] ?? '', '/' . $__cm_adminDir . '/') !== false;

if (!$__cm_isCli && !$__cm_isPlugin && !$__cm_isAdmin) {
    require_once __DIR__ . '/comments-shortcode.php';

    ob_start(function (string $html): string {
        if (strpos($html, '[comments') === false) {
            return $html;
        }

        $found = false;
        $html  = preg_replace_callback(
            '/\[comments(\s+[^\]]*)?\]/i',
            function ($matches) use (&$found) {
                $found = true;
                $attrs = !empty($matches[1]) ? _shortcode_parse_attrs($matches[1]) : [];
                return cm_render_comments_html($attrs);
            },
            $html
        );

        if (!$found) return $html;

        $assets = cm_render_assets();
        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', $assets . '</head>', $html, 1);
        } else {
            $html = $assets . $html;
        }

        return $html;
    });
}

unset($__cm_isCli, $__cm_isPlugin, $__cm_isAdmin);
