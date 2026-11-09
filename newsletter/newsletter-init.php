<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Newsletter Plugin — Init
 *
 * Three independent responsibilities, all guarded so they only do work in
 * the right context:
 *
 * 1. Admin menu registration ('admin_menu' hook, see plugin-api.php) — the
 *    sidebar entry is registered here whenever the plugin is loaded in an
 *    admin context.
 *
 * 2. Dashboard widget ('admin_dashboard' hook) — confirmed/pending
 *    subscriber counts, same pattern as the Booking and Comments dashboard
 *    widgets. Toggled by the 'show_dashboard_widget' setting (Sender
 *    settings panel).
 *
 * 3. Front-end [newsletter_signup] shortcode resolution — the core's
 *    render_content_html() shortcode pipeline is closed (no filter hook
 *    runs inside it), so there is no clean way to register a new
 *    [shortcode] without editing core files. Instead, this plugin buffers
 *    the entire page output and resolves [newsletter_signup] once the page
 *    is fully rendered. Only runs for front-end HTML responses (never
 *    admin/AJAX/CLI — see the guard below).
 */

if (defined('NL_INIT_LOADED')) return;
define('NL_INIT_LOADED', true);

require_once __DIR__ . '/newsletter-functions.php';
require_once __DIR__ . '/newsletter-i18n.php';

// Only needed when the plugin's admin page is actually being rendered
// (admin/plugin-page.php calls newsletter_render_admin_page()). Loaded
// unconditionally here rather than lazily, since it's a single lightweight
// require and keeping the contract function always available avoids a
// fragile function_exists() dance in plugin-page.php.
require_once __DIR__ . '/admin/admin-page.php';

// ─── 1. Admin menu entry ────────────────────────────────────────────────────
if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        pl_register_admin_menu(
            'newsletter',
            nl_t('nl_admin_title', 'Newsletter', 'admin'),
            nl_admin_page_url(),
            '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>'
        );
    });
}

// ─── 2. Dashboard widget ────────────────────────────────────────────────────
// Reuses .nl-a-* design tokens from newsletter-admin-embed.css (linked here
// since admin_dashboard has no extra_head slot). nl_count_by_status() reads
// only the lightweight subscribers index — cheap enough for an admin page load.
if (function_exists('pl_on_admin_dashboard')) {
    pl_on_admin_dashboard(function () {
        if (empty(nl_load_settings()['show_dashboard_widget'])) return;

        $counts = nl_count_by_status();

        $cssPath = __DIR__ . '/assets/css/newsletter-admin-embed.css';
        $cssV    = @filemtime($cssPath) ?: time();
        ?>
        <link rel="stylesheet" href="<?php echo hsc(nl_plugin_base_url() . 'assets/css/newsletter-admin-embed.css'); ?>?v=<?php echo $cssV; ?>">
        <div class="dashboard-panel dashboard-panel--sm nl-dash-widget">
            <div class="nl-dash-widget-header">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <?php echo hsc(nl_t('nl_dashboard_widget_title', 'Newsletter', 'admin')); ?>
                </h3>
                <a href="<?php echo hsc(nl_admin_page_url()); ?>" class="nl-dash-more"><?php echo hsc(nl_t('nl_view_all', 'View all →', 'admin')); ?></a>
            </div>
            <div class="nl-dash-kpi-row">
                <div class="nl-dash-kpi">
                    <div class="nl-dash-kpi-value"><?php echo (int)$counts['confirmed']; ?></div>
                    <div class="nl-dash-kpi-label"><?php echo hsc(nl_t('nl_stat_confirmed', 'Confirmed subscribers', 'admin')); ?></div>
                </div>
                <div class="nl-dash-kpi">
                    <div class="nl-dash-kpi-value"><?php echo (int)$counts['pending']; ?></div>
                    <div class="nl-dash-kpi-label"><?php echo hsc(nl_t('nl_stat_pending', 'Awaiting confirmation', 'admin')); ?></div>
                </div>
            </div>
        </div>
        <?php
    });
}

// ─── 3. Front-end shortcode resolution ─────────────────────────────────────
// strpos(...) !== false (not === 0) because the CMS may live in a
// sub-directory, so '/newsletter/' will not necessarily be the first path
// segment.
$__nl_isCli    = PHP_SAPI === 'cli';
$__nl_isPlugin = strpos($_SERVER['REQUEST_URI'] ?? '', '/newsletter/') !== false;
$__nl_cfgFile  = dirname(__DIR__, 2) . '/config.json';
$__nl_adminDir = 'admin';
if (file_exists($__nl_cfgFile)) {
    $__nl_cfg = json_decode(file_get_contents($__nl_cfgFile), true);
    if (is_array($__nl_cfg) && !empty($__nl_cfg['admin_dir'])) {
        $__nl_adminDir = $__nl_cfg['admin_dir'];
    }
    unset($__nl_cfg);
}
unset($__nl_cfgFile);
$__nl_isAdmin = strpos($_SERVER['REQUEST_URI'] ?? '', '/' . $__nl_adminDir . '/') !== false;

// llms.txt/llms-full.txt render page content as plain text via
// render_content_html() + strip_tags() (see core/llms.php, core/llms-full.php) —
// the literal shortcode marker survives that pipeline (strip_tags() doesn't
// touch it) and would otherwise still be caught below, splicing this plugin's
// <link>/<script> tags into a text/plain response.
$__nl_isLlms = (bool) preg_match('#/llms(-full)?\.txt(?:\?|$)#', $_SERVER['REQUEST_URI'] ?? '');

if (!$__nl_isCli && !$__nl_isPlugin && !$__nl_isAdmin && !$__nl_isLlms) {
    require_once __DIR__ . '/newsletter-shortcode.php';

    ob_start(function (string $html): string {
        if (strpos($html, '[newsletter_signup]') === false) {
            return $html;
        }

        // Only ever splice assets into an actual HTML document. A buffer
        // with no </head> isn't one (JSON, XML, plain text, …) — leave it
        // untouched rather than injecting into it.
        if (stripos($html, '</head>') === false) {
            return $html;
        }

        $html = str_replace('[newsletter_signup]', nl_render_form_html(), $html);

        // Inject CSS/JS just before </head> — only happens on pages that use
        // the shortcode, so the asset cost never lands on unrelated pages.
        $assets = nl_render_assets();
        $html = preg_replace('/<\/head>/i', $assets . '</head>', $html, 1);

        return $html;
    });
}

unset($__nl_isCli, $__nl_isPlugin, $__nl_isAdmin, $__nl_isLlms);
