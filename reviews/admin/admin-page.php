<?php
/**
 * Reviews Plugin — Admin Page Renderer
 *
 * Exposes reviews_render_admin_page($view): array, the contract required
 * by admin/plugin-page.php. Single view — the review list with an
 * inline add/edit form (no separate 'compose' route needed, unlike
 * Newsletter, since a review is a handful of short fields rather than a
 * rich-text document).
 *
 * admin/actions.php (create/update/delete/reorder review, save settings)
 * remains a standalone POST-only endpoint — it never renders HTML.
 */

if (defined('RV_ADMIN_PAGE_LOADED')) return;
define('RV_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../reviews-functions.php';
require_once __DIR__ . '/../reviews-i18n.php';
require_once __DIR__ . '/../reviews-security.php';

/**
 * Base URL for the plugin's admin page (core's
 * admin/index.php?action=plugin_page router).
 *
 * Absolute path (not relative to the current script) — this URL is used
 * both from pages physically inside /admin/ and from a return_url field
 * submitted to actions.php, which lives under /plugins/reviews/admin/. A
 * relative "index.php?..." resolves correctly from the former but not the
 * latter (the browser would resolve it against /plugins/reviews/admin/
 * and 404) — so this always returns the full absolute path instead.
 */
function rv_admin_page_url(array $extraParams = []): string
{
    $params = array_merge(['action' => 'plugin_page', 'slug' => 'reviews', 'view' => 'dashboard'], $extraParams);

    $settingsFile = dirname(__DIR__, 3) . '/config.json';
    $adminDir     = 'admin';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['admin_dir'])) {
            $adminDir = $decoded['admin_dir'];
        }
    }

    return rv_site_base_url() . $adminDir . '/index.php?' . http_build_query($params);
}

function rv_admin_actions_url(): string
{
    return rv_plugin_base_url() . 'admin/actions.php';
}

/**
 * Plugin contract entry point — see admin/plugin-page.php for the expected
 * return shape.
 */
function reviews_render_admin_page(string $view): array
{
    ob_start();
    require __DIR__ . '/templates/dashboard.php';
    $html = ob_get_clean();

    $embedCss = __DIR__ . '/../assets/css/reviews-admin-embed.css';
    $adminJs  = __DIR__ . '/../assets/js/reviews-admin.js';

    $extraHead  = '<link rel="stylesheet" href="' . htmlspecialchars(rv_plugin_base_url() . 'assets/css/reviews-admin-embed.css') . '?v=' . @filemtime($embedCss) . '">' . "\n";
    $extraHead .= '<script src="' . htmlspecialchars(rv_plugin_base_url() . 'assets/js/reviews-admin.js') . '?v=' . @filemtime($adminJs) . '" defer></script>' . "\n";

    return [
        'title'      => rv_t('rv_admin_title', 'Reviews', 'admin'),
        'html'       => $html,
        'extra_head' => $extraHead,
    ];
}
