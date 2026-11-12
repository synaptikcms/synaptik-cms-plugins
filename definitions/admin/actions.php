<?php
$__df_cmsRoot      = dirname(__DIR__, 3); // admin/ -> definitions/ -> plugins/ -> CMS root
$__df_settingsFile = $__df_cmsRoot . '/config.json';
$__df_adminDir     = 'admin';
if (file_exists($__df_settingsFile)) {
    $__df_decoded = json_decode(file_get_contents($__df_settingsFile), true);
    if (is_array($__df_decoded) && !empty($__df_decoded['admin_dir'])) {
        $__df_adminDir = $__df_decoded['admin_dir'];
    }
}
$__sessionConfig = $__df_cmsRoot . '/' . $__df_adminDir . '/includes/session-config.php';
if (file_exists($__sessionConfig)) {
    require_once $__sessionConfig;
}
unset($__df_cmsRoot, $__df_settingsFile, $__df_adminDir, $__df_decoded, $__sessionConfig);

session_start();

require_once dirname(__DIR__) . '/definitions-functions.php';
require_once dirname(__DIR__) . '/definitions-i18n.php';
require_once __DIR__ . '/admin-page.php';

function df_admin_is_logged_in(): bool
{
    if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) return false;
    if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 7200) return false;
    $_SESSION['admin_last_activity'] = time();
    return true;
}

if (!df_admin_is_logged_in()) {
    http_response_code(403);
    exit('Not authorized. Please log in to the CMS admin panel.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . df_admin_page_url());
    exit;
}

if (!df_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'create_term':
    case 'update_term':
        $term       = trim($_POST['term'] ?? '');
        $definition = trim($_POST['definition'] ?? '');
        $enabled    = !empty($_POST['enabled']);
        $wrapAll    = !empty($_POST['wrap_all']);
        $id         = trim($_POST['id'] ?? '');

        // The "max occurrences" number input is disabled (and so never
        // submitted) whenever "wrap_all" is checked client-side — fall
        // back to the term's own previously saved count rather than
        // silently resetting it to 1 every time this form is saved while
        // that checkbox is on.
        $existing = $id !== '' ? df_get_term($id) : null;
        if (isset($_POST['max_occurrences'])) {
            $maxOccurrences = df_sanitize_max_occurrences($_POST['max_occurrences']);
        } else {
            $maxOccurrences = (int)($existing['max_occurrences'] ?? 1);
        }

        $term       = mb_substr($term, 0, 80);
        $definition = mb_substr($definition, 0, 500);
        $variants   = df_sanitize_variants($_POST['variants'] ?? '', $term);

        if ($term === '' || $definition === '') {
            header('Location: ' . df_admin_page_url(['msg' => 'invalid']));
            exit;
        }

        if ($action === 'create_term') {
            if (df_term_exists($term)) {
                header('Location: ' . df_admin_page_url(['msg' => 'duplicate']));
                exit;
            }
            df_create_term($term, $definition, $enabled, $variants, $wrapAll, $maxOccurrences);
            header('Location: ' . df_admin_page_url(['msg' => 'created']));
        } else {
            if ($id === '' || df_get_term($id) === null) {
                header('Location: ' . df_admin_page_url(['msg' => 'not_found']));
                exit;
            }
            if (df_term_exists($term, $id)) {
                header('Location: ' . df_admin_page_url(['msg' => 'duplicate']));
                exit;
            }
            df_update_term($id, $term, $definition, $enabled, $variants, $wrapAll, $maxOccurrences);
            header('Location: ' . df_admin_page_url(['msg' => 'updated']));
        }
        exit;

    case 'delete_term':
        $id = trim($_POST['id'] ?? '');
        df_delete_term($id);
        header('Location: ' . df_admin_page_url(['msg' => 'deleted']));
        exit;

    case 'save_settings':
        $settings = df_load_settings();
        $settings['wrap_all_by_default'] = !empty($_POST['wrap_all_by_default']);
        $settings['skip_headings']       = !empty($_POST['skip_headings']);
        if (isset($_POST['max_occurrences_default'])) {
            $settings['max_occurrences_default'] = df_sanitize_max_occurrences($_POST['max_occurrences_default']);
        }
        df_save_settings($settings);
        header('Location: ' . df_admin_page_url(['view' => 'settings', 'msg' => 'saved']));
        exit;

    default:
        header('Location: ' . df_admin_page_url());
        exit;
}
