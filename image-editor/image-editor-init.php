<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

if (defined('IE_INIT_LOADED')) return;
define('IE_INIT_LOADED', true);

require_once __DIR__ . '/image-editor-functions.php';

if (function_exists('pl_add_filter')) {
    pl_add_filter('admin_file_manager_footer', 'ie_file_manager_footer', 10);
    pl_add_filter('admin_file_manager_action', 'ie_file_manager_action', 10);
}
