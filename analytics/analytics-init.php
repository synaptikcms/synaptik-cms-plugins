<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Analytics Plugin — Init
 *
 * Two responsibilities:
 *
 * 1. Admin sidebar entry registration.
 *
 * 2. Front-end page view tracking via output buffering. The tracker runs
 *    after the full page response is assembled (inside the ob_start callback),
 *    so it can extract the page title from the rendered <title> tag and the
 *    routing context from the CMS globals set by index.php. No JS required.
 */

if (defined('AN_INIT_LOADED')) return;
define('AN_INIT_LOADED', true);

require_once __DIR__ . '/analytics-functions.php';
require_once __DIR__ . '/analytics-i18n.php';
require_once __DIR__ . '/admin/admin-page.php';

// ── 1. Admin sidebar ─────────────────────────────────────────────────────────

if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        pl_register_admin_menu(
            'analytics',
            an_t('an_admin_title', 'Analytics', 'admin'),
            an_admin_page_url('overview'),
            '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>'
        );
    });
}

// ── 2. Dashboard widget — full-width KPI + line chart + top-3-pages preview ──
// Reuses the .an-kpi/.an-bar-list markup/CSS from the overview page. The chart
// itself is rendered by an external script (assets/js/dashboard-widget.js),
// fed through a <script type="application/json"> data island — admin_dashboard
// has no extra_head slot, and an inline <script> would violate the admin CSP
// (script-src has no 'unsafe-inline', see admin/.htaccess).

if (function_exists('pl_on_admin_dashboard')) {
    pl_on_admin_dashboard(function () {
        $__anCfgFile = dirname(__DIR__, 2) . '/config.json';
        $__anCfg     = file_exists($__anCfgFile) ? (json_decode(file_get_contents($__anCfgFile), true) ?: []) : [];
        if (empty($__anCfg['analytics_dashboard_widget_enabled'] ?? true)) return;

        require_once __DIR__ . '/analytics-data-layer.php';

        $stats      = an_build_aggregate('7d');
        $pluginBase = an_site_base_url() . 'plugins/analytics/';
        $cssV       = @filemtime(__DIR__ . '/assets/css/analytics-admin.css') ?: time();
        $jsFile     = __DIR__ . '/assets/js/dashboard-widget.js';
        $jsV        = @filemtime($jsFile) ?: time();

        $widgetData = [
            'days'  => array_keys($stats['by_day']),
            'views' => array_values($stats['by_day']),
            'label' => an_t('an_kpi_views', 'Page views', 'admin'),
        ];
        ?>
        <link rel="stylesheet" href="<?php echo hsc($pluginBase . 'assets/css/analytics-admin.css'); ?>?v=<?php echo $cssV; ?>">
        <div class="dashboard-panel an-dash-widget">
            <div class="an-dash-widget-header">
                <h3><?php echo hsc(an_t('an_dashboard_widget_title', 'Traffic', 'admin')); ?></h3>
                <a href="<?php echo hsc(an_admin_page_url('overview')); ?>" class="an-panel-more"><?php echo hsc(an_t('an_view_all', 'View all →', 'admin')); ?></a>
            </div>
            <div class="an-dash-widget-body">
                <div class="an-kpi an-dash-kpi">
                    <div class="an-kpi-value"><?php echo number_format($stats['total_views']); ?></div>
                    <div class="an-kpi-label"><?php echo hsc(an_t('an_dashboard_kpi_label', 'views in the last 7 days', 'admin')); ?></div>
                </div>
                <div class="an-chart-wrap an-dash-chart-wrap">
                    <canvas id="an-dash-chart" height="70"></canvas>
                </div>
            </div>
            <?php if (!empty($stats['top_pages'])): ?>
            <div class="an-bar-list an-bar-list--compact">
                <?php
                $topSlice = array_slice($stats['top_pages'], 0, 3, true);
                $maxViews = max(1, reset($topSlice)['views'] ?? 1);
                foreach ($topSlice as $slug => $p):
                    $pct = round($p['views'] / $maxViews * 100);
                ?>
                <div class="an-bar-row">
                    <div class="an-bar-meta">
                        <span class="an-bar-title"><?php echo hsc($p['title'] ?: $slug); ?></span>
                    </div>
                    <div class="an-bar-track">
                        <div class="an-bar-fill" style="width:<?php echo $pct; ?>%"></div>
                    </div>
                    <div class="an-bar-count"><?php echo number_format($p['views']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <script type="application/json" id="an-dash-widget-data"><?php echo json_encode($widgetData, JSON_HEX_TAG); ?></script>
        <script src="<?php echo hsc($pluginBase . 'assets/js/dashboard-widget.js'); ?>?v=<?php echo $jsV; ?>" defer></script>
        <?php
    });
}

// ── 3. Front-end tracker ─────────────────────────────────────────────────────

if (isset($_GET['an_ai_export']) && function_exists('pl_add_hook')) {
    pl_add_hook('early_request', function () {
        require_once __DIR__ . '/analytics-ai.php';
        an_ai_export_handle();
    });
}

$__an_adminDir = 'admin';
$__an_cfgFile  = dirname(__DIR__, 2) . '/config.json';
if (file_exists($__an_cfgFile)) {
    $__an_cfg = json_decode(file_get_contents($__an_cfgFile), true);
    if (is_array($__an_cfg) && !empty($__an_cfg['admin_dir'])) {
        $__an_adminDir = $__an_cfg['admin_dir'];
    }
    unset($__an_cfg);
}
unset($__an_cfgFile);
$__an_isAdmin = strpos($_SERVER['REQUEST_URI'] ?? '', '/' . $__an_adminDir . '/') !== false;
$__an_isCli    = PHP_SAPI === 'cli';
$__an_isPlugin = strpos($_SERVER['REQUEST_URI'] ?? '', '/plugins/') !== false;
$__an_isAsset  = (bool) preg_match('/\.(css|js|json|xml|txt|ico|png|jpg|gif|svg|woff2?)(\?.*)?$/i', $_SERVER['REQUEST_URI'] ?? '');

if (!$__an_isAdmin && !$__an_isCli && !$__an_isPlugin && !$__an_isAsset && !isset($_GET['an_ai_export'])) {
    require_once __DIR__ . '/analytics-tracker.php';

    ob_start(function (string $html): string {
        // Extract page title from <title> tag (already rendered in the buffer)
        $title = '';
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Resolve routing context from CMS globals populated by index.php
        $type = 'other';
        $slug = '';

        $requestType = $GLOBALS['_route_type'] ?? ($_GET['type'] ?? '');
        $requestSlug = $GLOBALS['_route_slug'] ?? ($_GET['slug'] ?? '');

        if (empty($requestType) && empty($requestSlug)) {
            $type = 'home';
        } elseif ($requestType === '404') {
            // Store the actual requested URI so 404s are identifiable in the admin
            $type = '404';
            $slug = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        } elseif (!empty($requestType) && !empty($requestSlug)) {
            $type = $requestType;
            $slug = $requestSlug;
        } elseif (!empty($requestType)) {
            $type = 'list';
            $slug = $requestType;
        }

        // Check for search page
        if (isset($_GET['q']) || strpos($_SERVER['REQUEST_URI'] ?? '', '/search') !== false) {
            $type = 'search';
        }

        an_record_hit(['type' => $type, 'slug' => $slug, 'title' => $title]);

        return $html; // Pass-through — never modify front-end HTML
    });
}

unset($__an_isAdmin, $__an_isCli, $__an_isPlugin, $__an_isAsset);
