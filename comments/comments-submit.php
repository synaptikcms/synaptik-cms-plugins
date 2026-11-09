<?php
/**
 * Comments Plugin — Submission Handler
 *
 * Standalone POST endpoint (not routed through index.php). Security layers,
 * in order:
 *  1. Method check           — POST only
 *  2. Honeypot                — _hp must be empty
 *  3. Timing check            — minimum 3 s between page load and submit
 *  4. Referrer validation     — request must come from own domain
 *  5. CSRF token               — HMAC-SHA256, stateless, TTL 2 h
 *  6. Rate limiting            — max 5 comments per IP per hour
 *  7. Target validation        — type/slug valid, comments enabled for type
 *  8. Field validation         — name, email, content length
 *  9. Threading validation     — a reply cannot target another reply
 * 10. Spam content detection   — URL density, repetition, admin blacklist
 * 11. hCaptcha verification    — server-side token check (when enabled)
 * 12. Save comment (status per moderation_mode)
 *
 * Responds with JSON — consumed by assets/js/comments-form.js.
 */

// Loads the CMS core so sanitizeSlug() is available — this endpoint is a
// standalone script (POST target of the public <form>, never routed
// through index.php), so nothing else loads the core here otherwise.
require_once dirname(__DIR__, 2) . '/core/functions.php';

require_once __DIR__ . '/comments-functions.php';
require_once __DIR__ . '/comments-data-layer.php';
require_once __DIR__ . '/comments-i18n.php';
require_once __DIR__ . '/comments-security.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function cm_submit_fail(string $reason, int $httpCode = 400): void
{
    http_response_code($httpCode);
    echo json_encode(['ok' => false, 'error' => $reason]);
    exit;
}

// ── 1. Method ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cm_submit_fail('method', 405);
}

// ── 2. Honeypot — silently "succeed" without doing any work ─────────────────
if (!empty($_POST['_hp'])) {
    echo json_encode(['ok' => true, 'status' => 'approved']);
    exit;
}

// ── 3. Timing check ──────────────────────────────────────────────────────────
$formTime = (int)($_POST['_ft'] ?? 0);
if ($formTime === 0 || (time() - $formTime) < 3) {
    echo json_encode(['ok' => true, 'status' => 'approved']); // silent — don't tip off bots
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
    cm_submit_fail('referrer', 403);
}

// ── 5. CSRF token ─────────────────────────────────────────────────────────────
$csrfToken = trim($_POST['_csrf'] ?? '');
if (!cm_verify_csrf($csrfToken)) {
    cm_submit_fail('csrf', 403);
}

// ── 6. Rate limiting ──────────────────────────────────────────────────────────
$clientIp = cm_get_ip();
if (!cm_check_rate($clientIp)) {
    cm_submit_fail('rate', 429);
}

// ── 7. Target validation ──────────────────────────────────────────────────────
$type = trim($_POST['type'] ?? '');
$slug = sanitizeSlug(trim($_POST['slug'] ?? ''));

if (!cm_is_valid_type($type) || $slug === '') {
    cm_submit_fail('validation');
}
if (!cm_type_enabled($type)) {
    cm_submit_fail('disabled', 403);
}

// ── 8. Field validation ───────────────────────────────────────────────────────
$authorName  = trim(strip_tags($_POST['author_name']  ?? ''));
$authorEmail = trim(strip_tags($_POST['author_email'] ?? ''));
$content     = trim(strip_tags($_POST['content']      ?? ''));
$parentId    = trim($_POST['parent_id'] ?? '');

if (empty($authorName) || mb_strlen($authorName) > 100) {
    cm_submit_fail('validation');
}
if (empty($authorEmail) || !filter_var($authorEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($authorEmail) > 254) {
    cm_submit_fail('validation');
}
if (empty($content) || mb_strlen($content) < 2 || mb_strlen($content) > 3000) {
    cm_submit_fail('validation');
}

// ── 9. Threading validation (max one level of replies) ──────────────────────
if ($parentId !== '') {
    $parentComment = cm_find_comment($parentId);
    if ($parentComment === null) {
        cm_submit_fail('validation');
    }
    [$parentType, $parentSlug, $parentData] = $parentComment;

    // The parent must belong to the same item being commented on, and must
    // itself be a root comment — a reply-to-a-reply would create a second
    // nesting level, which the front-end tree renderer does not support.
    if ($parentType !== $type || $parentSlug !== $slug || !empty($parentData['parent_id'])) {
        cm_submit_fail('validation');
    }
}

// ── 10. Spam content detection ────────────────────────────────────────────────
$settings = cm_load_settings();
if (cm_is_spam($content, $settings['blacklist'])) {
    echo json_encode(['ok' => true, 'status' => 'pending']); // silent — don't tell spammers they were caught
    exit;
}

// ── 11. hCaptcha server-side verification ─────────────────────────────────────
if (!empty($settings['use_hcaptcha'])) {
    $hcaptchaSecret = cm_core_hcaptcha_secret();
    if ($hcaptchaSecret !== '') {
        $hcaptchaToken = trim($_POST['h-captcha-response'] ?? '');
        if (empty($hcaptchaToken) || !cm_verify_hcaptcha($hcaptchaToken, $hcaptchaSecret)) {
            cm_submit_fail('captcha');
        }
    }
}

// ── 12. Save comment ──────────────────────────────────────────────────────────
$status = ($settings['moderation_mode'] === 'auto') ? 'approved' : 'pending';

$comment = [
    'author_name'  => $authorName,
    'author_email' => $authorEmail,
    'content'      => $content,
    'status'       => $status,
    'created_at'   => date('Y-m-d H:i:s'),
    'parent_id'    => $parentId !== '' ? $parentId : null,
    'ip_hash'      => hash('sha256', $clientIp),
];

cm_add_comment($type, $slug, $comment);
cm_record_rate($clientIp);

echo json_encode([
    'ok'     => true,
    'status' => $status,
]);
