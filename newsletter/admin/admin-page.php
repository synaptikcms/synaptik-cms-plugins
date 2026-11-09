<?php
/**
 * Newsletter Plugin — Admin Page Renderer
 *
 * Exposes newsletter_render_admin_page($view): array, the contract
 * required by admin/plugin-page.php. Seven views, reachable through the
 * tab bar rendered above every one of them:
 *  - 'dashboard' (default): stats and the two send actions
 *  - 'compose': free-form newsletter editor, reusing the CMS's own
 *    WYSIWYG (editor-common.js + editor.js), loaded manually here since
 *    the plugin-page route doesn't go through the core's content-editor
 *    asset detection (see admin/includes/layout.php)
 *  - 'digest_template': the digest email wrapper, same WYSIWYG
 *  - 'subscribers': confirmed list + pending sign-ups
 *  - 'history': previously sent newsletters
 *  - 'settings': sender identity
 *  - 'smtp': outgoing mail transport
 *
 * admin/actions.php (send digest/custom batch, delete, settings) remains a
 * standalone POST-only endpoint — it never renders HTML.
 */

if (defined('NL_ADMIN_PAGE_LOADED')) return;
define('NL_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../newsletter-functions.php';
require_once __DIR__ . '/../newsletter-i18n.php';
require_once __DIR__ . '/../newsletter-security.php';
require_once __DIR__ . '/../newsletter-mail.php';

/**
 * Base URL for the plugin's admin page (core's
 * admin/index.php?action=plugin_page router).
 *
 * Absolute path (not relative to the current script) — this URL is used
 * both from pages physically inside /admin/ and from a return_url field
 * submitted to actions.php, which lives under /plugins/newsletter/admin/.
 * A relative "index.php?..." resolves correctly from the former but not
 * the latter (the browser would resolve it against
 * /plugins/newsletter/admin/ and 404) — so this always returns the full
 * absolute path instead.
 */
function nl_admin_page_url(array $extraParams = []): string
{
    $params = array_merge(['action' => 'plugin_page', 'slug' => 'newsletter', 'view' => 'dashboard'], $extraParams);

    $settingsFile = dirname(__DIR__, 3) . '/config.json';
    $adminDir     = 'admin';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['admin_dir'])) {
            $adminDir = $decoded['admin_dir'];
        }
    }

    return nl_site_base_url() . $adminDir . '/index.php?' . http_build_query($params);
}

function nl_admin_actions_url(): string
{
    return nl_plugin_base_url() . 'admin/actions.php';
}

/**
 * Absolute URL to a core admin asset (JS/CSS), independent of which
 * directory the current script lives in. The plugin's own admin page
 * runs through admin/index.php (relative asset paths work as-is), but
 * this is used from templates that may be reasoned about independently
 * of that fact, so it's built explicitly rather than assumed.
 */
function nl_core_admin_asset_url(string $relativePath): string
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

    return nl_site_base_url() . $adminDir . '/' . $relativePath . '?v=' . $v;
}

/**
 * Plugin contract entry point — see admin/plugin-page.php for the expected
 * return shape.
 */
function newsletter_render_admin_page(string $view): array
{
    $validViews = ['dashboard', 'compose', 'digest_template', 'subscribers', 'history', 'settings', 'smtp'];
    if (!in_array($view, $validViews, true)) {
        $view = 'dashboard';
    }

    ob_start();

    // Sub-navigation between the plugin's own views, rendered above the
    // view content so it's visible without needing a second sidebar level.
    // Reuses the core admin's own .tabs/.tab components (admin-components.css)
    // instead of a plugin-specific style, so this looks identical to the
    // tabs used elsewhere in the admin. Mirrors the Booking plugin.
    $navPending = nl_count_by_status()['pending'];
    ?>
    <div class="tabs nl-subnav">
        <?php foreach ([
            'dashboard'       => nl_t('nl_nav_overview',    'Overview',      'admin'),
            'compose'         => nl_t('nl_nav_compose',     'Compose',       'admin'),
            'digest_template' => nl_t('nl_nav_digest',      'Digest email',  'admin'),
            'subscribers'     => nl_t('nl_nav_subscribers', 'Subscribers',   'admin'),
            'history'         => nl_t('nl_nav_history',     'History',       'admin'),
            'settings'        => nl_t('nl_nav_settings',    'Settings',      'admin'),
            'smtp'            => nl_t('nl_nav_smtp',        'SMTP',          'admin'),
        ] as $navView => $navLabel): ?>
        <a href="<?php echo htmlspecialchars(nl_admin_page_url(['view' => $navView])); ?>" class="tab <?php echo $view === $navView ? 'active' : ''; ?>">
            <?php echo htmlspecialchars($navLabel); ?>
            <?php if ($navView === 'subscribers' && $navPending > 0): ?><span class="nl-tab-badge"><?php echo (int)$navPending; ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php
    require __DIR__ . '/templates/' . $view . '.php';
    $html = ob_get_clean();

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(nl_plugin_base_url() . 'assets/css/newsletter-admin-embed.css'); ?>?v=<?php echo @filemtime(__DIR__ . '/../assets/css/newsletter-admin-embed.css'); ?>">
    <?php if ($view === 'compose' || $view === 'digest_template'): ?>
    <!-- Reuses the CMS core's own WYSIWYG editor rather than reimplementing
         one — see editor.js for the full toolbar/behavior. Not loaded by
         the core automatically here because this page isn't a
         content-add/edit route (see admin/includes/layout.php's asset
         detection), so it's brought in explicitly. jQuery is already
         loaded globally by layout.php for every admin page. Used by both
         the free-form composer and the digest template editor — each
         page hosts exactly one #content textarea, per editor.js's
         single-instance contract. -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(nl_core_admin_asset_url('assets/css/editor-layout.css')); ?>">
    <?php
    // Disable the core's autosave-to-drafts entirely in this context — it
    // targets admin/autosave.php, which is for CMS content items, not
    // newsletters. Suppressing it here (rather than not loading
    // editor-common.js) keeps the rest of the editor's behavior intact.
    ?>
    <script type="application/json" data-window-var="AUTOSAVE_ENABLED_BY_SETTINGS">false</script>
    <script type="application/json" data-window-var="CONTENT_FORMAT">"html"</script>
    <script src="<?php echo htmlspecialchars(nl_core_admin_asset_url('assets/js/admin-boot.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(nl_core_admin_asset_url('assets/js/editor-icons.js')); ?>"></script>
    <script src="<?php echo htmlspecialchars(nl_core_admin_asset_url('assets/js/editor-common.js')); ?>" defer></script>
    <script src="<?php echo htmlspecialchars(nl_core_admin_asset_url('assets/js/editor.js')); ?>" defer></script>
    <?php endif; ?>
    <script src="<?php echo htmlspecialchars(nl_plugin_base_url() . 'assets/js/newsletter-admin.js'); ?>?v=<?php echo @filemtime(__DIR__ . '/../assets/js/newsletter-admin.js'); ?>" defer></script>
    <?php
    $extraHead = ob_get_clean();

    return [
        'title'      => nl_t('nl_admin_title', 'Newsletter', 'admin'),
        'html'       => $html,
        'extra_head' => $extraHead,
    ];
}
