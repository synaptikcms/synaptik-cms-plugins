<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Newsletter Plugin — Security Helpers
 *
 * CSRF token generation/verification, IP rate limiting, and signed tokens
 * for the confirm/unsubscribe email links — all HMAC-based against a
 * secret stored in the plugin's own private/ store, independent from core
 * state. Mirrors the Booking plugin's approach.
 */

if (defined('NL_SECURITY_LOADED')) return;
define('NL_SECURITY_LOADED', true);

function nl_private_dir(): string
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
 * Loads (or generates on first use) the HMAC secret shared by CSRF tokens
 * and signed confirm/unsubscribe links.
 */
function nl_get_secret(): string
{
    $secretFile = nl_private_dir() . '/newsletter.secret';

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

// ─── CSRF (form submission) ─────────────────────────────────────────────────

/**
 * Generates a stateless CSRF token: "{timestamp}.{HMAC-SHA256}", 2 h TTL.
 */
function nl_generate_csrf(): string
{
    $ts     = time();
    $secret = nl_get_secret();
    $mac    = hash_hmac('sha256', (string)$ts, $secret);
    return $ts . '.' . $mac;
}

function nl_verify_csrf(string $token): bool
{
    if (empty($token) || substr_count($token, '.') !== 1) return false;

    [$ts, $mac] = explode('.', $token, 2);
    if (!is_numeric($ts)) return false;
    if ((time() - (int)$ts) > 7200) return false;

    $expected = hash_hmac('sha256', $ts, nl_get_secret());
    return hash_equals($expected, $mac);
}

// ─── Signed action tokens (confirm / unsubscribe links) ────────────────────

// How long a double opt-in confirmation link stays valid. Unsubscribe links
// are deliberately exempt (see nl_verify_action_token()) — a subscriber must
// always be able to unsubscribe for as long as the subscription exists.
const NL_CONFIRM_TOKEN_TTL = 7 * 24 * 60 * 60; // 7 days

/**
 * Builds a signed token binding a subscriber ID + timestamp to a specific
 * action ('confirm' or 'unsubscribe'). The timestamp is only enforced for
 * 'confirm' (see nl_verify_action_token()) — carrying it for 'unsubscribe'
 * too costs nothing and keeps the token format uniform.
 *
 * Format: base64url({id}|{action}|{ts}|{hmac})
 */
function nl_generate_action_token(string $subscriberId, string $action): string
{
    $payload = $subscriberId . '|' . $action . '|' . time();
    $mac     = hash_hmac('sha256', $payload, nl_get_secret());
    $token   = $payload . '|' . $mac;

    return rtrim(strtr(base64_encode($token), '+/', '-_'), '=');
}

/**
 * Verifies a token and returns the subscriber ID if valid for the expected
 * action, or null otherwise. A 'confirm' token older than
 * NL_CONFIRM_TOKEN_TTL is rejected — an indefinitely-valid confirmation
 * link widens the window for an intercepted email to confirm a third
 * party's address long after the fact. 'unsubscribe' stays unlimited.
 */
function nl_verify_action_token(string $token, string $expectedAction): ?string
{
    $decoded = base64_decode(strtr($token, '-_', '+/'), true);
    if ($decoded === false) return null;

    $parts = explode('|', $decoded, 4);
    if (count($parts) !== 4) return null;

    [$id, $action, $ts, $mac] = $parts;
    if ($action !== $expectedAction) return null;

    $expected = hash_hmac('sha256', $id . '|' . $action . '|' . $ts, nl_get_secret());
    if (!hash_equals($expected, $mac)) return null;

    if ($expectedAction === 'confirm' && (time() - (int)$ts) > NL_CONFIRM_TOKEN_TTL) {
        return null;
    }

    return $id;
}

// ─── Rate limiting ───────────────────────────────────────────────────────────

function nl_get_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Checks whether the given IP is still within the allowed submission rate
 * (5 signup attempts / hour by default). IPs are stored as SHA-256 hashes,
 * never in plaintext.
 */
function nl_check_rate(string $ip, int $limit = 5, int $window = 3600): bool
{
    $rateFile = nl_private_dir() . '/newsletter_rate.json';
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
function nl_record_rate(string $ip, int $window = 3600): void
{
    $rateFile = nl_private_dir() . '/newsletter_rate.json';
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
