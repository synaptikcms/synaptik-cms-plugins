<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Analytics Plugin — Data Layer
 *
 * Reads and aggregates daily log files into the stats consumed by the admin
 * views. Results are cached in data/cache/ with a 5-minute TTL so the admin
 * panel stays fast even on large log sets.
 *
 * All public functions return plain PHP arrays ready for json_encode() or
 * direct use in templates.
 */

if (defined('AN_DATA_LAYER_LOADED')) return;
define('AN_DATA_LAYER_LOADED', true);

require_once __DIR__ . '/analytics-functions.php';

// Cache TTL in seconds
const AN_CACHE_TTL = 300;

// ── Cache helpers ────────────────────────────────────────────────────────────

function an_cache_get(string $range): ?array
{
    $file = an_cache_file($range);
    if (!file_exists($file)) return null;
    if ((time() - filemtime($file)) > AN_CACHE_TTL) return null;
    $d = json_decode(file_get_contents($file), true);
    return is_array($d) ? $d : null;
}

function an_cache_set(string $range, array $data): void
{
    an_ensure_dirs();
    an_write_json(an_cache_file($range), $data);
}

// ── Log reader ───────────────────────────────────────────────────────────────

/**
 * Loads and merges raw hits for the last $days days (today inclusive).
 * Returns a flat array of hit objects.
 */
function an_load_hits(int $days): array
{
    $hits = [];
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $file = an_log_file($date);
        if (!file_exists($file)) continue;
        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') continue;
        // Close open array if tracker was interrupted mid-write
        $raw = rtrim($raw);
        if (substr($raw, -1) !== ']') $raw .= ']';
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $hits = array_merge($hits, $parsed);
        }
    }
    return $hits;
}

// ── Aggregate builder ────────────────────────────────────────────────────────

/**
 * Builds the full aggregate stats object for a given range.
 * $range: '7d' | '30d' | '90d' | '365d'
 */
function an_build_aggregate(string $range): array
{
    $days = match($range) {
        '30d'  => 30,
        '90d'  => 90,
        '365d' => 365,
        default => 7,
    };

    $cached = an_cache_get($range);
    if ($cached !== null) return $cached;

    $hits = an_load_hits($days);

    // ── 1. Total views & unique visitors ────────────────────────────────────
    // 'ip'  = monthly hash → same visitor identifiable within a calendar month
    // 'ip_d' = daily hash  → used only for the single-day "today" KPI
    // Unique visitors here = distinct monthly hashes across the full range.
    $totalViews     = count($hits);
    $uniqueIpSet    = []; // monthly hash dedup across the full period
    $uniqueVisitors = 0;

    // ── 2. Views per day and unique visitors per day (for the line chart) ─────
    $byDay       = [];
    $byDayUnique = [];
    $dailyIpSets = []; // date => [hash => true]
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $byDay[$d]       = 0;
        $byDayUnique[$d] = 0;
    }

    // ── 3. Top pages ──────────────────────────────────────────────────────────
    $pages = []; // slug => ['title', 'type', 'views']

    // ── 4. Referrer breakdown ─────────────────────────────────────────────────
    $refHosts  = []; // host => count
    $refUrls   = []; // host => [url => count]
    $refMedia  = ['direct' => 0, 'search' => 0, 'social' => 0, 'referral' => 0, 'ai' => 0];

    // ── 5. Device breakdown ───────────────────────────────────────────────────
    $devices = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];

    // ── 6. Content type breakdown ─────────────────────────────────────────────
    $byType = [];

    // ── 7. Country breakdown ──────────────────────────────────────────────────
    $countries = [];

    foreach ($hits as $h) {
        if (!is_array($h)) continue;

        $date = date('Y-m-d', (int)($h['ts'] ?? 0));

        // Views per day
        if (isset($byDay[$date])) $byDay[$date]++;

        // Unique visitors — deduplicated by monthly IP hash across the whole range
        $monthlyHash = $h['ip'] ?? '';
        if ($monthlyHash !== '' && !isset($uniqueIpSet[$monthlyHash])) {
            $uniqueIpSet[$monthlyHash] = true;
            $uniqueVisitors++;
        }

        // Unique visitors per day (monthly hash dedup per day)
        if (isset($byDayUnique[$date]) && $monthlyHash !== '') {
            if (!isset($dailyIpSets[$date][$monthlyHash])) {
                $dailyIpSets[$date][$monthlyHash] = true;
                $byDayUnique[$date]++;
            }
        }

        // Top pages
        $slug = $h['slug'] ?? '';
        if ($slug !== '') {
            if (!isset($pages[$slug])) {
                $pages[$slug] = ['title' => $h['title'] ?? $slug, 'type' => $h['type'] ?? '', 'views' => 0];
            }
            $pages[$slug]['views']++;
            // Update title in case it was blank on earlier hits
            if ($pages[$slug]['title'] === $slug && !empty($h['title'])) {
                $pages[$slug]['title'] = $h['title'];
            }
        }

        // Referrers
        $medium = $h['ref_medium'] ?? 'direct';
        if ($medium !== 'ai' && an_ai_source((string)($h['ref_host'] ?? ''), (string)($h['utm'] ?? '')) !== '') $medium = 'ai';
        $host   = $h['ref_host']   ?? '';
        $url    = $h['ref_url']    ?? '';
        if (isset($refMedia[$medium])) $refMedia[$medium]++;
        if ($host !== '') {
            $refHosts[$host] = ($refHosts[$host] ?? 0) + 1;
            if ($url !== '') {
                $refUrls[$host][$url] = ($refUrls[$host][$url] ?? 0) + 1;
            }
        }

        // Devices
        $device = $h['device'] ?? 'desktop';
        if (isset($devices[$device])) $devices[$device]++;

        // Content types
        $type = $h['type'] ?? 'other';
        $byType[$type] = ($byType[$type] ?? 0) + 1;

        // Countries
        $cc = $h['country'] ?? '';
        if ($cc !== '') {
            $countries[$cc] = ($countries[$cc] ?? 0) + 1;
        }
    }

    // Sort top pages descending
    uasort($pages, fn($a, $b) => $b['views'] - $a['views']);
    $topPages = array_slice($pages, 0, 20, true);

    // Sort referrer hosts descending, cap at 20
    arsort($refHosts);
    $topReferrers = array_slice($refHosts, 0, 20, true);

    // Sort referrer URLs within each host descending, cap at 10 per host
    $topRefUrls = [];
    foreach ($topReferrers as $host => $_) {
        if (!empty($refUrls[$host])) {
            arsort($refUrls[$host]);
            $topRefUrls[$host] = array_slice($refUrls[$host], 0, 10, true);
        }
    }

    // Sort countries descending, cap at 50
    arsort($countries);
    $topCountries = array_slice($countries, 0, 50, true);

    $result = [
        'range'          => $range,
        'days'           => $days,
        'total_views'    => $totalViews,
        'unique_visitors'=> $uniqueVisitors,
        'by_day'         => $byDay,
        'by_day_unique'  => $byDayUnique,
        'top_pages'      => $topPages,
        'ref_media'      => $refMedia,
        'top_referrers'  => $topReferrers,
        'ref_urls'       => $topRefUrls,
        'devices'        => $devices,
        'by_type'        => $byType,
        'top_countries'  => $topCountries,
        'generated_at'   => time(),
    ];

    an_cache_set($range, $result);
    return $result;
}

