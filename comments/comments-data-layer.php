<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Comments Plugin — Data Layer
 *
 * One flat JSON file per commented item, mirroring the core's own
 * split-file pattern instead of a single growing global store:
 *
 *   data/{type}-{slug}.json — { "c_xxxxxxxx": { author_name, content, ... } }
 *   data/config.json      — plugin settings (see comments-functions.php)
 *
 * A page with zero comments never creates a file — the list/count
 * functions simply return empty. Only a genuine first submission writes
 * anything to disk for that item.
 */

if (defined('CM_DATA_LAYER_LOADED')) return;
define('CM_DATA_LAYER_LOADED', true);

function cm_data_dir(): string
{
    return __DIR__ . '/data';
}

/**
 * Builds the per-item store filename. $type/$slug are expected already
 * validated (see cm_is_valid_type() / sanitizeSlug()) by the caller —
 * this function does not re-validate, only assembles the path.
 */
function cm_item_store_path(string $type, string $slug): string
{
    return cm_data_dir() . '/' . $type . '-' . $slug . '.json';
}

/**
 * Ensures the required directory exists, with .htaccess protection on
 * first creation (defense in depth alongside the plugin-level .htaccess).
 */
function cm_ensure_dirs(): void
{
    $dir = cm_data_dir();
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
function _cm_write_json(string $path, $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;

    return rename($tmp, $path);
}

function _cm_read_json(string $path): array
{
    if (!file_exists($path)) return [];
    $raw     = file_get_contents($path);
    $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function cm_generate_id(): string
{
    return 'c_' . bin2hex(random_bytes(6));
}

// ─── Per-item comment store ─────────────────────────────────────────────────

/**
 * Loads every comment (any status) stored for a single content item.
 * Cached per-request per item — a page renders its own comment list once.
 */
function cm_load_item_comments(string $type, string $slug): array
{
    $cacheKey = '_cm_comments_' . $type . '_' . $slug;
    if (isset($GLOBALS[$cacheKey])) {
        return $GLOBALS[$cacheKey];
    }
    $result = _cm_read_json(cm_item_store_path($type, $slug));
    $GLOBALS[$cacheKey] = $result;
    return $result;
}

function cm_write_item_comments(string $type, string $slug, array $comments): bool
{
    cm_ensure_dirs();
    $success = _cm_write_json(cm_item_store_path($type, $slug), $comments);
    if ($success) {
        unset($GLOBALS['_cm_comments_' . $type . '_' . $slug]);
        // Cross-item cache is now stale — a moderation action in the same
        // request (approve/reject/delete, or a bulk run) must not go on
        // reading the pre-write snapshot.
        unset($GLOBALS['_cm_all_comments_cache']);
    }
    return $success;
}

/**
 * Appends a new comment to an item's store and returns its generated ID.
 */
function cm_add_comment(string $type, string $slug, array $comment): string
{
    $comments = cm_load_item_comments($type, $slug);
    $id       = cm_generate_id();
    $comments[$id] = $comment;
    cm_write_item_comments($type, $slug, $comments);
    return $id;
}

/**
 * Returns only approved, top-level-first comments for front-end display,
 * with each root's approved replies nested under it via '_replies'.
 * Rejected and pending comments are never included here.
 */
function cm_load_approved_tree(string $type, string $slug): array
{
    $all = cm_load_item_comments($type, $slug);

    $roots   = [];
    $replies = [];

    foreach ($all as $id => $comment) {
        if (($comment['status'] ?? '') !== 'approved') continue;

        if (empty($comment['parent_id'])) {
            $comment['_id'] = $id;
            $comment['_replies'] = [];
            $roots[$id] = $comment;
        } else {
            $comment['_id'] = $id;
            $replies[] = [$comment['parent_id'], $comment];
        }
    }

    foreach ($replies as [$parentId, $reply]) {
        if (isset($roots[$parentId])) {
            $roots[$parentId]['_replies'][] = $reply;
        }
        // A reply whose parent was rejected/deleted after the fact is
        // silently dropped — orphaned replies are never shown standalone.
    }

    // Oldest-first — natural conversation reading order.
    uasort($roots, fn($a, $b) => strcmp($a['created_at'] ?? '', $b['created_at'] ?? ''));
    foreach ($roots as &$root) {
        usort($root['_replies'], fn($a, $b) => strcmp($a['created_at'] ?? '', $b['created_at'] ?? ''));
    }
    unset($root);

    return array_values($roots);
}

/**
 * Counts approved comments (roots + replies) for a single item — used for
 * a "N comments" label without building the full display tree.
 */
function cm_count_approved(string $type, string $slug): int
{
    $count = 0;
    foreach (cm_load_item_comments($type, $slug) as $comment) {
        if (($comment['status'] ?? '') === 'approved') $count++;
    }
    return $count;
}

// ─── Cross-item index (admin dashboard) ─────────────────────────────────────

/**
 * Scans every per-item store under data/ and returns a flat list of all
 * comments (any status) with their type/slug attached, newest first.
 * Only used by the admin dashboard — never on front-end requests.
 *
 * Memoized per request (same pattern as cm_load_settings()): this reads and
 * json_decodes every comment file on disk, and an admin page load calls it
 * more than once — the sidebar pending badge on every page, plus the
 * dashboard widget or the moderation table on top of that. Without the
 * cache each of those repeated the full directory scan from scratch.
 * Invalidated by cm_write_item_comments() on any write.
 */
function cm_load_all_comments(): array
{
    if (isset($GLOBALS['_cm_all_comments_cache'])) {
        return $GLOBALS['_cm_all_comments_cache'];
    }

    cm_ensure_dirs();
    $all = [];

    foreach (glob(cm_data_dir() . '/*.json') ?: [] as $file) {
        $basename = basename($file, '.json');
        // Filenames are "{type}-{slug}", where slug itself may contain
        // hyphens — split on the first hyphen only, against the known
        // content type prefixes, rather than guessing from position.
        $type = null;
        foreach (['article', 'page', 'project'] as $knownType) {
            if (strpos($basename, $knownType . '-') === 0) {
                $type = $knownType;
                $slug = substr($basename, strlen($knownType) + 1);
                break;
            }
        }
        if ($type === null) continue; // Not a recognized store file — skip.

        $comments = _cm_read_json($file);
        foreach ($comments as $id => $comment) {
            $comment['_id']   = $id;
            $comment['_type'] = $type;
            $comment['_slug'] = $slug;
            $all[] = $comment;
        }
    }

    uasort($all, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

    $GLOBALS['_cm_all_comments_cache'] = $all;
    return $all;
}

/**
 * Finds a single comment by ID across all item stores. Needed for admin
 * moderation actions, which only receive the comment ID from the form.
 * Returns [type, slug, comment] or null.
 */
function cm_find_comment(string $id): ?array
{
    foreach (glob(cm_data_dir() . '/*.json') ?: [] as $file) {
        $comments = _cm_read_json($file);
        if (isset($comments[$id])) {
            $basename = basename($file, '.json');
            foreach (['article', 'page', 'project'] as $knownType) {
                if (strpos($basename, $knownType . '-') === 0) {
                    return [$knownType, substr($basename, strlen($knownType) + 1), $comments[$id]];
                }
            }
        }
    }
    return null;
}

/**
 * Updates a single comment's status (approve/reject). No-op if not found.
 */
function cm_set_status(string $id, string $status): bool
{
    $found = cm_find_comment($id);
    if ($found === null) return false;
    [$type, $slug, ] = $found;

    $comments = cm_load_item_comments($type, $slug);
    if (!isset($comments[$id])) return false;

    $comments[$id]['status'] = $status;
    return cm_write_item_comments($type, $slug, $comments);
}

/**
 * Deletes a comment. If it is a root comment, its approved replies become
 * orphaned and are simply no longer returned by cm_load_approved_tree() —
 * they are not cascade-deleted, so re-approving is never destructive.
 */
function cm_delete_comment(string $id): bool
{
    $found = cm_find_comment($id);
    if ($found === null) return true;
    [$type, $slug, ] = $found;

    $comments = cm_load_item_comments($type, $slug);
    if (!isset($comments[$id])) return true;

    unset($comments[$id]);
    return cm_write_item_comments($type, $slug, $comments);
}

/**
 * Counts pending comments across the whole site — used for the admin
 * sidebar badge. Cheap enough to run per admin page load: comment stores
 * stay small, and this is never called on front-end requests.
 */
function cm_count_pending(): int
{
    $count = 0;
    foreach (cm_load_all_comments() as $comment) {
        if (($comment['status'] ?? '') === 'pending') $count++;
    }
    return $count;
}

/**
 * Applies a status change or deletion to multiple comment IDs at once —
 * used by the dashboard's bulk-action toolbar. Groups IDs by their target
 * store file first, so each file is read and written once regardless of
 * how many of its comments are affected, rather than once per ID.
 *
 * @param array  $ids    Comment IDs to act on.
 * @param string $action 'approve' | 'reject' | 'delete'
 * @return int Number of comments actually affected.
 */
function cm_bulk_action(array $ids, string $action): int
{
    if (empty($ids) || !in_array($action, ['approve', 'reject', 'delete'], true)) {
        return 0;
    }

    // Map each ID to its store file in a single directory scan, instead of
    // calling cm_find_comment() (itself a full scan) once per ID.
    $targets = []; // file path => [id => true, ...]
    foreach (glob(cm_data_dir() . '/*.json') ?: [] as $file) {
        $comments = _cm_read_json($file);
        foreach ($ids as $id) {
            if (isset($comments[$id])) {
                $targets[$file][$id] = true;
            }
        }
    }

    $affected = 0;
    foreach ($targets as $file => $idMap) {
        $comments = _cm_read_json($file);
        $changed  = false;

        foreach (array_keys($idMap) as $id) {
            if (!isset($comments[$id])) continue;
            if ($action === 'delete') {
                unset($comments[$id]);
            } else {
                $comments[$id]['status'] = $action === 'approve' ? 'approved' : 'rejected';
            }
            $affected++;
            $changed = true;
        }

        if ($changed) {
            _cm_write_json($file, $comments);
            // Invalidate this item's per-request cache, matching
            // cm_write_item_comments()'s own cache-busting behavior.
            $basename = basename($file, '.json');
            foreach (['article', 'page', 'project'] as $knownType) {
                if (strpos($basename, $knownType . '-') === 0) {
                    unset($GLOBALS['_cm_comments_' . $knownType . '_' . substr($basename, strlen($knownType) + 1)]);
                    break;
                }
            }
        }
    }

    return $affected;
}
