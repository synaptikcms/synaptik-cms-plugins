<?php
/**
 * Booking Plugin — Admin Bootstrap
 *
 * Reuses the CMS core's own admin session ($_SESSION['admin']) rather than
 * implementing a separate login system — anyone already authenticated in
 * the CMS admin panel is authenticated here too, and logging out of one
 * logs out of both since it's the same PHP session.
 *
 * Used only by admin/actions.php (a standalone POST endpoint). The plugin's
 * actual admin page is rendered through the core layout via
 * admin/plugin-page.php → booking_render_admin_page(), which loads its own
 * dependencies through booking-init.php — this file is not part of that path.
 */

if (defined('BK_ADMIN_BOOTSTRAP_LOADED')) return;
define('BK_ADMIN_BOOTSTRAP_LOADED', true);

// Match the core's session cookie hardening, but only when no session is
// active yet — session_set_cookie_params() after a session has started
// would silently fail to apply and emit a warning.
if (session_status() === PHP_SESSION_NONE) {
    // Ensures this install's admin session cookie has a unique name, so a
    // demo/sub-folder install on the same domain never shares login state
    // with the main install (see admin/includes/session-config.php). The
    // admin folder's real name may have been renamed after install (either
    // via install.php or manually), so it's read from config.json rather
    // than assumed to be literally 'admin'.
    $__bk_cmsRoot      = dirname(__DIR__, 3); // admin/ -> booking/ -> plugins/ -> CMS root
    $__bk_settingsFile = $__bk_cmsRoot . '/config.json';
    $__bk_adminDir     = 'admin';
    if (file_exists($__bk_settingsFile)) {
        $__bk_decoded = json_decode(file_get_contents($__bk_settingsFile), true);
        if (is_array($__bk_decoded) && !empty($__bk_decoded['admin_dir'])) {
            $__bk_adminDir = $__bk_decoded['admin_dir'];
        }
    }
    $__sessionConfig = $__bk_cmsRoot . '/' . $__bk_adminDir . '/includes/session-config.php';
    if (file_exists($__sessionConfig)) {
        require_once $__sessionConfig;
    }
    unset($__bk_cmsRoot, $__bk_settingsFile, $__bk_adminDir, $__bk_decoded, $__sessionConfig);

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    ]);
    session_start();
}

require_once dirname(__DIR__) . '/booking-functions.php';
require_once dirname(__DIR__) . '/booking-i18n.php';
require_once dirname(__DIR__) . '/booking-security.php';
require_once dirname(__DIR__) . '/booking-mail.php';
require_once __DIR__ . '/admin-page.php'; // bk_admin_page_url(), bk_admin_actions_url()

// Mirrors core's admin_is_logged_in() 2-hour inactivity timeout — this file
// is a standalone entry point, not routed through admin/index.php, so it
// can't call the core function directly and must enforce the same expiry
// itself instead of only checking the flag.
function bk_admin_is_logged_in(): bool
{
    if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) return false;
    if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 7200) return false;
    $_SESSION['admin_last_activity'] = time();
    return true;
}

function bk_admin_require_login(): void
{
    if (bk_admin_is_logged_in()) return;

    http_response_code(403);
    exit('Not authorized. Please log in to the CMS admin panel.');
}
