<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Redirects Plugin — Data Layer
 *
 * A single flat JSON store, keyed by redirect ID:
 *
 *   data/redirects.json — { "id": { from, to, status_code, created_at } }
 *   data/config.json  — plugin settings (see redirects-functions.php)
 *
 * No hit counter is stored — every front-end request that matches a
 * redirect only ever reads this file, it never writes to it. Keeping the
 * redirect path free of disk writes is deliberate: on shared hosting this
 * is the one code path that runs on every single page load site-wide, so
 * it stays as cheap as possible.
 */

if (defined('RD_DATA_LAYER_LOADED')) return;
define('RD_DATA_LAYER_LOADED', true);

function rd_data_dir(): string
{
    return __DIR__ . '/data';
}

function rd_redirects_path(): string
{
    return rd_data_dir() . '/redirects.json';
}

/**
 * Ensures the required directory exists, with .htaccess protection on
 * first creation (defense in depth alongside the plugin-level .htaccess).
 */
function rd_ensure_dirs(): void
{
    $dir = rd_data_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        $content = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                 . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        @file_put_contents($htaccess, $content);
    }
}

/**
 * Atomic JSON write (write to .tmp, then rename) — prevents partial writes
 * from corrupting the file if the PHP process is interrupted mid-write.
 */
function _rd_write_json(string $path, $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;

    return rename($tmp, $path);
}

function _rd_read_json(string $path): array
{
    if (!file_exists($path)) return [];
    $raw     = file_get_contents($path);
    $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

// ─── Redirects ───────────────────────────────────────────────────────────────

function rd_load_redirects(): array
{
    if (isset($GLOBALS['_rd_redirects_cache'])) {
        return $GLOBALS['_rd_redirects_cache'];
    }
    $result = _rd_read_json(rd_redirects_path());
    $GLOBALS['_rd_redirects_cache'] = $result;
    return $result;
}

function rd_write_redirects(array $redirects): bool
{
    rd_ensure_dirs();
    $success = _rd_write_json(rd_redirects_path(), $redirects);
    if ($success) {
        unset($GLOBALS['_rd_redirects_cache']);
    }
    return $success;
}

function rd_generate_id(): string
{
    return bin2hex(random_bytes(8));
}

/**
 * Returns redirects sorted newest-first, for display in the admin table.
 */
function rd_load_redirects_sorted(): array
{
    $all = rd_load_redirects();
    uasort($all, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    return $all;
}

function rd_get_redirect(string $id): ?array
{
    $all = rd_load_redirects();
    return $all[$id] ?? null;
}

/**
 * Creates a new redirect. $from and $to are expected already normalized
 * (see rd_normalize_path()) by the caller.
 *
 * @return string The redirect ID.
 */
function rd_create_redirect(string $from, string $to, int $statusCode): string
{
    $redirects = rd_load_redirects();
    $id = rd_generate_id();

    $redirects[$id] = [
        'from'        => $from,
        'to'          => $to,
        'status_code' => $statusCode,
        'created_at'  => date('Y-m-d H:i:s'),
    ];

    rd_write_redirects($redirects);
    return $id;
}

function rd_update_redirect(string $id, string $from, string $to, int $statusCode): bool
{
    $redirects = rd_load_redirects();
    if (!isset($redirects[$id])) return false;

    $redirects[$id]['from']        = $from;
    $redirects[$id]['to']          = $to;
    $redirects[$id]['status_code'] = $statusCode;

    return rd_write_redirects($redirects);
}

function rd_delete_redirect(string $id): bool
{
    $redirects = rd_load_redirects();
    if (!isset($redirects[$id])) return true;

    unset($redirects[$id]);
    return rd_write_redirects($redirects);
}

/**
 * Finds a redirect by its normalized "from" path. Returns the record or
 * null. This is the lookup used on every front-end request, so it stays a
 * simple linear scan over an in-memory array — no index file needed at
 * the scale a single-site redirect list ever reaches.
 */
function rd_find_by_from(string $normalizedFrom): ?array
{
    foreach (rd_load_redirects() as $record) {
        if (($record['from'] ?? '') === $normalizedFrom) {
            return $record;
        }
    }
    return null;
}

/**
 * True if another existing redirect (other than $excludeId) already uses
 * this "from" path — used to reject duplicate entries at creation/edit time.
 */
function rd_from_exists(string $normalizedFrom, string $excludeId = ''): bool
{
    foreach (rd_load_redirects() as $id => $record) {
        if ($id === $excludeId) continue;
        if (($record['from'] ?? '') === $normalizedFrom) return true;
    }
    return false;
}
