<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Form Builder Plugin — Security Helpers
 *
 * CSRF token generation/verification and IP rate limiting, mirroring the
 * core contact form's approach (contact-process.php) using the plugin's
 * own private/ store so it stays fully independent from core state.
 */

if (defined('FB_SECURITY_LOADED')) return;
define('FB_SECURITY_LOADED', true);

function fb_private_dir(): string
{
    $dir = __DIR__ . '/private';

    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
        $htaccess = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                  . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        @file_put_contents($dir . '/.htaccess', $htaccess);
    }

    return $dir;
}

/**
 * Loads (or generates on first use) the HMAC secret used for CSRF tokens.
 */
function fb_get_secret(): string
{
    $secretFile = fb_private_dir() . '/formbuilder.secret';

    if (file_exists($secretFile)) {
        $secret = trim(file_get_contents($secretFile));
        if (strlen($secret) === 64) {
            return $secret;
        }
    }

    $secret = bin2hex(random_bytes(32));
    @file_put_contents($secretFile, $secret, LOCK_EX);
    return $secret;
}

/**
 * Generates a stateless CSRF token: "{timestamp}.{HMAC-SHA256}", 2 h TTL.
 */
function fb_generate_csrf(): string
{
    $ts     = time();
    $secret = fb_get_secret();
    $mac    = hash_hmac('sha256', (string)$ts, $secret);
    return $ts . '.' . $mac;
}

function fb_verify_csrf(string $token): bool
{
    if (empty($token) || substr_count($token, '.') !== 1) return false;

    [$ts, $mac] = explode('.', $token, 2);
    if (!is_numeric($ts)) return false;
    if ((time() - (int)$ts) > 7200) return false;

    $expected = hash_hmac('sha256', $ts, fb_get_secret());
    return hash_equals($expected, $mac);
}

/**
 * Checks whether the given IP is still within the allowed submission rate.
 * IPs are stored as SHA-256 hashes, never in plaintext.
 */
function fb_check_rate(string $ip, int $limit = 5, int $window = 3600): bool
{
    $rateFile = fb_private_dir() . '/formbuilder_rate.json';
    $now      = time();

    $data = [];
    if (file_exists($rateFile)) {
        $data = json_decode(file_get_contents($rateFile), true) ?? [];
    }

    $key = hash('sha256', $ip);

    foreach (array_keys($data) as $k) {
        if (($now - ($data[$k]['window_start'] ?? 0)) > $window) {
            unset($data[$k]);
        }
    }

    if (!isset($data[$key])) return true;

    $entry = $data[$key];
    if (($now - $entry['window_start']) < $window) {
        return $entry['count'] < $limit;
    }

    return true;
}

/**
 * Records one submission for $ip. The read-modify-write cycle is wrapped in
 * a single flock() (not just LOCK_EX on the final write) so two concurrent
 * requests can't both read the same starting count and each increment it to
 * the same value, silently losing one of the increments. Also prunes
 * expired entries here so the file doesn't grow unboundedly.
 */
function fb_record_rate(string $ip, int $window = 3600): void
{
    $rateFile = fb_private_dir() . '/formbuilder_rate.json';
    $fh = @fopen($rateFile, 'c+');
    if ($fh === false) return;

    flock($fh, LOCK_EX);

    $raw  = stream_get_contents($fh);
    $data = ($raw !== false && $raw !== '') ? (json_decode($raw, true) ?? []) : [];

    $now = time();
    foreach (array_keys($data) as $k) {
        if (($now - ($data[$k]['window_start'] ?? 0)) > $window) {
            unset($data[$k]);
        }
    }

    $key = hash('sha256', $ip);
    if (!isset($data[$key]) || ($now - ($data[$key]['window_start'] ?? 0)) >= $window) {
        $data[$key] = ['count' => 1, 'window_start' => $now];
    } else {
        $data[$key]['count']++;
    }

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}
