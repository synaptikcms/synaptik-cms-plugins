<?php
if (defined('AN_ADMIN_PAGE_LOADED')) return;
define('AN_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../analytics-functions.php';
require_once __DIR__ . '/../analytics-i18n.php';
require_once __DIR__ . '/../analytics-data-layer.php';

function analytics_render_admin_page(string $view): array
{
    if (isset($_GET['_ajax'])) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            exit(json_encode(['ok' => false, 'error' => 'Method Not Allowed']));
        }
        $token = $_POST['csrf_token'] ?? '';
        if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            exit(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
        }

        $ajax = $_GET['_ajax'];

        // ── Save settings ─────────────────────────────────────────────────────
        if ($ajax === 'save_settings') {
            $days       = max(7, min(3650, (int)($_POST['retention_days'] ?? 365)));
            $configFile = dirname(__DIR__, 3) . '/config.json';
            $config     = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?: []) : [];
            $config['analytics_retention_days'] = $days;
            $config['analytics_dashboard_widget_enabled'] = !empty($_POST['dashboard_widget_enabled']);
            $tmp = $configFile . '.' . getmypid() . '.tmp';
            file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            rename($tmp, $configFile);
            an_purge_old_logs($days);
            exit(json_encode(['ok' => true]));
        }

        if ($ajax === 'ai_export_enable') {
            require_once __DIR__ . '/../analytics-ai.php';
            $newToken = an_ai_export_enable();
            exit(json_encode($newToken !== '' ? ['ok' => true] : ['ok' => false, 'error' => 'Could not write the token file.']));
        }

        if ($ajax === 'ai_export_disable') {
            require_once __DIR__ . '/../analytics-ai.php';
            an_ai_export_disable();
            exit(json_encode(['ok' => true]));
        }

        // ── Purge all logs ────────────────────────────────────────────────────
        if ($ajax === 'purge_all') {
            $dir   = an_logs_dir();
            $count = 0;
            if (is_dir($dir)) {
                foreach (glob($dir . '/????-??-??.json') as $f) {
                    @unlink($f);
                    $count++;
                }
            }
            $cacheDir = an_cache_dir();
            if (is_dir($cacheDir)) {
                foreach (glob($cacheDir . '/aggregate-*.json') as $f) @unlink($f);
            }
            exit(json_encode(['ok' => true, 'count' => $count]));
        }

        // ── Install GeoIP ─────────────────────────────────────────────────────
        if ($ajax === 'install_geoip') {
            require_once __DIR__ . '/../analytics-geoip.php';
            @set_time_limit(300);
            @ini_set('memory_limit', '128M');

            $year  = (int) date('Y');
            $month = (int) date('m');
            $candidates = [
                sprintf('https://download.db-ip.com/free/dbip-country-lite-%04d-%02d.csv.gz', $year, $month),
                sprintf('https://download.db-ip.com/free/dbip-country-lite-%04d-%02d.csv.gz',
                    $month === 1 ? $year - 1 : $year,
                    $month === 1 ? 12 : $month - 1
                ),
            ];

            $downloadUrl = null;
            foreach ($candidates as $url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 15,  CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT => 'SynaptikCMS-Analytics/1.0',
                ]);
                curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code === 200) { $downloadUrl = $url; break; }
            }

            if (!$downloadUrl) {
                exit(json_encode(['ok' => false, 'error' => 'Could not reach the DB-IP download server.']));
            }

            $tmpGz  = sys_get_temp_dir() . '/dbip-country-' . time() . '.csv.gz';
            $tmpCsv = sys_get_temp_dir() . '/dbip-country-' . time() . '.csv';

            $ch = curl_init($downloadUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 180,         CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'SynaptikCMS-Analytics/1.0',
            ]);
            $gz       = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($gz === false || $httpCode !== 200) {
                exit(json_encode(['ok' => false, 'error' => 'Download failed (HTTP ' . $httpCode . '). ' . $curlErr]));
            }

            file_put_contents($tmpGz, $gz);
            unset($gz);

            if (!function_exists('gzopen')) {
                @unlink($tmpGz);
                exit(json_encode(['ok' => false, 'error' => 'PHP zlib extension required.']));
            }

            $gzFp = gzopen($tmpGz, 'rb');
            $out  = fopen($tmpCsv, 'wb');
            if (!$gzFp || !$out) {
                @unlink($tmpGz);
                exit(json_encode(['ok' => false, 'error' => 'Failed to open temp files.']));
            }
            while (!gzeof($gzFp)) { fwrite($out, gzread($gzFp, 65536)); }
            gzclose($gzFp);
            fclose($out);
            @unlink($tmpGz);

            $result = an_geoip_build_from_csv($tmpCsv);
            @unlink($tmpCsv);

            if (!$result['ok']) {
                exit(json_encode(['ok' => false, 'error' => 'Build failed: ' . $result['error']]));
            }

            exit(json_encode([
                'ok'      => true,
                'message' => 'GeoIP database installed successfully.',
                'v4'      => $result['v4'],
                'v6'      => $result['v6'],
                'built_at'=> date('Y-m-d H:i:s'),
            ]));
        }

        exit(json_encode(['ok' => false, 'error' => 'Unknown action']));
    }

    // ── Normal page render ────────────────────────────────────────────────────
    $validViews  = ['overview', 'pages', 'referrers', 'countries', 'settings'];
    if (!in_array($view, $validViews, true)) $view = 'overview';

    // Resolve and validate range once, shared by all templates and tab links.
    $validRanges = ['7d', '30d', '90d', '365d'];
    $range = $_GET['range'] ?? '30d';
    if (!in_array($range, $validRanges, true)) $range = '30d';

    ob_start();
    ?>
    <div class="tabs an-subnav">
        <?php
        // Tab links always carry the current range so switching tabs preserves it.
        $rangedViews = ['overview', 'pages', 'referrers', 'countries'];
        foreach ([
            'overview'  => an_t('an_nav_overview',  'Overview',  'admin'),
            'pages'     => an_t('an_nav_pages',      'Top Pages', 'admin'),
            'referrers' => an_t('an_nav_referrers',  'Referrers', 'admin'),
            'countries' => an_t('an_nav_countries',  'Countries', 'admin'),
            'settings'  => an_t('an_nav_settings',   'Settings',  'admin'),
        ] as $v => $label):
            $params = in_array($v, $rangedViews, true) ? ['range' => $range] : [];
        ?>
        <a href="<?php echo hsc(an_admin_page_url($v, $params)); ?>"
           class="tab <?php echo $view === $v ? 'active' : ''; ?>">
            <?php echo hsc($label); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php
    // Syncs localStorage with the current range on every page load, and
    // restores the saved range when landing with no explicit range param.
    // Logic lives in assets/js/range-sync.js — this just feeds it data.
    $rangeFromUrl = isset($_GET['range']) && in_array($_GET['range'], $validRanges, true);
    $rangeSyncV   = @filemtime(__DIR__ . '/../assets/js/range-sync.js') ?: time();
    ?>
    <script type="application/json" id="an-range-sync-data"><?php echo json_encode(['current' => $range, 'fromUrl' => $rangeFromUrl]); ?></script>
    <script src="<?php echo hsc(an_site_base_url() . 'plugins/analytics/assets/js/range-sync.js'); ?>?v=<?php echo $rangeSyncV; ?>"></script>

    <?php
    switch ($view) {
        case 'pages':     require __DIR__ . '/templates/pages.php';     break;
        case 'referrers': require __DIR__ . '/templates/referrers.php'; break;
        case 'countries': require __DIR__ . '/templates/countries.php'; break;
        case 'settings':  require __DIR__ . '/templates/settings.php';  break;
        default:          require __DIR__ . '/templates/overview.php';  break;
    }

    $html = ob_get_clean();

    $pluginBase = an_site_base_url() . 'plugins/analytics/';
    $cssFile    = __DIR__ . '/../assets/css/analytics-admin.css';
    $cssV       = @filemtime($cssFile) ?: time();

    $extraHead = '<link rel="stylesheet" href="'
        . hsc($pluginBase . 'assets/css/analytics-admin.css')
        . '?v=' . $cssV . '">';

    return [
        'title'      => an_t('an_admin_title', 'Analytics', 'admin'),
        'html'       => $html,
        'extra_head' => $extraHead,
    ];
}
