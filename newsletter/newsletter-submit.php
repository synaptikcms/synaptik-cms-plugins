<?php
/**
 * Newsletter Plugin — Public Signup Handler
 *
 * POST /plugins/newsletter/newsletter-submit.php
 * Validates the submitted email, creates a pending subscriber, and sends
 * the double opt-in confirmation email. Responds with JSON — the signup
 * form is AJAX-driven.
 *
 * Security layers, mirroring the core contact form and the Booking plugin:
 *  1. Method check
 *  2. Honeypot
 *  3. Timing check (min 3 s)
 *  4. Referrer validation
 *  5. CSRF token
 *  6. Rate limiting
 *  7. Input validation
 */

require_once __DIR__ . '/newsletter-functions.php';
require_once __DIR__ . '/newsletter-i18n.php';
require_once __DIR__ . '/newsletter-security.php';
require_once __DIR__ . '/newsletter-mail.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function nl_submit_fail(string $key, string $fallback, int $httpCode = 400): void
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $key, 'message' => nl_t($key, $fallback)]);
    exit;
}

// ── 1. Method ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nl_submit_fail('newsletter_error_generic', 'An error occurred.', 405);
}

// ── 2. Honeypot ──────────────────────────────────────────────────────────────
if (!empty($_POST['_hp'])) {
    // Silent fake success — don't tip off bots.
    echo json_encode(['success' => true]);
    exit;
}

// ── 3. Timing check ──────────────────────────────────────────────────────────
$formTime = (int)($_POST['_ft'] ?? 0);
if ($formTime === 0 || (time() - $formTime) < 3) {
    echo json_encode(['success' => true]);
    exit;
}

// ── 4. Referrer validation ───────────────────────────────────────────────────
$siteHost    = parse_url((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST);
$refererHost = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
if (empty($refererHost) || strtolower($refererHost) !== strtolower((string)$siteHost)) {
    nl_submit_fail('newsletter_error_generic', 'An error occurred.', 403);
}

// ── 5. CSRF ──────────────────────────────────────────────────────────────────
if (!nl_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
    nl_submit_fail('newsletter_error_generic', 'An error occurred.', 403);
}

// ── 6. Rate limiting ─────────────────────────────────────────────────────────
$clientIp = nl_get_ip();
if (!nl_check_rate($clientIp)) {
    nl_submit_fail('newsletter_error_rate', 'Too many requests. Please try again later.', 429);
}

// ── 7. Input validation ──────────────────────────────────────────────────────
$email = trim(strip_tags($_POST['email'] ?? ''));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    nl_submit_fail('newsletter_error_validation', 'Please enter a valid email address.');
}

$nameMode = nl_name_field_mode();
$name     = $nameMode === 'off' ? '' : nl_clean_name((string)($_POST['name'] ?? ''));

if ($nameMode === 'required' && $name === '') {
    nl_submit_fail('newsletter_error_name', 'Please enter your name.');
}

nl_record_rate($clientIp);

// Already confirmed — respond with the same generic success message so the
// endpoint never reveals whether an email is already subscribed.
$existing = nl_find_by_email($email);
if ($existing !== null && $existing[1]['status'] === 'confirmed') {
    echo json_encode(['success' => true, 'message' => nl_t('newsletter_success_message', 'Check your inbox to confirm your subscription.')]);
    exit;
}

$subscriberId = nl_create_or_reuse_pending($email, $name);
$mailSent = nl_send_confirmation_email($subscriberId, $email);

// mail() failures are silent by design (PHP gives no error detail) — log
// enough to diagnose a misconfigured local mail transport (e.g. MAMP with
// no sendmail/SMTP set up) without exposing delivery status to the caller.
// The JSON response stays a generic success either way: this endpoint must
// never reveal whether a given address is a real, deliverable inbox.
if (!$mailSent) {
    error_log('[Newsletter] Failed to send confirmation email to ' . $email . ' — check the server mail configuration (sendmail_path / SMTP).');
}

echo json_encode([
    'success' => true,
    'message' => nl_t('newsletter_success_message', 'Check your inbox to confirm your subscription.'),
]);
