<?php
/**
 * Comments Plugin — Admin Actions
 *
 * Handles all state-changing admin operations (approve/reject/delete a
 * comment, save plugin settings). Every action requires the core admin
 * session and a valid CSRF token. Classic form POST + redirect back to
 * the dashboard — comment volumes on a self-hosted flat-file CMS never
 * justify an AJAX/batch flow.
 */

// Ensures this install's admin session cookie has a unique name — see
// admin/includes/session-config.php. The admin folder's real name may
// have been renamed after install, so it's read from config.json
// rather than assumed to be literally 'admin'.
$__cm_cmsRoot      = dirname(__DIR__, 3); // admin/ -> comments/ -> plugins/ -> CMS root
$__cm_settingsFile = $__cm_cmsRoot . '/config.json';
$__cm_adminDir     = 'admin';
if (file_exists($__cm_settingsFile)) {
    $__cm_decoded = json_decode(file_get_contents($__cm_settingsFile), true);
    if (is_array($__cm_decoded) && !empty($__cm_decoded['admin_dir'])) {
        $__cm_adminDir = $__cm_decoded['admin_dir'];
    }
}
$__sessionConfig = $__cm_cmsRoot . '/' . $__cm_adminDir . '/includes/session-config.php';
if (file_exists($__sessionConfig)) {
    require_once $__sessionConfig;
}
unset($__cm_cmsRoot, $__cm_settingsFile, $__cm_adminDir, $__cm_decoded, $__sessionConfig);

session_start();

require_once dirname(__DIR__) . '/comments-functions.php';
require_once dirname(__DIR__) . '/comments-data-layer.php';
require_once dirname(__DIR__) . '/comments-i18n.php';
require_once dirname(__DIR__) . '/comments-security.php';
require_once __DIR__ . '/admin-page.php';

// Mirrors core's admin_is_logged_in() 2-hour inactivity timeout — this file
// is a standalone entry point, not routed through admin/index.php, so it
// can't call the core function directly (admin-functions.php isn't loaded
// here) and must enforce the same expiry itself instead of only checking
// the flag, which would let an idle session stay authenticated forever.
function cm_admin_is_logged_in(): bool
{
    if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) return false;
    if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 7200) return false;
    $_SESSION['admin_last_activity'] = time();
    return true;
}

if (!cm_admin_is_logged_in()) {
    http_response_code(403);
    exit('Not authorized. Please log in to the CMS admin panel.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . cm_admin_page_url());
    exit;
}

// The core has no session-wide CSRF token for internal admin actions
// (settings.php relies on the authenticated session alone) — admin forms
// here reuse the plugin's own stateless HMAC token instead, the same one
// generated for the public submission form. Matches the Redirects plugin's
// approach for its own admin actions.
if (!cm_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'approve':
        $id = trim($_POST['id'] ?? '');
        cm_set_status($id, 'approved');
        header('Location: ' . cm_admin_page_url(['msg' => 'approved']));
        exit;

    case 'reject':
        $id = trim($_POST['id'] ?? '');
        cm_set_status($id, 'rejected');
        header('Location: ' . cm_admin_page_url(['msg' => 'rejected']));
        exit;

    case 'delete':
        $id = trim($_POST['id'] ?? '');
        cm_delete_comment($id);
        header('Location: ' . cm_admin_page_url(['msg' => 'deleted']));
        exit;

    case 'bulk_action':
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_map('trim', $ids), fn($id) => $id !== ''));

        $bulkAction = $_POST['bulk_type'] ?? '';
        if (!in_array($bulkAction, ['approve', 'reject', 'delete'], true) || empty($ids)) {
            header('Location: ' . cm_admin_page_url(['status' => $_POST['return_status'] ?? 'pending']));
            exit;
        }

        cm_bulk_action($ids, $bulkAction);
        header('Location: ' . cm_admin_page_url([
            'status' => $_POST['return_status'] ?? 'pending',
            'msg'    => 'bulk_' . $bulkAction,
        ]));
        exit;

    case 'save_settings':
        $settings = cm_load_settings();

        $mode = $_POST['moderation_mode'] ?? 'manual';
        $settings['moderation_mode'] = $mode === 'auto' ? 'auto' : 'manual';

        $settings['enabled_types'] = [
            'article' => !empty($_POST['enabled_article']),
            'page'    => !empty($_POST['enabled_page']),
            'project' => !empty($_POST['enabled_project']),
        ];

        $settings['use_hcaptcha'] = !empty($_POST['use_hcaptcha']);
        $settings['show_dashboard_widget'] = !empty($_POST['show_dashboard_widget']);

        // Blacklist: one keyword per line in the textarea, stored as an
        // array, trimmed, empty lines dropped, capped at a sane count so
        // an admin fat-fingering a paste doesn't bloat config.json.
        $rawBlacklist = (string)($_POST['blacklist'] ?? '');
        $lines = array_filter(array_map('trim', explode("\n", $rawBlacklist)), fn($l) => $l !== '');
        $settings['blacklist'] = array_values(array_slice($lines, 0, 200));

        cm_save_settings($settings);
        header('Location: ' . cm_admin_page_url(['view' => 'settings', 'msg' => 'saved']));
        exit;

    default:
        header('Location: ' . cm_admin_page_url());
        exit;
}
