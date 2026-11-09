<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Reviews Plugin — Security Helpers
 *
 * CSRF token generation/verification for admin actions. There is no
 * public-facing submission form — every review is entered by an
 * authenticated admin — so this plugin needs none of the honeypot/rate
 * limiting/signed-link machinery the Newsletter and Booking plugins carry
 * for their public endpoints.
 */

if (defined('RV_SECURITY_LOADED')) return;
define('RV_SECURITY_LOADED', true);

function rv_private_dir(): string
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
 * Loads (or generates on first use) the HMAC secret backing CSRF tokens.
 */
function rv_get_secret(): string
{
    $secretFile = rv_private_dir() . '/reviews.secret';

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
function rv_generate_csrf(): string
{
    $ts     = time();
    $secret = rv_get_secret();
    $mac    = hash_hmac('sha256', (string)$ts, $secret);
    return $ts . '.' . $mac;
}

function rv_verify_csrf(string $token): bool
{
    if (empty($token) || substr_count($token, '.') !== 1) return false;

    [$ts, $mac] = explode('.', $token, 2);
    if (!is_numeric($ts)) return false;
    if ((time() - (int)$ts) > 7200) return false;

    $expected = hash_hmac('sha256', $ts, rv_get_secret());
    return hash_equals($expected, $mac);
}
