<?php
/**
 * Analytics Plugin — Admin Actions
 *
 * POST-only endpoint for state-changing operations (save settings, purge data).
 * Requires an active admin session. Never renders HTML.
 * Redirects to the ?return= URL after each action.
 */

if (session_status() === PHP_SESSION_NONE) {
    $__sessionConfig = dirname(__DIR__, 3) . '/admin/includes/session-config.php';
    if (file_exists($__sessionConfig)) {
        require_once $__sessionConfig;
    }
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
    unset($__sessionConfig);
}

// Mirrors core's admin_is_logged_in() 2-hour inactivity timeout — this file
// is a standalone entry point, not routed through admin/index.php, so it
// can't call the core function directly and must enforce the same expiry
// itself instead of only checking the flag.
$__an_loggedIn = !empty($_SESSION['admin']) && $_SESSION['admin'] === true;
if ($__an_loggedIn && isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 7200) {
    $__an_loggedIn = false;
}
if (!$__an_loggedIn) {
    http_response_code(403);
    exit('Forbidden');
}
$_SESSION['admin_last_activity'] = time();
unset($__an_loggedIn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

require_once dirname(__DIR__) . '/analytics-functions.php';
require_once dirname(__DIR__) . '/analytics-i18n.php';
require_once dirname(__DIR__) . '/analytics-data-layer.php';
require_once dirname(__DIR__) . '/analytics-geoip.php';

// CSRF — reuse core admin session token
$token = $_POST['csrf_token'] ?? '';
if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

// Safe return URL — must point back into the same admin
$returnUrl = $_GET['return'] ?? '';
if (empty($returnUrl) || strpos($returnUrl, 'action=plugin_page') === false) {
    $returnUrl = an_admin_page_url('settings');
}

$action = $_GET['_action'] ?? '';

switch ($action) {
    case 'install_geoip':
        header('Content-Type: application/json; charset=UTF-8');
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
        $gz      = curl_exec($ch);
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
            'ok'       => true,
            'message'  => 'GeoIP database installed successfully.',
            'v4'       => $result['v4'],
            'v6'       => $result['v6'],
            'built_at' => date('Y-m-d H:i:s'),
        ]));

    case 'save_settings':
        $days = max(7, min(3650, (int)($_POST['retention_days'] ?? 365)));

        $configFile = dirname(__DIR__, 3) . '/config.json';
        $config = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?: []) : [];
        $config['analytics_retention_days'] = $days;
        $config['analytics_dashboard_widget_enabled'] = !empty($_POST['dashboard_widget_enabled']);

        $tmp = $configFile . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, $configFile);

        an_purge_old_logs($days);

        $sep = strpos($returnUrl, '?') !== false ? '&' : '?';
        header('Location: ' . $returnUrl . $sep . 'saved=1');
        exit;

    case 'purge_all':
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

        $sep = strpos($returnUrl, '?') !== false ? '&' : '?';
        header('Location: ' . $returnUrl . $sep . 'purged=1&purge_count=' . $count);
        exit;

    default:
        http_response_code(400);
        exit('Unknown action');
}
