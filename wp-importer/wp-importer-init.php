<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * WordPress Importer — Init
 *
 * Admin-only plugin: registers the sidebar entry and loads the admin page
 * renderer. No front-end code runs when this plugin is active.
 */

if (defined('WPI_INIT_LOADED')) return;
define('WPI_INIT_LOADED', true);

require_once __DIR__ . '/wp-importer-i18n.php';
require_once __DIR__ . '/admin/admin-page.php';

if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        pl_register_admin_menu(
            'wp-importer',
            wpi_t('wpi_admin_title', 'WP Importer', 'admin'),
            wpi_admin_page_url(),
            '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>'
        );
    });
}
