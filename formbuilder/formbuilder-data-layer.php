<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Form Builder Plugin — Data Layer
 *
 * Flat-file storage, split into two responsibilities:
 *
 *  - data/forms/{form_id}.json        — one file per form (label + field schema)
 *  - data/forms/_index.json           — lightweight index (id, name, field count)
 *  - data/submissions/{form_id}/      — one JSON file per submission, newest first
 *      by filename sort ({timestamp}-{random}.json)
 *
 * Mirrors the core's own split-file index/item pattern (see data-layer.php)
 * rather than one giant JSON blob, so listing forms or paging submissions
 * never requires reading everything into memory.
 */

if (defined('FB_DATA_LAYER_LOADED')) return;
define('FB_DATA_LAYER_LOADED', true);

function fb_data_dir(): string
{
    return __DIR__ . '/data';
}

function fb_forms_dir(): string
{
    return fb_data_dir() . '/forms';
}

function fb_submissions_dir(): string
{
    return fb_data_dir() . '/submissions';
}

/**
 * Creates the plugin's data directories on first use, each protected by
 * its own deny-all .htaccess — submissions may contain personal data
 * (names, emails, free-text answers) and must never be web-accessible.
 */
function fb_ensure_dirs(): void
{
    foreach ([fb_data_dir(), fb_forms_dir(), fb_submissions_dir()] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }

    $rules = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
           . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    @file_put_contents(fb_data_dir() . '/.htaccess', $rules);
}

/**
 * Atomic write helper: write to a temp file then rename, avoiding a
 * corrupted file if the PHP process is interrupted mid-write.
 */
function fb_write_json(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;

    return rename($tmp, $path);
}

