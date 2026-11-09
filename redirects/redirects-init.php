<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Redirects Plugin — Init
 *
 * Two responsibilities:
 * 1. Register rd_maybe_redirect() on the core's generic 'after_routing'
 *    hook (see plugin-api.php) — fired from index.php right after
 *    parseRequestUri() resolves the route, with whether the request is a
 *    genuine 404 passed as the hook argument. index.php never names this
 *    plugin directly.
 * 2. Register the admin sidebar entry ('admin_menu' hook, see plugin-api.php).
 */

if (defined('RD_INIT_LOADED')) return;
define('RD_INIT_LOADED', true);

require_once __DIR__ . '/redirects-functions.php';
require_once __DIR__ . '/redirects-i18n.php';

// Only needed when the plugin's admin page is actually being rendered
// (admin/index.php's plugin_page router calls redirects_render_admin_page()).
// Loaded unconditionally rather than lazily — a single lightweight require,
// and it keeps the contract function always available.
require_once __DIR__ . '/admin/admin-page.php';

if (function_exists('pl_add_hook')) {
    pl_add_hook('after_routing', function ($isGenuine404) {
        rd_maybe_redirect((bool)$isGenuine404);
    });
}

if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        pl_register_admin_menu(
            'redirects',
            rd_t('rd_admin_title', 'Redirects', 'admin'),
            rd_admin_page_url(),
            '<path d="M17 2l4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>'
        );
    });
}