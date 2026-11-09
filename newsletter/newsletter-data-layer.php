<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Newsletter Plugin — Data Layer
 *
 * Two flat JSON stores — no split index/item pattern needed, both
 * collections stay small (subscriber count and newsletter-send count are
 * orders of magnitude below CMS content volume):
 *
 *   data/subscribers.json  — keyed by subscriber ID
 *   data/newsletters.json  — keyed by newsletter ID, history of sent/draft newsletters
 *   data/config.json     — plugin settings (see newsletter-functions.php)
 */

if (defined('NL_DATA_LAYER_LOADED')) return;
define('NL_DATA_LAYER_LOADED', true);

function nl_data_dir(): string
{
    return __DIR__ . '/data';
}

function nl_subscribers_path(): string
{
    return nl_data_dir() . '/subscribers.json';
}

function nl_newsletters_path(): string
{
    return nl_data_dir() . '/newsletters.json';
}

/**
 * Ensures the required directory exists, with .htaccess protection on
 * first creation (defense in depth alongside the plugin-level .htaccess).
 */
function nl_ensure_dirs(): void
{
    $dir = nl_data_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $content = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
             . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    @file_put_contents($dir . '/.htaccess', $content);
}

/**
 * Atomic JSON write (write to .tmp, then rename) — prevents partial writes
 * from corrupting the file if the PHP process is interrupted mid-write.
 */
function _nl_write_json(string $path, $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;

    return rename($tmp, $path);
}

