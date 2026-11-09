<?php
/**
 * Form Builder Plugin — Admin Page Renderer
 *
 * Exposes formbuilder_render_admin_page($view): array, the contract
 * required by admin/index.php's plugin_page router. Mirrors the Booking
 * plugin's admin/admin-page.php.
 */

if (defined('FB_ADMIN_PAGE_LOADED')) return;
define('FB_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../formbuilder-functions.php';
require_once __DIR__ . '/../formbuilder-i18n.php';
require_once __DIR__ . '/../formbuilder-data-layer.php';

/**
 * Base URL for links within the plugin's admin page. Absolute path — see
 * the Booking plugin's bk_admin_page_url() for why a relative path would
 * break when linked from actions.php (which lives outside /admin/).
 */
function fb_admin_page_url(string $view, array $extraParams = []): string
{
    $params = array_merge(['action' => 'plugin_page', 'slug' => 'formbuilder', 'view' => $view], $extraParams);

    $settingsFile = dirname(__DIR__, 3) . '/config.json';
    $adminDir     = 'admin';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['admin_dir'])) {
            $adminDir = $decoded['admin_dir'];
        }
    }

    return fb_site_base_url() . $adminDir . '/index.php?' . http_build_query($params);
}

function fb_admin_actions_url(): string
{
    return fb_plugin_base_url() . 'admin/actions.php';
}

/**
 * Plugin contract entry point — see admin/index.php's plugin_page router.
 */
function formbuilder_render_admin_page(string $view): array
{
    require_once __DIR__ . '/../formbuilder-security.php';

    $validViews = ['forms', 'edit', 'submissions', 'settings'];
    if (!in_array($view, $validViews, true)) {
        $view = 'forms';
    }

    ob_start();
    ?>
    <div class="tabs fb-subnav">
        <a href="<?php echo htmlspecialchars(fb_admin_page_url('forms')); ?>" class="tab <?php echo in_array($view, ['forms', 'edit'], true) ? 'active' : ''; ?>"><?php echo htmlspecialchars(fb_t('fb_nav_forms', 'Forms', 'admin')); ?></a>
        <a href="<?php echo htmlspecialchars(fb_admin_page_url('submissions')); ?>" class="tab <?php echo $view === 'submissions' ? 'active' : ''; ?>"><?php echo htmlspecialchars(fb_t('fb_nav_submissions', 'Submissions', 'admin')); ?></a>
        <a href="<?php echo htmlspecialchars(fb_admin_page_url('settings')); ?>" class="tab <?php echo $view === 'settings' ? 'active' : ''; ?>"><?php echo htmlspecialchars(fb_t('fb_nav_settings', 'Settings', 'admin')); ?></a>
    </div>
    <?php

    switch ($view) {
        case 'edit':
            require __DIR__ . '/templates/edit.php';
            break;
        case 'submissions':
            require __DIR__ . '/templates/submissions.php';
            break;
        case 'settings':
            require __DIR__ . '/templates/settings.php';
            break;
        case 'forms':
        default:
            require __DIR__ . '/templates/forms.php';
            break;
    }

    $html = ob_get_clean();

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(fb_plugin_base_url() . 'assets/css/formbuilder-admin-embed.css'); ?>?v=<?php echo @filemtime(__DIR__ . '/../assets/css/formbuilder-admin-embed.css'); ?>">
    <?php if ($view === 'edit'): ?>
    <script src="<?php echo htmlspecialchars(fb_plugin_base_url() . 'assets/js/formbuilder-editor.js'); ?>?v=<?php echo @filemtime(__DIR__ . '/../assets/js/formbuilder-editor.js'); ?>" defer></script>
    <?php endif; ?>
    <?php
    $extraHead = ob_get_clean();

    return [
        'title'      => fb_t('fb_admin_title', 'Form Builder', 'admin'),
        'html'       => $html,
        'extra_head' => $extraHead,
    ];
}
