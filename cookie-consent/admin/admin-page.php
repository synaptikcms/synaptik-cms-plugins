<?php
/**
 * Cookie Consent Plugin — Admin Page Renderer
 *
 * Exposes cookie_consent_render_admin_page($view): array, the contract
 * required by admin/index.php's plugin_page router. Single view:
 * 'dashboard' — one form covering all settings (general, appearance,
 * categories, texts).
 */

if (defined('CC_ADMIN_PAGE_LOADED')) return;
define('CC_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../cookie-consent-functions.php';
require_once __DIR__ . '/../cookie-consent-i18n.php';

/**
 * Plugin contract entry point — see admin/plugin-page.php router for the
 * expected return shape.
 */
function cookie_consent_render_admin_page(string $view): array
{
    ob_start();
    require __DIR__ . '/templates/dashboard.php';
    $html = ob_get_clean();

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(cc_asset_url('assets/css/cookie-consent-admin-embed.css')); ?>">
    <?php
    $extraHead = ob_get_clean();

    return [
        'title'      => cc_t('cc_admin_title', 'Cookie Consent', 'admin'),
        'html'       => $html,
        'extra_head' => $extraHead,
    ];
}
