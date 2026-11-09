<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Analytics Plugin — GeoIP Engine
 *
 * Provides country lookup from a local binary database built from the
 * DB-IP Country Lite CSV (https://db-ip.com/db/download/ip-to-country-lite).
 * No external requests at lookup time — pure PHP binary search, ~1ms per hit.
 *
 * Database files (stored in data/geoip/):
 *   ipv4.bin  — packed IPv4 ranges: [start_int32][end_int32][cc2] × N, 8 bytes/record
 *   ipv6.bin  — packed IPv6 ranges: [start_bin16][end_bin16][cc2] × N, 34 bytes/record
 *   meta.json — build date, record counts
 *
 * If the database is absent, an_geoip_lookup() returns '' silently — the
 * tracker still works, country field is just empty.
 */

if (defined('AN_GEOIP_LOADED')) return;
define('AN_GEOIP_LOADED', true);

// ── Paths ─────────────────────────────────────────────────────────────────────

function an_geoip_dir(): string   { return __DIR__ . '/data/geoip'; }
function an_geoip_ipv4(): string  { return an_geoip_dir() . '/ipv4.bin'; }
function an_geoip_ipv6(): string  { return an_geoip_dir() . '/ipv6.bin'; }
function an_geoip_meta_file(): string { return an_geoip_dir() . '/meta.json'; }

// ── Status ────────────────────────────────────────────────────────────────────

function an_geoip_installed(): bool
{
    return file_exists(an_geoip_ipv4()) && filesize(an_geoip_ipv4()) > 0;
}

function an_geoip_meta(): array
{
    $f = an_geoip_meta_file();
    if (!file_exists($f)) return [];
    return json_decode(file_get_contents($f), true) ?: [];
}

// ── Lookup ────────────────────────────────────────────────────────────────────

/**
 * Returns the ISO 3166-1 alpha-2 country code for an IP address,
 * or an empty string if the database is not installed or the IP is private.
 */
function an_geoip_lookup(string $ip): string
{
    if ($ip === '') return '';

    // Skip private / loopback ranges immediately
    if (an_geoip_is_private($ip)) return '';

    if (strpos($ip, ':') !== false) {
        return an_geoip_lookup_v6($ip);
    }
    return an_geoip_lookup_v4($ip);
}

function an_geoip_is_private(string $ip): bool
{
    // IPv6 loopback / link-local
    if (strpos($ip, ':') !== false) {
        return in_array($ip, ['::1', '::'], true)
            || stripos($ip, 'fe80') === 0
            || stripos($ip, 'fc')   === 0
            || stripos($ip, 'fd')   === 0;
    }
    // IPv4 private ranges
    $long = ip2long($ip);
    if ($long === false) return true;
    foreach ([
        [ip2long('10.0.0.0'),      ip2long('10.255.255.255')],
        [ip2long('172.16.0.0'),    ip2long('172.31.255.255')],
        [ip2long('192.168.0.0'),   ip2long('192.168.255.255')],
        [ip2long('127.0.0.0'),     ip2long('127.255.255.255')],
        [ip2long('169.254.0.0'),   ip2long('169.254.255.255')],
    ] as [$start, $end]) {
        if ($long >= $start && $long <= $end) return true;
    }
    return false;
}

/**
 * Binary search in ipv4.bin.
 * Record layout: 4 bytes start + 4 bytes end + 2 bytes CC = 10 bytes.
 */
function an_geoip_lookup_v4(string $ip): string
{
    $file = an_geoip_ipv4();
    if (!file_exists($file)) return '';

    $needle = sprintf('%u', ip2long($ip));
    if ($needle === false || $needle < 0) return '';

    $fp = fopen($file, 'rb');
    if (!$fp) return '';

    $size       = filesize($file);
    $recordSize = 10; // 4 + 4 + 2
    $count      = intdiv($size, $recordSize);

    $lo = 0;
    $hi = $count - 1;
    $cc = '';

    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        fseek($fp, $mid * $recordSize);
        $raw = fread($fp, $recordSize);
        if (strlen($raw) < $recordSize) break;

        // Unpack as unsigned 32-bit big-endian to avoid signed integer issues
        $start = unpack('N', substr($raw, 0, 4))[1];
        $end   = unpack('N', substr($raw, 4, 4))[1];
        // Convert to comparable unsigned values on 32-bit PHP
        if ($start < 0) $start += 4294967296;
        if ($end   < 0) $end   += 4294967296;

        if ($needle < $start) {
            $hi = $mid - 1;
        } elseif ($needle > $end) {
            $lo = $mid + 1;
        } else {
            $cc = substr($raw, 8, 2);
            break;
        }
    }

    fclose($fp);
    return ($cc !== '' && $cc !== '--') ? strtoupper($cc) : '';
}

