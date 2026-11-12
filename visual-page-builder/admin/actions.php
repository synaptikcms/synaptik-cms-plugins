<?php
$vpb_root      = dirname(__DIR__, 3);
$vpb_adminDir  = 'admin';
$vpb_decoded   = json_decode((string)@file_get_contents($vpb_root . '/config.json'), true);
if (is_array($vpb_decoded) && !empty($vpb_decoded['admin_dir'])) {
    $vpb_adminDir = (string)$vpb_decoded['admin_dir'];
}
$vpb_sessionConfig = $vpb_root . '/' . $vpb_adminDir . '/includes/session-config.php';
if (file_exists($vpb_sessionConfig)) {
    require_once $vpb_sessionConfig;
}
unset($vpb_root, $vpb_adminDir, $vpb_decoded, $vpb_sessionConfig);

session_start();

require_once dirname(__DIR__) . '/vpb-functions.php';

function vpb_json_exit(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vpb_actions_logged_in(): bool
{
    if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) return false;
    if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 7200) return false;
    $_SESSION['admin_last_activity'] = time();
    return true;
}

if (!vpb_actions_logged_in()) {
    vpb_json_exit(403, ['ok' => false, 'error' => 'auth']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vpb_json_exit(405, ['ok' => false, 'error' => 'method']);
}

if (!vpb_verify_csrf(trim((string)($_POST['_csrf'] ?? '')))) {
    vpb_json_exit(403, ['ok' => false, 'error' => 'csrf']);
}

$manifest = vpb_manifest();
$key      = (string)($_POST['key'] ?? '');

if ($manifest === null || !vpb_valid_key($key) || !isset(vpb_targets($manifest)[$key])) {
    vpb_json_exit(400, ['ok' => false, 'error' => 'invalid']);
}

$action = (string)($_POST['action'] ?? '');

if ($action === 'save_layout' || $action === 'save_draft') {
    $raw = (string)($_POST['layout'] ?? '');
    if (strlen($raw) > 1500000) {
        vpb_json_exit(413, ['ok' => false, 'error' => 'too_large']);
    }
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        vpb_json_exit(400, ['ok' => false, 'error' => 'invalid']);
    }
    $layout = vpb_clean_layout($input, $manifest);

    if ($action === 'save_draft') {
        if (!vpb_save_draft($key, $layout)) {
            vpb_json_exit(500, ['ok' => false, 'error' => 'write']);
        }
        vpb_json_exit(200, ['ok' => true, 'token' => vpb_preview_token($key)]);
    }

    if (empty($_POST['force']) && (string)($_POST['rev'] ?? '') !== vpb_layout_rev($key)) {
        vpb_json_exit(409, ['ok' => false, 'error' => 'conflict']);
    }

    vpb_delete_draft($key);
    vpb_snapshot_version($key);
    if (empty($layout['blocks'])) {
        vpb_delete_layout($key);
        vpb_bump_cache();
        vpb_json_exit(200, ['ok' => true, 'layout' => $layout, 'hasLayout' => false, 'rev' => '']);
    }
    if (!vpb_save_layout($key, $layout)) {
        vpb_json_exit(500, ['ok' => false, 'error' => 'write']);
    }
    vpb_bump_cache();
    vpb_json_exit(200, ['ok' => true, 'layout' => $layout, 'hasLayout' => true, 'rev' => vpb_layout_rev($key)]);
}

if ($action === 'list_versions') {
    vpb_json_exit(200, ['ok' => true, 'versions' => vpb_list_versions($key)]);
}

if ($action === 'get_version') {
    $version = vpb_load_version($key, (string)($_POST['id'] ?? ''));
    if ($version === null) {
        vpb_json_exit(404, ['ok' => false, 'error' => 'invalid']);
    }
    vpb_json_exit(200, ['ok' => true, 'layout' => vpb_clean_layout($version, $manifest)]);
}

if ($action === 'reset_layout') {
    vpb_delete_draft($key);
    vpb_snapshot_version($key);
    if (!vpb_delete_layout($key)) {
        vpb_json_exit(500, ['ok' => false, 'error' => 'write']);
    }
    vpb_bump_cache();
    vpb_json_exit(200, ['ok' => true, 'rev' => '']);
}

vpb_json_exit(400, ['ok' => false, 'error' => 'invalid']);
