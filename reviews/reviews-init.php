<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Reviews Plugin — Init
 *
 * Two independent responsibilities, both guarded so they only do work in
 * the right context:
 *
 * 1. Admin menu registration ('admin_menu' hook, see plugin-api.php) — the
 *    sidebar entry is registered here whenever the plugin is loaded in an
 *    admin context.
 *
 * 2. Front-end [reviews_carousel] / [reviews_grid] shortcode resolution —
 *    the core's render_content_html() shortcode pipeline is closed (no
 *    filter hook runs inside it), so there is no clean way to register a
 *    new [shortcode] without editing core files. Instead, this plugin
 *    buffers the entire page output and resolves both shortcodes once the
 *    page is fully rendered. Only runs for front-end HTML responses (never
 *    admin/AJAX/CLI — see the guard below).
 */

if (defined('RV_INIT_LOADED')) return;
define('RV_INIT_LOADED', true);

require_once __DIR__ . '/reviews-functions.php';
require_once __DIR__ . '/reviews-i18n.php';

// Only needed when the plugin's admin page is actually being rendered
// (admin/plugin-page.php calls reviews_render_admin_page()). Loaded
// unconditionally here rather than lazily — a single lightweight require
// keeps the contract function always available and avoids a fragile
// function_exists() dance in plugin-page.php.
require_once __DIR__ . '/admin/admin-page.php';

// ─── 1. Admin menu entry ────────────────────────────────────────────────────
if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        pl_register_admin_menu(
            'reviews',
            rv_t('rv_admin_title', 'Reviews', 'admin'),
            rv_admin_page_url(),
            '<path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>'
        );
    });
}

// ─── 2. Front-end shortcode resolution ─────────────────────────────────────
// strpos(...) !== false (not === 0) because the CMS may live in a
// sub-directory, so '/reviews/' will not necessarily be the first path
// segment.
$__rv_isCli    = PHP_SAPI === 'cli';
$__rv_isPlugin = strpos($_SERVER['REQUEST_URI'] ?? '', '/reviews/') !== false;
$__rv_cfgFile  = dirname(__DIR__, 2) . '/config.json';
$__rv_adminDir = 'admin';
if (file_exists($__rv_cfgFile)) {
    $__rv_cfg = json_decode(file_get_contents($__rv_cfgFile), true);
    if (is_array($__rv_cfg) && !empty($__rv_cfg['admin_dir'])) {
        $__rv_adminDir = $__rv_cfg['admin_dir'];
    }
    unset($__rv_cfg);
}
unset($__rv_cfgFile);
$__rv_isAdmin = strpos($_SERVER['REQUEST_URI'] ?? '', '/' . $__rv_adminDir . '/') !== false;

// llms.txt/llms-full.txt render page content as plain text via
// render_content_html() + strip_tags() (see core/llms.php, core/llms-full.php) —
// the literal shortcode marker survives that pipeline (strip_tags() doesn't
// touch it) and would otherwise still be caught below, splicing this plugin's
// <link>/<script> tags into a text/plain response.
$__rv_isLlms = (bool) preg_match('#/llms(-full)?\.txt(?:\?|$)#', $_SERVER['REQUEST_URI'] ?? '');

if (!$__rv_isCli && !$__rv_isPlugin && !$__rv_isAdmin && !$__rv_isLlms) {
    require_once __DIR__ . '/reviews-shortcode.php';

    ob_start(function (string $html): string {
        $hasCarousel = strpos($html, '[reviews_carousel') !== false;
        $hasGrid     = strpos($html, '[reviews_grid') !== false;

        if (!$hasCarousel && !$hasGrid) {
            return $html;
        }

        // Only ever splice assets into an actual HTML document. A buffer
        // with no </head> isn't one (JSON, XML, plain text, …) — leave it
        // untouched rather than injecting into it.
        if (stripos($html, '</head>') === false) {
            return $html;
        }

        if ($hasCarousel) {
            $html = preg_replace_callback(
                '/\[reviews_carousel([^\]]*)\]/i',
                function ($m) { return rv_render_reviews_html('carousel', rv_parse_shortcode_attrs($m[1])); },
                $html
            );
        }

        if ($hasGrid) {
            $html = preg_replace_callback(
                '/\[reviews_grid([^\]]*)\]/i',
                function ($m) { return rv_render_reviews_html('grid', rv_parse_shortcode_attrs($m[1])); },
                $html
            );
        }

        // Inject CSS/JS just before </head> — only happens on pages that use
        // one of the shortcodes, so the asset cost never lands on
        // unrelated pages.
        $assets = rv_render_assets();
        $html = preg_replace('/<\/head>/i', $assets . '</head>', $html, 1);

        return $html;
    });
}

unset($__rv_isCli, $__rv_isPlugin, $__rv_isAdmin, $__rv_isLlms);
