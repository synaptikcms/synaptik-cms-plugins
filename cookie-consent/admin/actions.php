<?php
/**
 * Cookie Consent Plugin — Admin Actions
 *
 * Single action: save the plugin's settings. Requires the core admin
 * session and a valid CSRF token, same pattern as Redirects/Maintenance.
 */

// Ensures this install's admin session cookie has a unique name (see the
// same block in the Redirects and Maintenance plugins for context).
$__cc_cmsRoot      = dirname(__DIR__, 3); // admin/ -> cookie-consent/ -> plugins/ -> CMS root
$__cc_settingsFile = $__cc_cmsRoot . '/config.json';
$__cc_adminDir     = 'admin';
if (file_exists($__cc_settingsFile)) {
    $__cc_decoded = json_decode(file_get_contents($__cc_settingsFile), true);
    if (is_array($__cc_decoded) && !empty($__cc_decoded['admin_dir'])) {
        $__cc_adminDir = $__cc_decoded['admin_dir'];
    }
}
$__sessionConfig = $__cc_cmsRoot . '/' . $__cc_adminDir . '/includes/session-config.php';
if (file_exists($__sessionConfig)) {
    require_once $__sessionConfig;
}
unset($__cc_cmsRoot, $__cc_settingsFile, $__cc_adminDir, $__cc_decoded, $__sessionConfig);

session_start();

require_once dirname(__DIR__) . '/cookie-consent-functions.php';
require_once dirname(__DIR__) . '/cookie-consent-i18n.php';
require_once __DIR__ . '/admin-page.php';

// Mirrors core's admin_is_logged_in() 2-hour inactivity timeout — this file
// is a standalone entry point, not routed through admin/index.php, so it
// can't call the core function directly and must enforce the same expiry
// itself instead of only checking the flag.
function cc_admin_is_logged_in(): bool
{
    if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) return false;
    if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 7200) return false;
    $_SESSION['admin_last_activity'] = time();
    return true;
}

if (!cc_admin_is_logged_in()) {
    http_response_code(403);
    exit('Not authorized. Please log in to the CMS admin panel.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . cc_admin_page_url());
    exit;
}

if (!cc_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$action = $_POST['action'] ?? '';

if ($action === 'save_settings') {
    $settings = cc_load_settings();

    $settings['enabled']            = !empty($_POST['enabled']);
    $settings['position']           = ($_POST['position'] ?? 'bottom') === 'center' ? 'center' : 'bottom';
    $settings['expiration_days']    = max(1, min(3650, (int)($_POST['expiration_days'] ?? 180)));
    $settings['bg_color']           = cc_sanitize_hex($_POST['bg_color']     ?? '#ffffff', '#ffffff');
    $settings['text_color']         = cc_sanitize_hex($_POST['text_color']   ?? '#1a1a1a', '#1a1a1a');
    $settings['accent_color']       = cc_sanitize_hex($_POST['accent_color'] ?? '#3ea34e', '#3ea34e');
    $settings['show_reject_all']    = !empty($_POST['show_reject_all']);
    $settings['category_analytics'] = !empty($_POST['category_analytics']);
    $settings['category_marketing'] = !empty($_POST['category_marketing']);

    // Privacy policy URL — accept either a full https:// URL or a bare
    // site path (e.g. "/privacy/"). Reject anything with a scheme other
    // than http/https to avoid a javascript: or data: URL slipping in.
    $rawUrl = trim($_POST['privacy_policy_url'] ?? '');
    if ($rawUrl === '') {
        $settings['privacy_policy_url'] = '';
    } elseif (preg_match('#^https?://#i', $rawUrl)) {
        $settings['privacy_policy_url'] = $rawUrl;
    } elseif (strpos($rawUrl, '/') === 0) {
        $settings['privacy_policy_url'] = $rawUrl;
    } else {
        // A relative-looking path that does not start with / — treat as
        // invalid rather than guessing what the user meant.
        $settings['privacy_policy_url'] = '';
    }

    cc_save_settings($settings);
    header('Location: ' . cc_admin_page_url(['msg' => 'saved']));
    exit;
}

header('Location: ' . cc_admin_page_url());
exit;
