<?php
/**
 * Maintenance Plugin — Admin Actions
 *
 * Single state-changing operation: save settings. Requires the core admin
 * session and a valid CSRF token, then redirects back to the plugin's
 * admin page. Never renders HTML itself — POST-only endpoint.
 */

require_once __DIR__ . '/../maintenance-functions.php';
require_once __DIR__ . '/../maintenance-i18n.php';
require_once __DIR__ . '/admin-page.php';

// The core admin folder's real name may have been renamed after install
// (either via install.php or manually) — read it from config.json
// instead of assuming the literal folder name 'admin', or this include
// silently fails to fatal error on any renamed install.
$__mt_cmsRoot     = dirname(__DIR__, 3); // admin/ -> maintenance/ -> plugins/ -> CMS root
$__mt_settingsFile = $__mt_cmsRoot . '/config.json';
$__mt_adminDir     = 'admin';
if (file_exists($__mt_settingsFile)) {
    $__mt_decoded = json_decode(file_get_contents($__mt_settingsFile), true);
    if (is_array($__mt_decoded) && !empty($__mt_decoded['admin_dir'])) {
        $__mt_adminDir = $__mt_decoded['admin_dir'];
    }
}
require_once $__mt_cmsRoot . '/' . $__mt_adminDir . '/includes/session-config.php';
unset($__mt_cmsRoot, $__mt_settingsFile, $__mt_adminDir, $__mt_decoded);

session_start();

// Mirrors core's admin_is_logged_in() 2-hour inactivity timeout — this file
// is a standalone entry point, not routed through admin/index.php, so it
// can't call the core function directly and must enforce the same expiry
// itself instead of only checking the flag.
$__mt_loggedIn = !empty($_SESSION['admin']) && $_SESSION['admin'] === true;
if ($__mt_loggedIn && isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 7200) {
    $__mt_loggedIn = false;
}
if (!$__mt_loggedIn) {
    header('Location: ' . mt_admin_login_url());
    exit;
}
$_SESSION['admin_last_activity'] = time();
unset($__mt_loggedIn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . mt_admin_page_url());
    exit;
}

if (!mt_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$settings = mt_load_settings();

$settings['enabled'] = !empty($_POST['enabled']);

$title = trim(strip_tags($_POST['title'] ?? ''));
$settings['title'] = $title;

$message = trim(strip_tags($_POST['message'] ?? ''));
$settings['message'] = $message !== '' ? $message : mt_default_settings()['message'];

// Colors: validated against a strict #rrggbb pattern (the <input type="color">
// browser widget always submits this format) rather than trusted as-is —
// an invalid value silently falls back to the current setting instead of
// writing malformed CSS into mt_render_and_exit()'s inline <style>.
foreach (['bg_color', 'text_color', 'accent_color'] as $_mt_colorField) {
    $_mt_color = trim($_POST[$_mt_colorField] ?? '');
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $_mt_color)) {
        $settings[$_mt_colorField] = $_mt_color;
    }
}

// Logo image — same three-way precedence as the core's site logo/favicon
// fields: a freshly uploaded file wins, then an existing file picked from
// the library, then an explicit removal request, otherwise keep the
// current value untouched.
if (!empty($_FILES['logo_image_file']['name']) && $_FILES['logo_image_file']['error'] === UPLOAD_ERR_OK) {
    $_mt_logoPath = mt_upload_logo_image('logo_image_file');
    if ($_mt_logoPath !== '') $settings['logo_image'] = $_mt_logoPath;
} elseif (!empty($_POST['logo_image_path'])) {
    $settings['logo_image'] = mt_sanitize_logo_path($_POST['logo_image_path']);
} elseif (!empty($_POST['logo_image_remove'])) {
    $settings['logo_image'] = '';
}

mt_save_settings($settings);

header('Location: ' . mt_admin_page_url(['msg' => 'saved']));
exit;
