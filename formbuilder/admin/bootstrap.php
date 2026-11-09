<?php
/**
 * Form Builder Plugin — Admin Bootstrap
 *
 * Reuses the CMS core's own admin session ($_SESSION['admin']) rather than
 * implementing a separate login system. Used only by admin/actions.php (a
 * standalone POST endpoint) — the plugin's actual admin page is rendered
 * through the core layout via admin/index.php?action=plugin_page, which
 * loads its own dependencies through formbuilder-init.php.
 */

if (defined('FB_ADMIN_BOOTSTRAP_LOADED')) return;
define('FB_ADMIN_BOOTSTRAP_LOADED', true);

if (session_status() === PHP_SESSION_NONE) {
    // Unique session cookie name per install — read from config.json
    // since the admin folder may have been renamed after install.
    $__fb_cmsRoot      = dirname(__DIR__, 3); // admin/ -> formbuilder/ -> plugins/ -> CMS root
    $__fb_settingsFile = $__fb_cmsRoot . '/config.json';
    $__fb_adminDir     = 'admin';
    if (file_exists($__fb_settingsFile)) {
        $__fb_decoded = json_decode(file_get_contents($__fb_settingsFile), true);
        if (is_array($__fb_decoded) && !empty($__fb_decoded['admin_dir'])) {
            $__fb_adminDir = $__fb_decoded['admin_dir'];
        }
    }
    $__sessionConfig = $__fb_cmsRoot . '/' . $__fb_adminDir . '/includes/session-config.php';
    if (file_exists($__sessionConfig)) {
        require_once $__sessionConfig;
    }
    unset($__fb_cmsRoot, $__fb_settingsFile, $__fb_adminDir, $__fb_decoded, $__sessionConfig);

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    ]);
    session_start();
}

require_once dirname(__DIR__) . '/formbuilder-functions.php';
require_once dirname(__DIR__) . '/formbuilder-i18n.php';
require_once dirname(__DIR__) . '/formbuilder-data-layer.php';
require_once dirname(__DIR__) . '/formbuilder-security.php';
require_once __DIR__ . '/admin-page.php'; // fb_admin_page_url()

// Mirrors core's admin_is_logged_in() 2-hour inactivity timeout — this file
// is a standalone entry point, not routed through admin/index.php, so it
// can't call the core function directly and must enforce the same expiry
// itself instead of only checking the flag.
function fb_admin_is_logged_in(): bool
{
    if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) return false;
    if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 7200) return false;
    $_SESSION['admin_last_activity'] = time();
    return true;
}

function fb_admin_require_login(): void
{
    if (fb_admin_is_logged_in()) return;

    http_response_code(403);
    exit('Not authorized. Please log in to the CMS admin panel.');
}
