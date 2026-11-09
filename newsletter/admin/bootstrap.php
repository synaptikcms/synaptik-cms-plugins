<?php
/**
 * Newsletter Plugin — Admin Bootstrap
 *
 * Reuses the CMS core's own admin session ($_SESSION['admin']) rather than
 * implementing a separate login system. Used only by admin/actions.php (a
 * standalone POST endpoint). The plugin's actual admin page is rendered
 * through the core layout via admin/plugin-page.php →
 * newsletter_render_admin_page(), which loads its own dependencies through
 * newsletter-init.php — this file is not part of that path.
 */

if (defined('NL_ADMIN_BOOTSTRAP_LOADED')) return;
define('NL_ADMIN_BOOTSTRAP_LOADED', true);

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
    $__nl_cmsRoot      = dirname(__DIR__, 3); // admin/ -> newsletter/ -> plugins/ -> CMS root
    $__nl_settingsFile = $__nl_cmsRoot . '/config.json';
    $__nl_adminDir     = 'admin';
    if (file_exists($__nl_settingsFile)) {
        $__nl_decoded = json_decode(file_get_contents($__nl_settingsFile), true);
        if (is_array($__nl_decoded) && !empty($__nl_decoded['admin_dir'])) {
            $__nl_adminDir = $__nl_decoded['admin_dir'];
        }
    }
    $__sessionConfig = $__nl_cmsRoot . '/' . $__nl_adminDir . '/includes/session-config.php';
    if (file_exists($__sessionConfig)) {
        require_once $__sessionConfig;
    }
    unset($__nl_cmsRoot, $__nl_settingsFile, $__nl_adminDir, $__nl_decoded, $__sessionConfig);

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    ]);
    session_start();
}

require_once dirname(__DIR__) . '/newsletter-functions.php';
require_once dirname(__DIR__) . '/newsletter-i18n.php';
require_once dirname(__DIR__) . '/newsletter-security.php';
require_once dirname(__DIR__) . '/newsletter-mail.php';
require_once __DIR__ . '/admin-page.php'; // nl_admin_page_url(), nl_admin_actions_url()

// Mirrors core's admin_is_logged_in() 2-hour inactivity timeout — this file
// is a standalone entry point, not routed through admin/index.php, so it
// can't call the core function directly and must enforce the same expiry
// itself instead of only checking the flag.
function nl_admin_is_logged_in(): bool
{
    if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) return false;
    if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 7200) return false;
    $_SESSION['admin_last_activity'] = time();
    return true;
}

function nl_admin_require_login(): void
{
    if (nl_admin_is_logged_in()) return;

    http_response_code(403);
    exit('Not authorized. Please log in to the CMS admin panel.');
}