function _nl_read_json(string $path): array
{
    if (!file_exists($path)) return [];
    $raw     = file_get_contents($path);
    $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

// ─── Subscribers ─────────────────────────────────────────────────────────────

function nl_load_subscribers(): array
{
    if (isset($GLOBALS['_nl_subscribers_cache'])) {
        return $GLOBALS['_nl_subscribers_cache'];
    }
    $result = _nl_read_json(nl_subscribers_path());
    $GLOBALS['_nl_subscribers_cache'] = $result;
    return $result;
}

function nl_write_subscribers(array $subscribers): bool
{
    nl_ensure_dirs();
    $success = _nl_write_json(nl_subscribers_path(), $subscribers);
    if ($success) {
        unset($GLOBALS['_nl_subscribers_cache']);
    }
    return $success;
}

function nl_generate_id(): string
{
    return bin2hex(random_bytes(12));
}

/**
 * Finds a subscriber by email (case-insensitive). Returns [id, record] or
 * null if not found.
 */
function nl_find_by_email(string $email): ?array
{
    $needle = mb_strtolower(trim($email));
    foreach (nl_load_subscribers() as $id => $record) {
        if (mb_strtolower($record['email'] ?? '') === $needle) {
            return [$id, $record];
        }
    }
    return null;
}

function nl_get_subscriber(string $id): ?array
{
    $all = nl_load_subscribers();
    return $all[$id] ?? null;
}

/**
 * Creates a new subscriber record with status 'pending' (awaiting
 * double opt-in confirmation). If the email already exists and is still
 * pending, its record is reused (re-sending the confirmation email rather
 * than creating a duplicate). An already-confirmed email is left untouched
 * — the caller checks nl_find_by_email() first to detect that case.
 *
 * @return string The subscriber ID.
 */
function nl_create_or_reuse_pending(string $email, string $name = ''): string
{
    $existing = nl_find_by_email($email);
    if ($existing !== null && $existing[1]['status'] === 'pending') {
        if ($name !== '' && ($existing[1]['name'] ?? '') !== $name) {
            $subscribers = nl_load_subscribers();
            $subscribers[$existing[0]]['name'] = $name;
            nl_write_subscribers($subscribers);
        }
        return $existing[0];
    }

    $subscribers = nl_load_subscribers();
    $id = nl_generate_id();

    $subscribers[$id] = [
        'email'        => trim($email),
        'name'         => $name,
        'status'       => 'pending', // pending | confirmed
        'created_at'   => date('Y-m-d H:i:s'),
        'confirmed_at' => null,
    ];

    nl_write_subscribers($subscribers);
    return $id;
}

/**
 * Marks a subscriber as confirmed. Idempotent — confirming an
 * already-confirmed subscriber is a harmless no-op (a stale email client
 * that pre-fetches links, or the visitor clicking the link twice).
 */
function nl_confirm_subscriber(string $id): bool
{
    $subscribers = nl_load_subscribers();
    if (!isset($subscribers[$id])) return false;

    if ($subscribers[$id]['status'] === 'confirmed') return true;

    $subscribers[$id]['status']       = 'confirmed';
    $subscribers[$id]['confirmed_at'] = date('Y-m-d H:i:s');

    return nl_write_subscribers($subscribers);
}

function nl_delete_subscriber(string $id): bool
{
    $subscribers = nl_load_subscribers();
    if (!isset($subscribers[$id])) return true;

    unset($subscribers[$id]);
    return nl_write_subscribers($subscribers);
}

/**
 * Returns only confirmed subscribers — the actual send list for a newsletter.
 */
function nl_load_confirmed_subscribers(): array
{
    return array_filter(nl_load_subscribers(), fn($r) => ($r['status'] ?? '') === 'confirmed');
}

function nl_count_by_status(): array
{
    $counts = ['pending' => 0, 'confirmed' => 0];
    foreach (nl_load_subscribers() as $record) {
        $status = $record['status'] ?? 'pending';
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }
    return $counts;
}

// ─── Newsletter history ────────────────────────────────────────────────────
// A "newsletter" record represents one composed email — either a free-form
// one written in the admin editor, or a quick article digest. Every record
// is written once, at send time (there is no separate draft-saving step —
// see the plugin README for why). kind: 'custom' | 'digest'.

function nl_load_newsletters(): array
{
    if (isset($GLOBALS['_nl_newsletters_cache'])) {
        return $GLOBALS['_nl_newsletters_cache'];
    }
    $result = _nl_read_json(nl_newsletters_path());
    $GLOBALS['_nl_newsletters_cache'] = $result;
    return $result;
}

function nl_write_newsletters(array $newsletters): bool
{
    nl_ensure_dirs();
    $success = _nl_write_json(nl_newsletters_path(), $newsletters);
    if ($success) {
        unset($GLOBALS['_nl_newsletters_cache']);
    }
    return $success;
}

/**
 * Returns newsletter history records newest-first, for display in the
 * admin history table.
 */
function nl_load_newsletters_sorted(): array
{
    $all = nl_load_newsletters();
    uasort($all, fn($a, $b) => strcmp($b['sent_at'] ?? '', $a['sent_at'] ?? ''));
    return $all;
}

function nl_get_newsletter(string $id): ?array
{
    $all = nl_load_newsletters();
    return $all[$id] ?? null;
}

/**
 * Records a newsletter as sent. Called once the full batch send completes
 * (see admin/actions.php) — recipient_count reflects the confirmed
 * subscriber count at send time, not a live count.
 */
function nl_record_newsletter_sent(string $subject, string $bodyHtml, string $kind, int $recipientCount): string
{
    $newsletters = nl_load_newsletters();
    $id = nl_generate_id();

    $newsletters[$id] = [
        'subject'         => $subject,
        'body_html'       => $bodyHtml,
        'kind'            => $kind, // 'custom' | 'digest'
        'recipient_count' => $recipientCount,
        'sent_at'         => date('Y-m-d H:i:s'),
    ];

    nl_write_newsletters($newsletters);
    return $id;
}

function nl_delete_newsletter(string $id): bool
{
    $newsletters = nl_load_newsletters();
    if (!isset($newsletters[$id])) return true;

    unset($newsletters[$id]);
    return nl_write_newsletters($newsletters);
}
