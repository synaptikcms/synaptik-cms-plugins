<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Reviews Plugin — Data Layer
 *
 * Single flat JSON store, keyed by review ID — review volume never
 * approaches CMS content scale, so a split index/item pattern would be
 * unnecessary overhead here.
 *
 *   data/reviews.json — all review records
 *   data/config.json — display defaults (see reviews-functions.php)
 *
 * Record shape:
 * {
 *   "author_name":  string,
 *   "rating":       int (1-5),
 *   "text":         string,
 *   "avatar":       string,  // relative filename under assets/uploads/, or ''
 *   "source":       string,  // 'manual' — reserved for a future 'google' source
 *   "status":       string,  // 'published' | 'draft'
 *   "sort_order":   int,
 *   "date":         string,  // Y-m-d, shown to visitors
 *   "created_at":   string   // Y-m-d H:i:s, internal
 * }
 */

if (defined('RV_DATA_LAYER_LOADED')) return;
define('RV_DATA_LAYER_LOADED', true);

function rv_data_dir(): string
{
    return __DIR__ . '/data';
}

function rv_reviews_path(): string
{
    return rv_data_dir() . '/reviews.json';
}

/**
 * Ensures the data directory exists, with .htaccess protection on first
 * creation (defense in depth alongside the plugin-level .htaccess).
 */
function rv_ensure_dirs(): void
{
    $dir = rv_data_dir();
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
function _rv_write_json(string $path, $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;

    return rename($tmp, $path);
}

function _rv_read_json(string $path): array
{
    if (!file_exists($path)) return [];
    $raw     = file_get_contents($path);
    $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function rv_generate_id(): string
{
    return bin2hex(random_bytes(12));
}

// ─── Reviews ────────────────────────────────────────────────────────────────

function rv_load_reviews(): array
{
    if (isset($GLOBALS['_rv_reviews_cache'])) {
        return $GLOBALS['_rv_reviews_cache'];
    }
    $result = _rv_read_json(rv_reviews_path());
    $GLOBALS['_rv_reviews_cache'] = $result;
    return $result;
}

function rv_write_reviews(array $reviews): bool
{
    rv_ensure_dirs();
    $success = _rv_write_json(rv_reviews_path(), $reviews);
    if ($success) {
        unset($GLOBALS['_rv_reviews_cache']);
    }
    return $success;
}

function rv_get_review(string $id): ?array
{
    $all = rv_load_reviews();
    return $all[$id] ?? null;
}

/**
 * Returns all reviews sorted by sort_order ascending, then created_at
 * descending as a tie-breaker (newest first among equal orders — matches
 * the order new reviews are appended in).
 */
function rv_load_reviews_sorted(): array
{
    $all = rv_load_reviews();
    uasort($all, function ($a, $b) {
        $orderDiff = ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0);
        if ($orderDiff !== 0) return $orderDiff;
        return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });
    return $all;
}

/**
 * Returns only published reviews, sorted for display, optionally filtered
 * by a minimum star rating. Used by the front-end shortcode renderer.
 */
function rv_load_published_reviews(int $minRating = 1): array
{
    $reviews = rv_load_reviews_sorted();
    return array_filter($reviews, function ($r) use ($minRating) {
        return ($r['status'] ?? 'draft') === 'published' && (int)($r['rating'] ?? 0) >= $minRating;
    });
}

/**
 * Creates a new review record. Placed after the current highest
 * sort_order so new reviews append at the end of the display list by
 * default — the admin can reorder afterward.
 */
function rv_create_review(array $data): string
{
    $reviews = rv_load_reviews();
    $id = rv_generate_id();

    $maxOrder = 0;
    foreach ($reviews as $r) {
        $maxOrder = max($maxOrder, (int)($r['sort_order'] ?? 0));
    }

    $reviews[$id] = [
        'author_name' => $data['author_name'],
        'rating'      => $data['rating'],
        'text'        => $data['text'],
        'avatar'      => $data['avatar'] ?? '',
        'source'      => 'manual',
        'status'      => $data['status'] ?? 'published',
        'sort_order'  => $maxOrder + 1,
        'date'        => $data['date'] ?? date('Y-m-d'),
        'created_at'  => date('Y-m-d H:i:s'),
    ];

    rv_write_reviews($reviews);
    return $id;
}

function rv_update_review(string $id, array $data): bool
{
    $reviews = rv_load_reviews();
    if (!isset($reviews[$id])) return false;

    $reviews[$id] = array_merge($reviews[$id], [
        'author_name' => $data['author_name'],
        'rating'      => $data['rating'],
        'text'        => $data['text'],
        'avatar'      => $data['avatar'] ?? $reviews[$id]['avatar'] ?? '',
        'status'      => $data['status'] ?? $reviews[$id]['status'] ?? 'published',
        'date'        => $data['date'] ?? $reviews[$id]['date'] ?? date('Y-m-d'),
    ]);

    return rv_write_reviews($reviews);
}

/**
 * Deletes a review and its avatar file, if any. Avatar removal happens
 * here rather than in the caller so every deletion path (single delete,
 * future bulk actions) automatically stays consistent.
 */
function rv_delete_review(string $id): bool
{
    $reviews = rv_load_reviews();
    if (!isset($reviews[$id])) return true;

    $avatar = $reviews[$id]['avatar'] ?? '';
    if ($avatar !== '') {
        $path = __DIR__ . '/assets/uploads/' . basename($avatar);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    unset($reviews[$id]);
    return rv_write_reviews($reviews);
}

/**
 * Persists a new display order for all reviews at once — called by the
 * admin drag-and-drop reorder action with the full ordered ID list.
 */
function rv_reorder_reviews(array $orderedIds): bool
{
    $reviews = rv_load_reviews();
    foreach ($orderedIds as $index => $id) {
        if (isset($reviews[$id])) {
            $reviews[$id]['sort_order'] = $index + 1;
        }
    }
    return rv_write_reviews($reviews);
}

/**
 * Returns [average, count] for published reviews — used for the aggregate
 * rating display and JSON-LD structured data.
 */
function rv_get_average_rating(): array
{
    $published = rv_load_published_reviews();
    $count = count($published);
    if ($count === 0) return [0.0, 0];

    $sum = 0;
    foreach ($published as $r) {
        $sum += (int)($r['rating'] ?? 0);
    }

    return [round($sum / $count, 1), $count];
}