/**
 * Returns today's page view count and unique visitor count (live, no cache).
 * Unique visitors use the daily IP hash (ip_d) — correct for a single-day window.
 *
 * @return array{views: int, unique: int}
 */
function an_today_stats(): array
{
    $file = an_log_file(date('Y-m-d'));
    if (!file_exists($file)) return ['views' => 0, 'unique' => 0];

    $raw = file_get_contents($file);
    if (!$raw) return ['views' => 0, 'unique' => 0];

    $raw = rtrim($raw);
    if (substr($raw, -1) !== ']') $raw .= ']';
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) return ['views' => 0, 'unique' => 0];

    $seen = [];
    foreach ($parsed as $h) {
        $hash = $h['ip_d'] ?? $h['ip'] ?? ''; // ip_d for new hits, ip fallback for old logs
        if ($hash !== '') $seen[$hash] = true;
    }

    return ['views' => count($parsed), 'unique' => count($seen)];
}

/** @deprecated Use an_today_stats()['views'] */
function an_today_views(): int
{
    return an_today_stats()['views'];
}

/**
 * Returns available log date range (oldest and newest log file dates).
 */
function an_log_date_range(): array
{
    $dir = an_logs_dir();
    if (!is_dir($dir)) return ['oldest' => null, 'newest' => null];
    $files = glob($dir . '/????-??-??.json');
    if (empty($files)) return ['oldest' => null, 'newest' => null];
    sort($files);
    return [
        'oldest' => basename(reset($files), '.json'),
        'newest' => basename(end($files),   '.json'),
    ];
}

/**
 * Purges log files older than $days days.
 */
function an_purge_old_logs(int $days): int
{
    $dir   = an_logs_dir();
    $count = 0;
    if (!is_dir($dir)) return 0;
    $cutoff = strtotime("-{$days} days");
    foreach (glob($dir . '/????-??-??.json') as $file) {
        $date = basename($file, '.json');
        if (strtotime($date) < $cutoff) {
            @unlink($file);
            $count++;
        }
    }
    return $count;
}
