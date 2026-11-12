<?php
if (defined('DF_ADMIN_PAGE_LOADED')) return;
define('DF_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../definitions-functions.php';
require_once __DIR__ . '/../definitions-i18n.php';

function df_admin_page_url(array $extraParams = []): string
{
    $params = array_merge(['action' => 'plugin_page', 'slug' => 'definitions', 'view' => 'dashboard'], $extraParams);

    $settingsFile = dirname(__DIR__, 3) . '/config.json';
    $adminDir     = 'admin';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['admin_dir'])) {
            $adminDir = $decoded['admin_dir'];
        }
    }

    return df_site_base_url() . $adminDir . '/index.php?' . http_build_query($params);
}

function df_admin_actions_url(): string
{
    return df_plugin_base_url() . 'admin/actions.php';
}

function definitions_render_admin_page(string $view): array
{
    $validViews = ['dashboard', 'settings'];
    if (!in_array($view, $validViews, true)) {
        $view = 'dashboard';
    }

    ob_start();
    require __DIR__ . '/templates/tabs.php';
    switch ($view) {
        case 'settings':
            require __DIR__ . '/templates/settings.php';
            break;
        default:
            require __DIR__ . '/templates/dashboard.php';
            break;
    }
    $html = ob_get_clean();

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(df_plugin_asset_url('assets/css/definitions-admin-embed.css')); ?>">
    <script src="<?php echo htmlspecialchars(df_plugin_asset_url('assets/js/definitions-admin.js')); ?>" defer></script>
    <?php
    $extraHead = ob_get_clean();

    return [
        'title'      => df_t('df_admin_title', 'Definitions', 'admin'),
        'html'       => $html,
        'extra_head' => $extraHead,
    ];
}
