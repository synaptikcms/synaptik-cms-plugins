<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Maintenance Plugin — Init
 *
 * Two responsibilities:
 * 1. Load the front-end block logic (maintenance-block.php) and register
 *    mt_maybe_block() on the core's generic 'early_request' hook (see
 *    plugin-api.php) — fired from index.php as early as possible in the
 *    request, before this plugin (or index.php) needs to know anything
 *    about the other.
 * 2. Register the admin sidebar entry ('admin_menu' hook, see plugin-api.php).
 */

if (defined('MT_INIT_LOADED')) return;
define('MT_INIT_LOADED', true);

require_once __DIR__ . '/maintenance-functions.php';
require_once __DIR__ . '/maintenance-i18n.php';
require_once __DIR__ . '/maintenance-block.php';

// Only needed when the plugin's admin page is actually being rendered
// (admin/index.php's plugin_page router calls maintenance_render_admin_page()).
// Loaded unconditionally rather than lazily — a single lightweight require,
// and it keeps the contract function always available.
require_once __DIR__ . '/admin/admin-page.php';

if (function_exists('pl_add_hook')) {
    pl_add_hook('early_request', function () {
        mt_maybe_block();
    });
}

if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        pl_register_admin_menu(
            'maintenance',
            mt_t('mt_admin_title', 'Maintenance', 'admin'),
            mt_admin_page_url(),
            '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'
        );
    });
}
