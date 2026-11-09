<?php
/**
 * Form Builder Plugin — Submission Handler
 *
 * Standalone POST endpoint (not routed through index.php). Security layers,
 * in order:
 *  1. Method check           — POST only
 *  2. Honeypot                — _hp must be empty
 *  3. Timing check            — minimum 3 s between page load and submit
 *  4. Referrer validation     — request must come from own domain
 *  5. CSRF token               — HMAC-SHA256, stateless, TTL 2 h
 *  6. Rate limiting            — max 5 submissions per IP per hour
 *  7. Form existence + field validation (required fields, dropdown options)
 *  8. Save submission to disk
 *  9. Generate PDF recap and email it to the form's recipient
 *
 * Responds with JSON — consumed by assets/js/formbuilder-form.js.
 */

require_once __DIR__ . '/formbuilder-functions.php';
require_once __DIR__ . '/formbuilder-data-layer.php';
require_once __DIR__ . '/formbuilder-i18n.php';
require_once __DIR__ . '/formbuilder-security.php';
require_once __DIR__ . '/formbuilder-pdf.php';
require_once __DIR__ . '/formbuilder-mail.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function fb_submit_fail(string $reason, int $httpCode = 400): void
{
    http_response_code($httpCode);
    echo json_encode(['ok' => false, 'error' => $reason]);
    exit;
}

// ── 1. Method ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fb_submit_fail('method', 405);
}

// ── 2. Honeypot — silently "succeed" without doing any work ─────────────────
if (!empty($_POST['_hp'])) {
    echo json_encode(['ok' => true]);
    exit;
}

// ── 3. Timing check ──────────────────────────────────────────────────────────
$formTime = (int)($_POST['_ft'] ?? 0);
if ($formTime === 0 || (time() - $formTime) < 3) {
    echo json_encode(['ok' => true]); // silent — don't tip off bots
    exit;
}

// ── 4. Referrer validation ───────────────────────────────────────────────────
$siteHost = parse_url(
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST'],
    PHP_URL_HOST
);
$refererHost = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
if (empty($refererHost) || strtolower($refererHost) !== strtolower($siteHost)) {
    fb_submit_fail('referrer', 403);
}

// ── 5. CSRF token ─────────────────────────────────────────────────────────────
$csrfToken = trim($_POST['_csrf'] ?? '');
if (!fb_verify_csrf($csrfToken)) {
    fb_submit_fail('csrf', 403);
}

// ── 6. Rate limiting ──────────────────────────────────────────────────────────
$clientIp = fb_get_ip();
if (!fb_check_rate($clientIp)) {
    fb_submit_fail('rate', 429);
}

// ── 7. Form + field validation ────────────────────────────────────────────────
$formId = trim($_POST['form_id'] ?? '');
if (!fb_is_valid_id($formId)) {
    fb_submit_fail('validation');
}

$form = fb_load_form($formId);
if ($form === null) {
    fb_submit_fail('not_found', 404);
}

$values = [];
foreach ($form['fields'] as $field) {
    if ($field['type'] === 'separator') {
        continue;
    }

    $key = $field['key'];

    if ($field['type'] === 'checkbox') {
        $values[$key] = !empty($_POST[$key]) ? '1' : '';
        continue;
    }

    $raw = trim(strip_tags((string)($_POST[$key] ?? '')));

    if ($field['required'] && $raw === '') {
        fb_submit_fail('validation');
    }

    if ($field['type'] === 'email' && $raw !== '' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
        fb_submit_fail('validation');
    }

    if ($field['type'] === 'dropdown' && $raw !== '' && !in_array($raw, $field['options'], true)) {
        fb_submit_fail('validation');
    }

    if (mb_strlen($raw) > 2000) {
        fb_submit_fail('validation');
    }

    $values[$key] = $raw;
}

// ── 8. Save submission ────────────────────────────────────────────────────────
$submittedAt = date('c');
$savedFile   = fb_save_submission($formId, $values, $clientIp);
fb_record_rate($clientIp);

if ($savedFile === null) {
    fb_submit_fail('storage', 500);
}

// ── 9. Generate PDF and send notification email ──────────────────────────────
$checkedLabel   = fb_t('fb_checkbox_yes', 'Yes', 'admin');
$uncheckedLabel = fb_t('fb_checkbox_no', 'No', 'admin');

$pdfContent = fb_build_submission_pdf($form, $values, $submittedAt, $checkedLabel, $uncheckedLabel);
fb_notify_submission($form, $values, $pdfContent, $submittedAt);

echo json_encode([
    'ok'      => true,
    'message' => $form['success_message'] !== '' ? $form['success_message'] : fb_t('fb_default_success', 'Thank you, your submission has been received.'),
]);