function fb_read_json(string $path): ?array
{
    if (!file_exists($path)) return null;
    $decoded = json_decode(file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Validates a form/submission ID: lowercase letters, numbers, hyphens only.
 * Used both to sanitize new IDs and to reject any path-traversal attempt
 * before it ever touches the filesystem.
 */
function fb_is_valid_id(string $id): bool
{
    return $id !== '' && preg_match('/^[a-z0-9-]{1,64}$/', $id) === 1;
}

// ─── Forms ──────────────────────────────────────────────────────────────────

/**
 * Returns the lightweight form index: [id => ['id', 'name', 'field_count', 'created_at']].
 * Rebuilt from disk if missing rather than trusted blindly, so a manually
 * deleted form file never leaves a stale index entry pointing nowhere.
 */
function fb_load_form_index(): array
{
    fb_ensure_dirs();
    $indexPath = fb_forms_dir() . '/_index.json';
    $index     = fb_read_json($indexPath);

    if ($index !== null) {
        return $index;
    }

    return fb_rebuild_form_index();
}

function fb_rebuild_form_index(): array
{
    fb_ensure_dirs();
    $index   = [];
    $entries = @scandir(fb_forms_dir()) ?: [];

    foreach ($entries as $entry) {
        if (!str_ends_with($entry, '.json') || $entry === '_index.json') continue;
        $form = fb_read_json(fb_forms_dir() . '/' . $entry);
        if ($form === null || empty($form['id'])) continue;

        $index[$form['id']] = [
            'id'          => $form['id'],
            'name'        => $form['name'] ?? '',
            'field_count' => count($form['fields'] ?? []),
            'created_at'  => $form['created_at'] ?? '',
        ];
    }

    fb_write_json(fb_forms_dir() . '/_index.json', $index);
    return $index;
}

function fb_save_form_index(array $index): bool
{
    fb_ensure_dirs();
    return fb_write_json(fb_forms_dir() . '/_index.json', $index);
}

/**
 * Loads a single form's full definition (name, recipient override, field
 * schema). Returns null if the ID is invalid or the form does not exist.
 */
function fb_load_form(string $id): ?array
{
    if (!fb_is_valid_id($id)) return null;
    return fb_read_json(fb_forms_dir() . '/' . $id . '.json');
}

function fb_load_all_forms(): array
{
    $forms = [];
    foreach (array_keys(fb_load_form_index()) as $id) {
        $form = fb_load_form($id);
        if ($form !== null) $forms[$id] = $form;
    }
    return $forms;
}

/**
 * Default shape for a new field. Field types: text, textarea, email, tel,
 * number, dropdown, checkbox. 'options' only applies to dropdown (array of
 * strings). 'required' applies to all types except checkbox, which is
 * inherently optional (an unchecked box carries meaning of its own).
 */
function fb_default_field(): array
{
    return [
        'key'      => '',
        'label'    => '',
        'type'     => 'text',
        'required' => false,
        'options'  => [],
        'width'    => 'full',
        'style'    => 'space',
    ];
}

/**
 * Saves a form (create or update). Assigns a new slug-based ID on first
 * save; an existing ID is preserved so submissions keep pointing at the
 * right form. Field keys are sanitized and de-duplicated (a repeated key
 * would silently overwrite an earlier answer in a submission).
 */
function fb_save_form(array $form): ?string
{
    fb_ensure_dirs();

    $id = $form['id'] ?? '';
    if ($id === '' || !fb_is_valid_id($id)) {
        $id = fb_generate_form_id($form['name'] ?? 'form');
    }

    $seenKeys = [];
    $fields   = [];
    foreach ($form['fields'] ?? [] as $field) {
        $key = fb_sanitize_field_key($field['key'] ?? '');
        if ($key === '' || isset($seenKeys[$key])) continue;
        $seenKeys[$key] = true;

        $type = in_array($field['type'] ?? '', ['text', 'textarea', 'email', 'tel', 'number', 'dropdown', 'checkbox', 'separator'], true)
            ? $field['type']
            : 'text';

        $options = [];
        if ($type === 'dropdown') {
            foreach ($field['options'] ?? [] as $option) {
                $option = trim((string)$option);
                if ($option !== '') $options[] = $option;
            }
        }

        $width = ($field['width'] ?? 'full') === 'half' ? 'half' : 'full';
        $style = in_array($field['style'] ?? '', ['space', 'line'], true) ? $field['style'] : 'space';

        $fields[] = [
            'key'      => $key,
            'label'    => $type === 'separator' ? '' : trim((string)($field['label'] ?? '')),
            'type'     => $type,
            'required' => $type !== 'checkbox' && $type !== 'separator' && !empty($field['required']),
            'options'  => $options,
            'width'    => $type === 'separator' ? 'full' : $width,
            'style'    => $style,
        ];
    }

    $existing = fb_load_form($id);

    $record = [
        'id'                => $id,
        'name'              => trim((string)($form['name'] ?? '')),
        'recipient_email'   => trim((string)($form['recipient_email'] ?? '')),
        'success_message'   => trim((string)($form['success_message'] ?? '')),
        'notify_by_email'   => !empty($form['notify_by_email']),
        'fields'            => $fields,
        'created_at'        => $existing['created_at'] ?? date('c'),
        'updated_at'        => date('c'),
    ];

    if (!fb_write_json(fb_forms_dir() . '/' . $id . '.json', $record)) {
        return null;
    }

    $index = fb_load_form_index();
    $index[$id] = [
        'id'          => $id,
        'name'        => $record['name'],
        'field_count' => count($fields),
        'created_at'  => $record['created_at'],
    ];
    fb_save_form_index($index);

    return $id;
}

/**
 * Sanitizes a field key for safe use as an array key and PDF/email label
 * lookup: lowercase letters, numbers, underscores only.
 */
function fb_sanitize_field_key(string $key): string
{
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9_]+/', '_', $key);
    $key = trim($key, '_');
    return $key ?? '';
}

/**
 * Generates a unique, URL-safe form ID from its name, falling back to a
 * short numeric suffix on collision.
 */
function fb_generate_form_id(string $name): string
{
    $base = strtolower(trim($name));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-');
    if ($base === '') $base = 'form';
    $base = substr($base, 0, 40);

    $index = fb_load_form_index();
    $id    = $base;
    $i     = 2;
    while (isset($index[$id])) {
        $id = $base . '-' . $i;
        $i++;
    }

    return $id;
}

/**
 * Deletes a form definition and its index entry. Does not delete past
 * submissions — those remain on disk as a record, browsable from the
 * Submissions tab by selecting "All forms" even after the form itself is
 * removed from the builder.
 */
function fb_delete_form(string $id): bool
{
    if (!fb_is_valid_id($id)) return false;

    $path = fb_forms_dir() . '/' . $id . '.json';
    if (file_exists($path)) {
        @unlink($path);
    }

    $index = fb_load_form_index();
    unset($index[$id]);
    fb_save_form_index($index);

    return true;
}

// ─── Submissions ────────────────────────────────────────────────────────────

/**
 * Persists a submission to data/submissions/{form_id}/{timestamp}-{rand}.json.
 * The filename sorts newest-first when read in reverse alphabetical order,
 * avoiding a separate index file for something append-only and rarely
 * filtered beyond "which form" and "how recent".
 */
function fb_save_submission(string $formId, array $values, string $ip): ?string
{
    fb_ensure_dirs();
    if (!fb_is_valid_id($formId)) return null;

    $dir = fb_submissions_dir() . '/' . $formId;
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $filename = date('Y-m-d-His') . '-' . bin2hex(random_bytes(3)) . '.json';

    $record = [
        'form_id'      => $formId,
        'submitted_at' => date('c'),
        'ip'           => $ip,
        'values'       => $values,
    ];

    if (!fb_write_json($dir . '/' . $filename, $record)) {
        return null;
    }

    return $filename;
}

/**
 * Lists submissions for a form (or all forms when $formId is null),
 * newest first, capped at $limit. Full record contents are loaded lazily
 * so the list view stays fast even with a large submission history.
 */
function fb_list_submissions(?string $formId = null, int $limit = 50, int $offset = 0): array
{
    fb_ensure_dirs();

    $entries = [];
    if ($formId !== null) {
        if (!fb_is_valid_id($formId)) return [];
        $dir = fb_submissions_dir() . '/' . $formId;
        if (!is_dir($dir)) return [];
        foreach (@scandir($dir) ?: [] as $file) {
            if (str_ends_with($file, '.json')) {
                $entries[] = ['form_id' => $formId, 'file' => $file];
            }
        }
    } else {
        foreach (@scandir(fb_submissions_dir()) ?: [] as $sub) {
            if ($sub === '.' || $sub === '..' || $sub === '.htaccess') continue;
            $dir = fb_submissions_dir() . '/' . $sub;
            if (!is_dir($dir)) continue;
            foreach (@scandir($dir) ?: [] as $file) {
                if (str_ends_with($file, '.json')) {
                    $entries[] = ['form_id' => $sub, 'file' => $file];
                }
            }
        }
    }

    usort($entries, fn($a, $b) => strcmp($b['file'], $a['file'])); // newest first
    $entries = array_slice($entries, $offset, $limit);

    $results = [];
    foreach ($entries as $entry) {
        $record = fb_read_json(fb_submissions_dir() . '/' . $entry['form_id'] . '/' . $entry['file']);
        if ($record === null) continue;
        $record['_file'] = $entry['file'];
        $results[] = $record;
    }

    return $results;
}

/**
 * Loads a single submission by form ID and filename, validating both
 * against path traversal before touching the filesystem.
 */
function fb_load_submission(string $formId, string $file): ?array
{
    if (!fb_is_valid_id($formId)) return null;
    if (!preg_match('/^[0-9-]+-[a-f0-9]{6}\.json$/', $file)) return null;

    return fb_read_json(fb_submissions_dir() . '/' . $formId . '/' . $file);
}

function fb_delete_submission(string $formId, string $file): bool
{
    if (!fb_is_valid_id($formId)) return false;
    if (!preg_match('/^[0-9-]+-[a-f0-9]{6}\.json$/', $file)) return false;

    $path = fb_submissions_dir() . '/' . $formId . '/' . $file;
    if (!file_exists($path)) return false;

    return @unlink($path);
}

// ─── Settings ───────────────────────────────────────────────────────────────

function fb_settings_path(): string
{
    return fb_data_dir() . '/config.json';
}

function fb_default_settings(): array
{
    return [
        'from_email' => '', // falls back to noreply@{host} when empty
        'from_name'  => '', // falls back to the site title when empty
    ];
}

function fb_load_settings(): array
{
    if (isset($GLOBALS['_fb_config_cache'])) {
        return $GLOBALS['_fb_config_cache'];
    }

    $settings = fb_default_settings();
    $decoded  = fb_read_json(fb_settings_path());
    if ($decoded !== null) {
        $settings = array_merge($settings, $decoded);
    }

    $GLOBALS['_fb_config_cache'] = $settings;
    return $settings;
}

function fb_save_settings(array $settings): bool
{
    fb_ensure_dirs();
    $success = fb_write_json(fb_settings_path(), $settings);
    if ($success) {
        unset($GLOBALS['_fb_config_cache']);
    }
    return $success;
}
