<?php
/**
 * Reviews Plugin — Admin Bootstrap
 *
 * Reuses the CMS core's own admin session ($_SESSION['admin']) rather than
 * implementing a separate login system. Used only by admin/actions.php (a
 * standalone POST endpoint). The plugin's actual admin page is rendered
 * through the core layout via admin/plugin-page.php →
 * reviews_render_admin_page(), which loads its own dependencies through
 * reviews-init.php — this file is not part of that path.
 */

if (defined('RV_ADMIN_BOOTSTRAP_LOADED')) return;
define('RV_ADMIN_BOOTSTRAP_LOADED', true);

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
    $__rv_cmsRoot      = dirname(__DIR__, 3); // admin/ -> reviews/ -> plugins/ -> CMS root
    $__rv_settingsFile = $__rv_cmsRoot . '/config.json';
    $__rv_adminDir     = 'admin';
    if (file_exists($__rv_settingsFile)) {
        $__rv_decoded = json_decode(file_get_contents($__rv_settingsFile), true);
        if (is_array($__rv_decoded) && !empty($__rv_decoded['admin_dir'])) {
            $__rv_adminDir = $__rv_decoded['admin_dir'];
        }
    }
    $__sessionConfig = $__rv_cmsRoot . '/' . $__rv_adminDir . '/includes/session-config.php';
    if (file_exists($__sessionConfig)) {
        require_once $__sessionConfig;
    }
    unset($__rv_cmsRoot, $__rv_settingsFile, $__rv_adminDir, $__rv_decoded, $__sessionConfig);

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    ]);
    session_start();
}

require_once dirname(__DIR__) . '/reviews-functions.php';
require_once dirname(__DIR__) . '/reviews-i18n.php';
require_once dirname(__DIR__) . '/reviews-security.php';
require_once __DIR__ . '/admin-page.php'; // rv_admin_page_url(), rv_admin_actions_url()

// Mirrors core's admin_is_logged_in() 2-hour inactivity timeout — this file
// is a standalone entry point, not routed through admin/index.php, so it
// can't call the core function directly and must enforce the same expiry
// itself instead of only checking the flag.
function rv_admin_is_logged_in(): bool
{
    if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) return false;
    if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 7200) return false;
    $_SESSION['admin_last_activity'] = time();
    return true;
}

function rv_admin_require_login(): void
{
    if (rv_admin_is_logged_in()) return;

    http_response_code(403);
    exit('Not authorized. Please log in to the CMS admin panel.');
}
