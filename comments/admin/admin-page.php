<?php
/**
 * Comments Plugin — Admin Page Renderer
 *
 * Exposes comments_render_admin_page($view): array, the contract required
 * by admin/index.php's plugin_page router. Two views: 'dashboard' (the
 * moderation queue and full comment list) and 'settings' (moderation mode,
 * enabled content types, blacklist, hCaptcha toggle), both sitting under
 * the single tab bar rendered here — the status filters and Settings share
 * one strip, the way the Booking and Analytics plugins lay out their views.
 *
 * Shared URL helpers (cm_site_base_url(), cm_plugin_base_url(),
 * cm_plugin_asset_url()) live in comments-functions.php, not here — the
 * front-end shortcode renderer needs them too, and never loads this file
 * on a genuine front-end request. Only admin-specific URL builders stay
 * in this file.
 *
 * admin/actions.php (approve/reject/delete, save settings) remains a
 * standalone POST-only endpoint — it never renders HTML.
 */

if (defined('CM_ADMIN_PAGE_LOADED')) return;
define('CM_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../comments-functions.php';
require_once __DIR__ . '/../comments-i18n.php';
require_once __DIR__ . '/../comments-security.php';
require_once __DIR__ . '/../comments-data-layer.php';

/**
 * Base URL for the plugin's admin page (core's admin/index.php?action=plugin_page router).
 */
function cm_admin_page_url(array $extraParams = []): string
{
    $params = array_merge(['action' => 'plugin_page', 'slug' => 'comments', 'view' => 'dashboard'], $extraParams);

    $settingsFile = dirname(__DIR__, 3) . '/config.json';
    $adminDir     = 'admin';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['admin_dir'])) {
            $adminDir = $decoded['admin_dir'];
        }
    }

    return cm_site_base_url() . $adminDir . '/index.php?' . http_build_query($params);
}

function cm_admin_actions_url(): string
{
    return cm_plugin_base_url() . 'admin/actions.php';
}

/**
 * Plugin contract entry point — see admin/index.php's plugin_page router
 * for the expected return shape.
 */
function comments_render_admin_page(string $view): array
{
    $template = $view === 'settings' ? 'settings.php' : 'dashboard.php';

    // Resolved once here, not in dashboard.php: the tab links below need the
    // current filter/search to stay on them while switching status, and the
    // dashboard template reuses the same two resolved values. Mirrors how the
    // Analytics plugin resolves its date range before rendering its tabs.
    $validStatuses = ['pending', 'approved', 'rejected', 'all'];
    $filter = $_GET['status'] ?? 'pending';
    if (!in_array($filter, $validStatuses, true)) {
        $filter = 'pending';
    }
    $search = trim((string)($_GET['q'] ?? ''));

    $pendingCount = count(array_filter(cm_load_all_comments(), fn($c) => ($c['status'] ?? '') === 'pending'));

    ob_start();

    // Sub-navigation: the four status filters plus Settings, in one strip.
    // Reuses the core admin's own .tabs/.tab components (admin-components.css)
    // so it matches the tabs used elsewhere in the admin.
    ?>
    <div class="tabs cm-a-subnav" role="tablist">
        <?php foreach ([
            'pending'  => cm_t('cm_filter_pending',  'Pending',  'admin'),
            'approved' => cm_t('cm_filter_approved', 'Approved', 'admin'),
            'rejected' => cm_t('cm_filter_rejected', 'Rejected', 'admin'),
            'all'      => cm_t('cm_filter_all',      'All',      'admin'),
        ] as $statusKey => $label):
            $isActive = $view !== 'settings' && $filter === $statusKey;
        ?>
        <a href="<?php echo htmlspecialchars(cm_admin_page_url(array_filter(['status' => $statusKey, 'q' => $search !== '' ? $search : null]))); ?>"
           class="tab<?php echo $isActive ? ' active' : ''; ?>" role="tab" aria-selected="<?php echo $isActive ? 'true' : 'false'; ?>">
            <?php echo htmlspecialchars($label); ?>
            <?php if ($statusKey === 'pending' && $pendingCount > 0): ?><span class="cm-a-tab-badge"><?php echo (int)$pendingCount; ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <a href="<?php echo htmlspecialchars(cm_admin_page_url(['view' => 'settings'])); ?>"
           class="tab cm-a-subnav-settings<?php echo $view === 'settings' ? ' active' : ''; ?>" role="tab" aria-selected="<?php echo $view === 'settings' ? 'true' : 'false'; ?>">
            <?php echo htmlspecialchars(cm_t('cm_nav_settings', 'Settings', 'admin')); ?>
        </a>
    </div>
    <?php
    require __DIR__ . '/templates/' . $template;
    $html = ob_get_clean();

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(cm_plugin_asset_url('assets/css/comments-admin-embed.css')); ?>">
    <?php
    $extraHead = ob_get_clean();

    return [
        'title'      => cm_t('cm_admin_title', 'Comments', 'admin'),
        'html'       => $html,
        'extra_head' => $extraHead,
    ];
}