/**
 * Binary search in ipv6.bin.
 * Record layout: 16 bytes start + 16 bytes end + 2 bytes CC = 34 bytes.
 */
function an_geoip_lookup_v6(string $ip): string
{
    $file = an_geoip_ipv6();
    if (!file_exists($file)) return '';

    $needle = @inet_pton($ip);
    if ($needle === false || strlen($needle) !== 16) return '';

    $fp = fopen($file, 'rb');
    if (!$fp) return '';

    $size       = filesize($file);
    $recordSize = 34; // 16 + 16 + 2
    $count      = intdiv($size, $recordSize);

    $lo = 0;
    $hi = $count - 1;
    $cc = '';

    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        fseek($fp, $mid * $recordSize);
        $raw = fread($fp, $recordSize);
        if (strlen($raw) < $recordSize) break;

        $start = substr($raw, 0, 16);
        $end   = substr($raw, 16, 16);

        if (strcmp($needle, $start) < 0) {
            $hi = $mid - 1;
        } elseif (strcmp($needle, $end) > 0) {
            $lo = $mid + 1;
        } else {
            $cc = substr($raw, 32, 2);
            break;
        }
    }

    fclose($fp);
    return ($cc !== '' && $cc !== '--') ? strtoupper($cc) : '';
}

// ── CSV → Binary builder ──────────────────────────────────────────────────────

/**
 * Converts a DB-IP Country Lite CSV file into the optimised binary databases.
 * The CSV format is: ip_start,ip_end,country_code (no header line).
 *
 * Called once from the install endpoint after the CSV is downloaded.
 * Returns ['ok' => bool, 'error' => string, 'v4' => int, 'v6' => int].
 */
function an_geoip_build_from_csv(string $csvPath): array
{
    if (!file_exists($csvPath)) {
        return ['ok' => false, 'error' => 'CSV file not found', 'v4' => 0, 'v6' => 0];
    }

    $dir = an_geoip_dir();
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Write .htaccess to block direct access
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht,
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n"
        );
    }

    $fpV4 = fopen(an_geoip_ipv4() . '.tmp', 'wb');
    $fpV6 = fopen(an_geoip_ipv6() . '.tmp', 'wb');

    if (!$fpV4 || !$fpV6) {
        return ['ok' => false, 'error' => 'Cannot open output files for writing', 'v4' => 0, 'v6' => 0];
    }

    $fh   = fopen($csvPath, 'r');
    if (!$fh) {
        return ['ok' => false, 'error' => 'Cannot open CSV file', 'v4' => 0, 'v6' => 0];
    }

    $countV4 = 0;
    $countV6 = 0;

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        // Handle optional quoting in DB-IP CSVs
        $parts = str_getcsv($line);
        if (count($parts) < 3) continue;

        [$startStr, $endStr, $cc] = $parts;
        $cc = strtoupper(trim($cc));
        if (strlen($cc) !== 2) $cc = '--';

        $isV6 = strpos($startStr, ':') !== false;

        if ($isV6) {
            $startBin = @inet_pton($startStr);
            $endBin   = @inet_pton($endStr);
            if ($startBin === false || $endBin === false) continue;
            if (strlen($startBin) !== 16 || strlen($endBin) !== 16) continue;
            fwrite($fpV6, $startBin . $endBin . $cc);
            $countV6++;
        } else {
            $start = ip2long($startStr);
            $end   = ip2long($endStr);
            if ($start === false || $end === false) continue;
            // Pack as unsigned 32-bit big-endian
            fwrite($fpV4, pack('N', $start) . pack('N', $end) . $cc);
            $countV4++;
        }
    }

    fclose($fh);
    fclose($fpV4);
    fclose($fpV6);

    // Atomically replace the live files
    rename(an_geoip_ipv4() . '.tmp', an_geoip_ipv4());
    rename(an_geoip_ipv6() . '.tmp', an_geoip_ipv6());

    // Write metadata
    file_put_contents(an_geoip_meta_file(), json_encode([
        'built_at'  => date('Y-m-d H:i:s'),
        'source'    => 'DB-IP Country Lite',
        'v4_count'  => $countV4,
        'v6_count'  => $countV6,
    ]));

    return ['ok' => true, 'error' => '', 'v4' => $countV4, 'v6' => $countV6];
}
