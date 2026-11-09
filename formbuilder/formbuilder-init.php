<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Form Builder Plugin — Init
 *
 * Two independent responsibilities, both guarded so they only do work in
 * the right context:
 *
 * 1. Admin menu registration ('admin_menu' hook, see plugin-api.php).
 *
 * 2. Front-end [form id="x"] shortcode resolution — the core's
 *    render_content_html() shortcode pipeline is closed (no filter hook
 *    runs inside it), so this plugin buffers the entire page output and
 *    resolves every [form ...] occurrence once the page is fully
 *    rendered, mirroring the Booking plugin's approach.
 */

if (defined('FB_INIT_LOADED')) return;
define('FB_INIT_LOADED', true);

require_once __DIR__ . '/formbuilder-functions.php';
require_once __DIR__ . '/formbuilder-i18n.php';

// Only needed when the plugin's admin page is actually being rendered.
require_once __DIR__ . '/admin/admin-page.php';

// ─── 1. Admin menu entry ────────────────────────────────────────────────────
if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        pl_register_admin_menu(
            'formbuilder',
            fb_t('fb_admin_title', 'Form Builder', 'admin'),
            fb_admin_page_url('forms'),
            '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 8h10"/><path d="M7 12h10"/><path d="M7 16h6"/>'
        );
    });
}

// ─── 2. Front-end shortcode resolution ─────────────────────────────────────
$__fb_isCli    = PHP_SAPI === 'cli';
$__fb_isPlugin = strpos($_SERVER['REQUEST_URI'] ?? '', '/formbuilder/') !== false;

// The admin folder is renamable at install time (admin_dir in config.json),
// so matching a hardcoded '/admin/' would silently fail to detect the admin
// context on any site where it was renamed. Read the real folder name instead,
// the same way fb_admin_page_url() and bootstrap.php already do.
$__fb_cmsRoot      = dirname(__DIR__, 2); // plugins/formbuilder/ -> plugins/ -> CMS root
$__fb_settingsFile = $__fb_cmsRoot . '/config.json';
$__fb_adminDir     = 'admin';
if (file_exists($__fb_settingsFile)) {
    $__fb_decoded = json_decode(file_get_contents($__fb_settingsFile), true);
    if (is_array($__fb_decoded) && !empty($__fb_decoded['admin_dir'])) {
        $__fb_adminDir = $__fb_decoded['admin_dir'];
    }
}
$__fb_isAdmin = strpos($_SERVER['REQUEST_URI'] ?? '', '/' . $__fb_adminDir . '/') !== false;

// llms.txt/llms-full.txt render page content as plain text via
// render_content_html() + strip_tags() (see core/llms.php, core/llms-full.php) —
// the literal shortcode marker survives that pipeline (strip_tags() doesn't
// touch it) and would otherwise still be caught below, splicing this plugin's
// <link>/<script> tags into a text/plain response.
$__fb_isLlms = (bool) preg_match('#/llms(-full)?\.txt(?:\?|$)#', $_SERVER['REQUEST_URI'] ?? '');

if (!$__fb_isCli && !$__fb_isPlugin && !$__fb_isAdmin && !$__fb_isLlms) {
    require_once __DIR__ . '/formbuilder-shortcode.php';

    ob_start(function (string $html): string {
        if (strpos($html, '[form ') === false && strpos($html, '[form]') === false) {
            return $html;
        }

        $found = false;
        $html  = preg_replace_callback(
            '/\[form\s+id=["\']([a-z0-9-]{1,64})["\']\s*\]/i',
            function ($matches) use (&$found) {
                $found = true;
                return fb_render_form_html($matches[1]);
            },
            $html
        );

        if (!$found) return $html;

        // Only ever splice assets into an actual HTML document. A buffer
        // with no </head> isn't one (JSON, XML, plain text, …) — leave it
        // untouched rather than injecting into it.
        if (stripos($html, '</head>') === false) {
            return $html;
        }

        $assets = fb_render_assets();
        $html = preg_replace('/<\/head>/i', $assets . '</head>', $html, 1);

        return $html;
    });
}

unset($__fb_isCli, $__fb_isPlugin, $__fb_isAdmin, $__fb_isLlms, $__fb_cmsRoot, $__fb_settingsFile, $__fb_adminDir, $__fb_decoded);
