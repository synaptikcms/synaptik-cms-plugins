<?php
/**
 * Reviews Plugin — Admin Actions
 *
 * Handles all state-changing admin operations. Every action requires the
 * core admin session and a valid CSRF token.
 *
 * reorder_reviews responds with JSON (driven by drag-and-drop in
 * reviews-admin.js). Every other action is a classic form POST with a
 * redirect back to the dashboard.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/reviews-data-layer.php';
rv_admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . rv_admin_page_url());
    exit;
}

$action = $_POST['action'] ?? '';

// reorder_reviews is JSON-driven (drag-and-drop) — checked before the
// generic CSRF gate below so it can return a JSON error instead of a
// plain-text one.
if ($action === 'reorder_reviews') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    if (!rv_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false]);
        exit;
    }

    $orderedIds = json_decode($_POST['order'] ?? '[]', true);
    if (!is_array($orderedIds)) {
        echo json_encode(['success' => false]);
        exit;
    }

    $success = rv_reorder_reviews(array_map('strval', $orderedIds));
    echo json_encode(['success' => $success]);
    exit;
}

// ── All other actions: classic form POST + redirect ─────────────────────────

if (!rv_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

/**
 * Validates and normalizes the shared review fields (author, rating,
 * text, date, status) submitted by both the create and update forms.
 * Returns null if required fields are missing or invalid.
 */
function rv_validate_review_input(array $post): ?array
{
    $authorName = trim(strip_tags($post['author_name'] ?? ''));
    $text       = trim(strip_tags($post['text'] ?? ''));
    $rating     = (int)($post['rating'] ?? 0);
    $status     = ($post['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
    $date       = trim($post['date'] ?? '');

    if ($authorName === '' || $text === '' || $rating < 1 || $rating > 5) {
        return null;
    }

    // A malformed date input falls back to today rather than failing the
    // whole submission — the date is a display detail, not the substance
    // of the review.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
        $date = date('Y-m-d');
    }

    return [
        'author_name' => mb_substr($authorName, 0, 120),
        'text'        => mb_substr($text, 0, 2000),
        'rating'      => $rating,
        'status'      => $status,
        'date'        => $date,
    ];
}

switch ($action) {

    case 'create_review': {
        $data = rv_validate_review_input($_POST);
        if ($data === null) {
            header('Location: ' . rv_admin_page_url(['msg' => 'invalid']));
            exit;
        }

        if (!empty($_FILES['avatar']['tmp_name'])) {
            $avatar = rv_handle_avatar_upload($_FILES['avatar']);
            if ($avatar !== null) {
                $data['avatar'] = $avatar;
            }
        }

        rv_create_review($data);
        header('Location: ' . rv_admin_page_url(['msg' => 'created']));
        exit;
    }

    case 'update_review': {
        $id = trim($_POST['id'] ?? '');
        $data = rv_validate_review_input($_POST);
        if ($id === '' || $data === null || rv_get_review($id) === null) {
            header('Location: ' . rv_admin_page_url(['msg' => 'invalid']));
            exit;
        }

        if (!empty($_FILES['avatar']['tmp_name'])) {
            $avatar = rv_handle_avatar_upload($_FILES['avatar']);
            if ($avatar !== null) {
                // Replacing the avatar — remove the previous file so
                // uploads/ doesn't accumulate orphaned images over time.
                $existing = rv_get_review($id);
                $oldAvatar = $existing['avatar'] ?? '';
                if ($oldAvatar !== '') {
                    @unlink(rv_uploads_dir() . '/' . basename($oldAvatar));
                }
                $data['avatar'] = $avatar;
            }
        }

        rv_update_review($id, $data);
        header('Location: ' . rv_admin_page_url(['msg' => 'updated']));
        exit;
    }

    case 'delete_review':
        $id = trim($_POST['id'] ?? '');
        rv_delete_review($id);
        header('Location: ' . rv_admin_page_url(['msg' => 'deleted']));
        exit;

    case 'save_settings':
        $settings = rv_load_settings();

        $style = ($_POST['display_style'] ?? '') === 'grid' ? 'grid' : 'carousel';
        $limit = max(1, min(50, (int)($_POST['default_limit'] ?? 10)));

        $settings['display_style'] = $style;
        $settings['default_limit'] = $limit;

        rv_save_settings($settings);
        header('Location: ' . rv_admin_page_url(['msg' => 'saved']));
        exit;

    default:
        header('Location: ' . rv_admin_page_url());
        exit;
}
