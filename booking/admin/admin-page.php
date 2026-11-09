<?php
/**
 * Booking Plugin — Admin Page Renderer
 *
 * Exposes booking_render_admin_page($view): array, the contract required by
 * admin/plugin-page.php. Renders the same four views previously served by
 * the plugin's standalone admin/index.php, but as HTML returned to the core
 * layout instead of echoed directly — so the plugin now appears inside the
 * real admin chrome (sidebar, topbar, footer) rather than as a separate page.
 *
 * admin/actions.php (confirm/refuse/cancel/delete/save settings/availability)
 * remains a standalone POST-only endpoint — it never renders HTML, so it has
 * no reason to go through the core layout.
 */

if (defined('BK_ADMIN_PAGE_LOADED')) return;
define('BK_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../booking-functions.php';
require_once __DIR__ . '/../booking-i18n.php';
require_once __DIR__ . '/../booking-security.php';
require_once __DIR__ . '/../booking-mail.php';

/**
 * Base URL for links within the plugin's admin page (core's
 * admin/index.php?action=plugin_page router), and for the plugin's own
 * standalone actions.php endpoint.
 *
 * Absolute path (not relative to the current script) — this URL is used
 * both from pages physically inside /admin/ (the sidebar, the rendered
 * view itself) and from a return_url field submitted to actions.php, which
 * lives under /plugins/booking/admin/. A relative "index.php?..." resolves
 * correctly from the former but not the latter (the browser would resolve
 * it against /plugins/booking/admin/ and 404) — so this always returns the
 * full absolute path instead.
 */
function bk_admin_page_url(string $view, array $extraParams = []): string
{
    $params = array_merge(['action' => 'plugin_page', 'slug' => 'booking', 'view' => $view], $extraParams);

    $settingsFile = dirname(__DIR__, 3) . '/config.json';
    $adminDir     = 'admin';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['admin_dir'])) {
            $adminDir = $decoded['admin_dir'];
        }
    }

    return bk_site_base_url() . $adminDir . '/index.php?' . http_build_query($params);
}

function bk_admin_actions_url(): string
{
    return bk_plugin_base_url() . 'admin/actions.php';
}

/**
 * Absolute URL to a core admin asset (JS/CSS), independent of which
 * directory the current script lives in. Mirrors the Newsletter plugin's
 * nl_core_admin_asset_url() — used to load the core's WYSIWYG editor
 * (editor-common.js / editor.js / editor-layout.css) on the Emails view.
 */
function bk_core_admin_asset_url(string $relativePath): string
{
    $settingsFile = dirname(__DIR__, 3) . '/config.json';
    $adminDir     = 'admin';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['admin_dir'])) {
            $adminDir = $decoded['admin_dir'];
        }
    }
    $coreAdminPath = dirname(__DIR__, 3) . '/' . $adminDir . '/' . $relativePath;
    $v = @filemtime($coreAdminPath) ?: time();

    return bk_site_base_url() . $adminDir . '/' . $relativePath . '?v=' . $v;
}

/**
 * Plugin contract entry point — see admin/plugin-page.php for the expected
 * return shape.
 */
