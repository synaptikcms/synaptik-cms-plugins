<?php
/**
 * Redirects Plugin — Admin Actions
 *
 * Handles all state-changing admin operations (create/update/delete a
 * redirect, save the 404-fallback setting). Every action requires the
 * core admin session and a valid CSRF token. Classic form POST + redirect
 * back to the dashboard — no AJAX/batch flow needed here (unlike the
 * Newsletter plugin's send actions), redirect lists stay small.
 */

// Ensures this install's admin session cookie has a unique name, so a
// demo/sub-folder install on the same domain never shares login state
// with the main install (see admin/includes/session-config.php). The
// admin folder's real name may have been renamed after install (either
// via install.php or manually), so it's read from config.json rather
// than assumed to be literally 'admin'.
$__rd_cmsRoot      = dirname(__DIR__, 3); // admin/ -> redirects/ -> plugins/ -> CMS root
$__rd_settingsFile = $__rd_cmsRoot . '/config.json';
$__rd_adminDir     = 'admin';
if (file_exists($__rd_settingsFile)) {
    $__rd_decoded = json_decode(file_get_contents($__rd_settingsFile), true);
    if (is_array($__rd_decoded) && !empty($__rd_decoded['admin_dir'])) {
        $__rd_adminDir = $__rd_decoded['admin_dir'];
    }
}
$__sessionConfig = $__rd_cmsRoot . '/' . $__rd_adminDir . '/includes/session-config.php';
if (file_exists($__sessionConfig)) {
    require_once $__sessionConfig;
}
unset($__rd_cmsRoot, $__rd_settingsFile, $__rd_adminDir, $__rd_decoded, $__sessionConfig);

session_start();

require_once dirname(__DIR__) . '/redirects-functions.php';
require_once dirname(__DIR__) . '/redirects-i18n.php';
require_once __DIR__ . '/admin-page.php';

// Mirrors core's admin_is_logged_in() 2-hour inactivity timeout — this file
// is a standalone entry point, not routed through admin/index.php, so it
// can't call the core function directly and must enforce the same expiry
// itself instead of only checking the flag.
function rd_admin_is_logged_in(): bool
{
    if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) return false;
    if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 7200) return false;
    $_SESSION['admin_last_activity'] = time();
    return true;
}

if (!rd_admin_is_logged_in()) {
    http_response_code(403);
    exit('Not authorized. Please log in to the CMS admin panel.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . rd_admin_page_url());
    exit;
}

if (!rd_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'create_redirect':
    case 'update_redirect':
        $from       = rd_normalize_path(trim($_POST['from'] ?? ''));
        $to         = trim($_POST['to'] ?? '');
        $statusCode = (int)($_POST['status_code'] ?? 301);
        $statusCode = in_array($statusCode, [301, 302], true) ? $statusCode : 301;
        $id         = trim($_POST['id'] ?? '');

        // "to" may legitimately be an external URL (migrating to a new
        // domain) or an absolute site path — only normalize it when it's
        // a bare path, never force it through rd_normalize_path()'s
        // trailing-slash convention if it's a full external URL.
        $isExternal = (bool)preg_match('#^https?://#i', $to);
        if (!$isExternal) {
            $to = rd_normalize_path($to);
        }

        if ($from === '' || $from === '/' || $to === '') {
            header('Location: ' . rd_admin_page_url(['msg' => 'invalid']));
            exit;
        }

        // A redirect pointing to itself is either a mistake or a redirect
        // loop waiting to happen — reject it outright.
        if (!$isExternal && $from === $to) {
            header('Location: ' . rd_admin_page_url(['msg' => 'self_redirect']));
            exit;
        }

        if ($action === 'create_redirect') {
            if (rd_from_exists($from)) {
                header('Location: ' . rd_admin_page_url(['msg' => 'duplicate']));
                exit;
            }
            rd_create_redirect($from, $to, $statusCode);
            header('Location: ' . rd_admin_page_url(['msg' => 'created']));
        } else {
            if ($id === '' || rd_get_redirect($id) === null) {
                header('Location: ' . rd_admin_page_url(['msg' => 'not_found']));
                exit;
            }
            if (rd_from_exists($from, $id)) {
                header('Location: ' . rd_admin_page_url(['msg' => 'duplicate']));
                exit;
            }
            rd_update_redirect($id, $from, $to, $statusCode);
            header('Location: ' . rd_admin_page_url(['msg' => 'updated']));
        }
        exit;

    case 'delete_redirect':
        $id = trim($_POST['id'] ?? '');
        rd_delete_redirect($id);
        header('Location: ' . rd_admin_page_url(['msg' => 'deleted']));
        exit;

    case 'save_settings':
        $settings = rd_load_settings();
        $settings['fallback_404_home'] = !empty($_POST['fallback_404_home']);
        rd_save_settings($settings);
        header('Location: ' . rd_admin_page_url(['msg' => 'saved']));
        exit;

    default:
        header('Location: ' . rd_admin_page_url());
        exit;
}
