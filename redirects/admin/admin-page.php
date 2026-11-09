<?php
/**
 * Redirects Plugin — Admin Page Renderer
 *
 * Exposes redirects_render_admin_page($view): array, the contract required
 * by admin/index.php's plugin_page router. Single view: 'dashboard' — a
 * list of existing redirects with an add form, and the 404-fallback toggle.
 *
 * admin/actions.php (create/update/delete, save settings) remains a
 * standalone POST-only endpoint — it never renders HTML.
 */

if (defined('RD_ADMIN_PAGE_LOADED')) return;
define('RD_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../redirects-functions.php';
require_once __DIR__ . '/../redirects-i18n.php';

/**
 * Returns the site's base URL with a trailing slash, correctly accounting
 * for the CMS living in a sub-directory (e.g. https://host/synaptikcms/) rather
 * than the domain root.
 *
 * Cannot delegate to the core's getBaseUrl(): that function derives the
 * path from $_SERVER['SCRIPT_NAME'], which is only correct when the
 * currently-executing script is the CMS root's index.php. Called from
 * admin/actions.php (.../plugins/redirects/admin/actions.php) or from
 * inside the plugin_page-rendered admin view (.../admin/index.php),
 * SCRIPT_NAME points at a different depth each time — getBaseUrl() would
 * return the wrong root in at least one of those two contexts, which is
 * exactly what produced the double "admin/admin/" URL before this fix.
 *
 * Instead, the CMS root is derived from this file's own filesystem
 * position (admin/admin-page.php lives three levels below the CMS root:
 * admin/ -> redirects/ -> plugins/ -> root) relative to DOCUMENT_ROOT —
 * reliable regardless of which script runs.
 */
function rd_site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    $host = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $cmsRootFilesystemPath = dirname(__DIR__, 3); // admin/ -> redirects/ -> plugins/ -> CMS root
    $documentRoot          = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $cmsRootFilesystemPath = str_replace('\\', '/', $cmsRootFilesystemPath);

    // realpath() resolves symlinks on both sides before comparing — on some
    // hosts (OVH mutualized included) DOCUMENT_ROOT and the plugin's own
    // __DIR__ can each contain a different symlinked alias to the same
    // physical directory (e.g. one going through a home-directory symlink
    // the other doesn't), which makes a plain strpos() prefix match fail
    // even though the two paths point at the same place.
    $realCmsRoot  = realpath($cmsRootFilesystemPath) ?: $cmsRootFilesystemPath;
    $realDocRoot  = $documentRoot !== '' ? (realpath($documentRoot) ?: $documentRoot) : '';
    $realCmsRoot  = str_replace('\\', '/', $realCmsRoot);
    $realDocRoot  = str_replace('\\', '/', $realDocRoot);

    if ($realDocRoot !== '' && strpos($realCmsRoot, $realDocRoot) === 0) {
        $urlPath = substr($realCmsRoot, strlen($realDocRoot));
    } elseif ($documentRoot !== '' && strpos($cmsRootFilesystemPath, $documentRoot) === 0) {
        $urlPath = substr($cmsRootFilesystemPath, strlen($documentRoot));
    } else {
        // DOCUMENT_ROOT unavailable or genuinely mismatched (rare, some
        // reverse-proxy setups) — fall back to the core's getBaseUrl() logic
        // as a best effort, and log once so a mismatch is diagnosable instead
        // of silently producing a wrong link.
        $urlPath = function_exists('getBaseUrl') ? parse_url(getBaseUrl(), PHP_URL_PATH) : '/';
        error_log(sprintf(
            '[Redirects] rd_site_base_url() could not match DOCUMENT_ROOT against the plugin path — falling back to getBaseUrl(). cmsRootFilesystemPath=%s documentRoot=%s realCmsRoot=%s realDocRoot=%s fallbackUrlPath=%s',
            $cmsRootFilesystemPath,
            $documentRoot,
            $realCmsRoot,
            $realDocRoot,
            (string)$urlPath
        ));
    }

    $urlPath = '/' . trim($urlPath, '/');
    if ($urlPath === '/') {
        return $host . '/';
    }
    return $host . $urlPath . '/';
}

/**
 * Base URL for the plugin's own folder (CMS root + 'plugins/redirects/').
 * Use for the plugin's static assets and its actions.php endpoint.
 */
function rd_plugin_base_url(): string
{
    return rd_site_base_url() . 'plugins/redirects/';
}

/**
 * Base URL for the plugin's admin page (core's admin/index.php?action=plugin_page router).
 *
 * Absolute path (not relative to the current script) — actions.php lives
 * under /plugins/redirects/admin/, so a relative "index.php?..." submitted
 * as a return_url would resolve against the wrong directory in the browser.
 */
function rd_admin_page_url(array $extraParams = []): string
{
    $params = array_merge(['action' => 'plugin_page', 'slug' => 'redirects', 'view' => 'dashboard'], $extraParams);

    $settingsFile = dirname(__DIR__, 3) . '/config.json';
    $adminDir     = 'admin';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['admin_dir'])) {
            $adminDir = $decoded['admin_dir'];
        }
    }

    return rd_site_base_url() . $adminDir . '/index.php?' . http_build_query($params);
}

function rd_admin_actions_url(): string
{
    return rd_plugin_base_url() . 'admin/actions.php';
}

/**
 * Plugin contract entry point — see admin/plugin-page.php router for the
 * expected return shape.
 */
function redirects_render_admin_page(string $view): array
{
    ob_start();
    require __DIR__ . '/templates/dashboard.php';
    $html = ob_get_clean();

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(rd_plugin_asset_url('assets/css/redirects-admin-embed.css')); ?>">
    <?php
    $extraHead = ob_get_clean();

    return [
        'title'      => rd_t('rd_admin_title', 'Redirects', 'admin'),
        'html'       => $html,
        'extra_head' => $extraHead,
    ];
}

/**
 * Cache-busted URL to one of the plugin's own assets.
 */
function rd_plugin_asset_url(string $relativePath): string
{
    $absPath = dirname(__DIR__) . '/' . $relativePath;
    $v       = @filemtime($absPath) ?: time();

    return rd_plugin_base_url() . $relativePath . '?v=' . $v;
}