function booking_render_admin_page(string $view): array
{
    $validViews = ['calendar', 'requests', 'history', 'availability', 'emails', 'settings', 'smtp'];
    if (!in_array($view, $validViews, true)) {
        $view = 'calendar';
    }

    ob_start();

    // Sub-navigation between the plugin's own views, rendered above the
    // view content so it's visible without needing a second sidebar level.
    // Reuses the core admin's own .tabs/.tab components (admin-components.css)
    // instead of a plugin-specific style, so this looks identical to the
    // tabs used elsewhere in the admin (Settings, Alt-Text Assistant, etc.).
    ?>
    <div class="tabs bk-subnav">
        <a href="<?php echo htmlspecialchars(bk_admin_page_url('calendar')); ?>" class="tab <?php echo $view === 'calendar' ? 'active' : ''; ?>"><?php echo htmlspecialchars(bk_t('bk_nav_calendar', 'Calendar', 'admin')); ?></a>
        <a href="<?php echo htmlspecialchars(bk_admin_page_url('requests')); ?>" class="tab <?php echo $view === 'requests' ? 'active' : ''; ?>">
            <?php echo htmlspecialchars(bk_t('bk_nav_requests', 'Requests', 'admin')); ?>
            <?php $bk_pendingCount = bk_count_pending(); if ($bk_pendingCount > 0): ?><span class="bk-tab-badge"><?php echo $bk_pendingCount; ?></span><?php endif; ?>
        </a>
        <a href="<?php echo htmlspecialchars(bk_admin_page_url('history')); ?>" class="tab <?php echo $view === 'history' ? 'active' : ''; ?>"><?php echo htmlspecialchars(bk_t('bk_nav_history', 'History', 'admin')); ?></a>
        <a href="<?php echo htmlspecialchars(bk_admin_page_url('availability')); ?>" class="tab <?php echo $view === 'availability' ? 'active' : ''; ?>"><?php echo htmlspecialchars(bk_t('bk_nav_availability', 'Availability', 'admin')); ?></a>
        <a href="<?php echo htmlspecialchars(bk_admin_page_url('emails')); ?>" class="tab <?php echo $view === 'emails' ? 'active' : ''; ?>"><?php echo htmlspecialchars(bk_t('bk_nav_emails', 'Emails', 'admin')); ?></a>
        <a href="<?php echo htmlspecialchars(bk_admin_page_url('settings')); ?>" class="tab <?php echo $view === 'settings' ? 'active' : ''; ?>"><?php echo htmlspecialchars(bk_t('bk_nav_settings', 'Settings', 'admin')); ?></a>
        <a href="<?php echo htmlspecialchars(bk_admin_page_url('smtp')); ?>" class="tab <?php echo $view === 'smtp' ? 'active' : ''; ?>"><?php echo htmlspecialchars(bk_t('bk_nav_smtp', 'SMTP', 'admin')); ?></a>
    </div>
    <?php

    switch ($view) {
        case 'requests':
            require __DIR__ . '/templates/requests.php';
            break;
        case 'history':
            require __DIR__ . '/templates/history.php';
            break;
        case 'availability':
            require __DIR__ . '/templates/availability.php';
            break;
        case 'emails':
            require __DIR__ . '/templates/emails.php';
            break;
        case 'settings':
            require __DIR__ . '/templates/settings.php';
            break;
        case 'smtp':
            require __DIR__ . '/templates/settings-smtp.php';
            break;
        case 'calendar':
        default:
            require __DIR__ . '/templates/calendar.php';
            break;
    }

    $html = ob_get_clean();

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(bk_plugin_base_url() . 'assets/css/booking-admin-embed.css'); ?>?v=<?php echo @filemtime(__DIR__ . '/../assets/css/booking-admin-embed.css'); ?>">
    <?php if ($view === 'emails'): ?>
    <!-- Reuses the CMS core's own WYSIWYG editor rather than reimplementing
         one — see editor.js for the full toolbar/behavior. Not loaded by
         the core automatically here because this page isn't a
         content-add/edit route (see admin/includes/layout.php's asset
         detection), so it's brought in explicitly. jQuery is already
         loaded globally by layout.php for every admin page. Mirrors the
         Newsletter plugin's compose view. -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(bk_core_admin_asset_url('assets/css/editor-layout.css')); ?>">
    <?php
    // Disable the core's autosave-to-drafts entirely in this context — it
    // targets admin/autosave.php, which is for CMS content items, not email
    // templates. Read by admin-boot.js's generic data-window-var path.
    ?>
    <script type="application/json" data-window-var="AUTOSAVE_ENABLED_BY_SETTINGS">false</script>
    <script type="application/json" data-window-var="CONTENT_FORMAT">"html"</script>
    <script src="<?php echo htmlspecialchars(bk_core_admin_asset_url('assets/js/admin-boot.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(bk_core_admin_asset_url('assets/js/editor-icons.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(bk_core_admin_asset_url('assets/js/editor-common.js')); ?>" defer></script>
    <script src="<?php echo htmlspecialchars(bk_core_admin_asset_url('assets/js/editor.js')); ?>" defer></script>
    <?php endif; ?>
    <?php
    $extraHead = ob_get_clean();

    return [
        'title'      => bk_t('bk_admin_title', 'Booking', 'admin'),
        'html'       => $html,
        'extra_head' => $extraHead,
    ];
}
