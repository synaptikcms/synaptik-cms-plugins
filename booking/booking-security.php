<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Booking Plugin — Security Helpers
 *
 * CSRF token generation/verification and IP rate limiting, mirroring the
 * core contact form's approach (contact-process.php) but using the plugin's
 * own private/ store so it stays fully independent from core state.
 */

if (defined('BK_SECURITY_LOADED')) return;
define('BK_SECURITY_LOADED', true);

function bk_private_dir(): string
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
function bk_get_secret(): string
{
    $secretFile = bk_private_dir() . '/booking.secret';

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
function bk_generate_csrf(): string
{
    $ts     = time();
    $secret = bk_get_secret();
    $mac    = hash_hmac('sha256', (string)$ts, $secret);
    return $ts . '.' . $mac;
}

function bk_verify_csrf(string $token): bool
{
    if (empty($token) || substr_count($token, '.') !== 1) return false;

    [$ts, $mac] = explode('.', $token, 2);
    if (!is_numeric($ts)) return false;
    if ((time() - (int)$ts) > 7200) return false;

    $expected = hash_hmac('sha256', $ts, bk_get_secret());
    return hash_equals($expected, $mac);
}

// ─── Signed action tokens (client cancellation link) ──────────────────

/**
 * Builds a signed token binding an appointment ID to a specific action
 * (currently only 'cancel'), with no expiry — the link is emailed once and
 * must keep working for as long as the appointment could plausibly still be
 * cancelled (up to the appointment date itself). Forgery is prevented by
 * the HMAC, not a TTL. Mirrors the Newsletter plugin's
 * nl_generate_action_token().
 *
 * Format: base64url({id}|{action}|{hmac})
 */
function bk_generate_action_token(string $appointmentId, string $action): string
{
    $payload = $appointmentId . '|' . $action;
    $mac     = hash_hmac('sha256', $payload, bk_get_secret());
    $token   = $payload . '|' . $mac;

    return rtrim(strtr(base64_encode($token), '+/', '-_'), '=');
}

/**
 * Verifies a token and returns the appointment ID if valid for the expected
 * action, or null otherwise.
 */
function bk_verify_action_token(string $token, string $expectedAction): ?string
{
    $decoded = base64_decode(strtr($token, '-_', '+/'), true);
    if ($decoded === false) return null;

    $parts = explode('|', $decoded, 3);
    if (count($parts) !== 3) return null;

    [$id, $action, $mac] = $parts;
    if ($action !== $expectedAction) return null;

    $expected = hash_hmac('sha256', $id . '|' . $action, bk_get_secret());
    if (!hash_equals($expected, $mac)) return null;

    return $id;
}

function bk_get_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Checks whether the given IP is still within the allowed submission rate
 * (5 requests / hour). IPs are stored as SHA-256 hashes, never in plaintext.
 */
function bk_check_rate(string $ip, int $limit = 5, int $window = 3600): bool
{
    $rateFile = bk_private_dir() . '/booking_rate.json';
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
 * expired entries here — the previous version only filtered them in memory
 * inside bk_check_rate() without ever persisting the result, so the file
 * grew forever.
 */
function bk_record_rate(string $ip, int $window = 3600): void
{
    $rateFile = bk_private_dir() . '/booking_rate.json';
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
