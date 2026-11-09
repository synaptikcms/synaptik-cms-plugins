<?php
/**
 * Form Builder Plugin — Admin Actions
 *
 * Handles all state-changing admin operations. Every action requires the
 * core admin session and a valid CSRF token, then redirects back to the
 * page the form was submitted from (return_url), with a status flag
 * appended. Never renders HTML itself — POST-only endpoint.
 */

require_once __DIR__ . '/bootstrap.php';
fb_admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . fb_admin_page_url('forms'));
    exit;
}

if (!fb_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

/**
 * Resolves a safe redirect target from the submitted return_url, appending
 * a status flag. Only accepts a same-host URL pointing at admin/index.php —
 * anything else falls back to the default view, closing off open-redirect
 * abuse via a tampered return_url field. Mirrors the Booking plugin's
 * bk_safe_redirect().
 */
function fb_safe_redirect(string $defaultView, array $params = []): void
{
    $fallback = fb_admin_page_url($defaultView, $params);
    $return   = $_POST['return_url'] ?? '';

    if ($return !== '') {
        $parsed      = parse_url($return);
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';

        $isSameHost = $parsed !== false
            && isset($parsed['path'])
            && (empty($parsed['host']) || strcasecmp($parsed['host'], $currentHost) === 0)
            && basename($parsed['path']) === 'index.php';

        if ($isSameHost) {
            parse_str($parsed['query'] ?? '', $query);
            $query = array_merge($query, $params);

            $scheme = $parsed['scheme'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http');
            $host   = $parsed['host'] ?? $currentHost;

            $fallback = (!empty($parsed['host']) ? $scheme . '://' . $host : '') . $parsed['path'] . '?' . http_build_query($query);
        }
    }

    header('Location: ' . $fallback);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'save_form':
        $fields = [];
        $labels    = $_POST['field_label']    ?? [];
        $keys      = $_POST['field_key']      ?? [];
        $types     = $_POST['field_type']     ?? [];
        $required  = $_POST['field_required'] ?? [];
        $optionsRaw = $_POST['field_options'] ?? [];
        $widths    = $_POST['field_width']    ?? [];
        $sepStyles = $_POST['field_separator_style'] ?? [];

        $count = count($labels);
        for ($i = 0; $i < $count; $i++) {
            $type = $types[$i] ?? 'text';
            $key  = $keys[$i] ?? '';

            if ($type === 'separator') {
                if (trim($key) === '') $key = 'separator_' . $i;
            } elseif (trim($key) === '') {
                continue; // a row with no key is skipped — see fb_save_form()
            }

            $options = [];
            if ($type === 'dropdown') {
                foreach (preg_split('/\r\n|\r|\n/', $optionsRaw[$i] ?? '') as $line) {
                    $line = trim($line);
                    if ($line !== '') $options[] = $line;
                }
            }

            $fields[] = [
                'key'      => $key,
                'label'    => $labels[$i] ?? '',
                'type'     => $type,
                'required' => ($required[$i] ?? '0') === '1',
                'options'  => $options,
                'width'    => ($widths[$i] ?? 'full') === 'half' ? 'half' : 'full',
                'style'    => ($sepStyles[$i] ?? 'space') === 'line' ? 'line' : 'space',
            ];
        }

        $savedId = fb_save_form([
            'id'              => trim($_POST['id'] ?? ''),
            'name'            => $_POST['name'] ?? '',
            'recipient_email' => $_POST['recipient_email'] ?? '',
            'success_message' => $_POST['success_message'] ?? '',
            'notify_by_email' => ($_POST['notify_by_email'] ?? '1') === '1',
            'fields'          => $fields,
        ]);

        if ($savedId === null) {
            fb_safe_redirect('forms', ['msg' => 'error']);
        }
        fb_safe_redirect('forms', ['msg' => 'saved']);

    case 'delete_form':
        $id = trim($_POST['id'] ?? '');
        fb_delete_form($id);
        fb_safe_redirect('forms', ['msg' => 'deleted']);

    case 'delete_submission':
        $formId = trim($_POST['form_id'] ?? '');
        $file   = trim($_POST['file'] ?? '');
        fb_delete_submission($formId, $file);
        fb_safe_redirect('submissions', ['msg' => 'deleted']);

    case 'save_settings':
        $settings = fb_load_settings();

        $fromEmail = trim($_POST['from_email'] ?? '');
        $settings['from_email'] = ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) ? $fromEmail : '';
        $settings['from_name']  = trim(strip_tags($_POST['from_name'] ?? ''));

        fb_save_settings($settings);
        fb_safe_redirect('settings', ['msg' => 'saved']);

    default:
        fb_safe_redirect('forms');
}
