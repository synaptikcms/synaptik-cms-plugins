<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Analytics Plugin — Tracker
 *
 * Records a single page view to the daily log file.
 * Called from the output buffer callback in analytics-init.php,
 * after the full page has been rendered.
 *
 * Storage format: one JSON array per day in data/logs/YYYY-MM-DD.json
 * Each element is a hit object (ts, type, slug, title, ref_host, ref_medium, device, ip).
 *
 * Uses a rewrite-on-append strategy with LOCK_EX to avoid concurrent write
 * corruption. Decodes, appends, re-encodes — safe and simple at typical
 * flat-file-CMS traffic volumes (a true log-append approach requires fseek
 * past the closing bracket, which is fragile on partial writes).
 */

if (defined('AN_TRACKER_LOADED')) return;
define('AN_TRACKER_LOADED', true);

require_once __DIR__ . '/analytics-functions.php';
require_once __DIR__ . '/analytics-geoip.php';
require_once __DIR__ . '/analytics-ai.php';

function an_record_hit(array $meta): void
{
    an_ensure_dirs();

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $aiBot = an_ai_bot_name($ua);
    if ($aiBot !== '') {
        an_record_ai_bot($aiBot);
        return;
    }
    if (an_is_bot($ua)) return;

    // Skip logged-in admins (session already started by index.php)
    if (!empty($_SESSION['admin'])) return;

    $ref      = an_parse_referrer($_SERVER['HTTP_REFERER'] ?? '');
    $utm      = is_string($_GET['utm_source'] ?? null) ? substr(preg_replace('/[^a-z0-9._\-]/', '', strtolower($_GET['utm_source'])), 0, 60) : '';
    if ($ref['medium'] === 'direct' && $utm !== '' && an_ai_source('', $utm) !== '') {
        $ref = ['host' => $utm, 'medium' => 'ai'];
    }
    $device   = an_detect_device($ua);
    $rawIp    = $_SERVER['REMOTE_ADDR'] ?? '';

    // Truncate referrer URL to avoid bloating log files.
    // Strip query strings from search engines (privacy + noise reduction).
    $rawRef   = $_SERVER['HTTP_REFERER'] ?? '';
    $refUrl   = '';
    if ($rawRef !== '' && $ref['host'] !== '') {
        $refParts = parse_url($rawRef);
        $refPath  = $refParts['path'] ?? '/';
        // Keep query string only for non-search referrers
        if ($ref['medium'] !== 'search' && isset($refParts['query'])) {
            $refPath .= '?' . $refParts['query'];
        }
        $refUrl = mb_substr($refParts['scheme'] . '://' . $ref['host'] . $refPath, 0, 500);
    }

    $hit = [
        'ts'         => time(),
        'type'       => $meta['type']  ?? 'other',
        'slug'       => $meta['slug']  ?? '',
        'title'      => mb_substr($meta['title'] ?? '', 0, 200),
        'ref_host'   => $ref['host'],
        'ref_url'    => $refUrl,
        'ref_medium' => $ref['medium'],
        'utm'        => $utm,
        'device'     => $device,
        // Monthly hash: identifies the same visitor within a calendar month
        // for accurate unique-visitor counts over multi-day ranges.
        'ip'         => an_hash_ip($rawIp),
        // Daily hash: used only for the "today" unique-visitor KPI card.
        'ip_d'       => an_hash_ip_daily($rawIp),
        // ISO 3166-1 alpha-2 country code — empty string if GeoIP DB not installed.
        'country'    => an_geoip_lookup($rawIp),
    ];

    $file = an_log_file(date('Y-m-d'));

    $fp = fopen($file, 'c+');
    if (!$fp) return;

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }

    // Read existing content, parse, append, rewrite
    $raw  = stream_get_contents($fp);
    $data = [];
    if ($raw !== false && $raw !== '') {
        $raw = rtrim($raw);
        // Repair unclosed array from a prior interrupted write
        if (substr($raw, -1) !== ']') $raw .= ']';
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) $data = $parsed;
    }

    $data[] = $hit;

    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $encoded);
    }

    flock($fp, LOCK_UN);
    fclose($fp);
}
