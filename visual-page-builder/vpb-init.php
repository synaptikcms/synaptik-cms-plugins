<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

if (defined('VPB_INIT_LOADED')) return;
define('VPB_INIT_LOADED', true);

require_once __DIR__ . '/vpb-functions.php';
require_once __DIR__ . '/vpb-i18n.php';
require_once __DIR__ . '/admin/admin-page.php';
require_once __DIR__ . '/vpb-shortcuts.php';

if (function_exists('pl_add_filter')) {
    pl_add_filter('render_content_html', 'vpb_generic_render_filter');
}

if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        pl_register_admin_menu(
            'visual-page-builder',
            vpb_t('vpb_admin_title', 'Sections'),
            vpb_admin_page_url(),
            '<rect x="3" y="3" width="18" height="6" rx="1"/><rect x="3" y="12" width="18" height="9" rx="1"/>'
        );
    });
}
