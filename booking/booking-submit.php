<?php
/**
 * Booking Plugin — Public Submission Handler
 *
 * POST /booking/booking-submit.php
 * Validates and stores a new appointment request (status: pending).
 * Responds with JSON — the front-end form is AJAX-driven so the calendar
 * state doesn't reset on submit.
 *
 * Security layers, mirroring the core contact form:
 *  1. Method check
 *  2. Honeypot
 *  3. Timing check (min 3 s)
 *  4. Referrer validation
 *  5. CSRF token
 *  6. Rate limiting
 *  7. Input validation
 *  8. Slot re-validation (race-condition guard against double booking)
 */

require_once __DIR__ . '/booking-functions.php';
require_once __DIR__ . '/booking-i18n.php';
require_once __DIR__ . '/booking-security.php';
require_once __DIR__ . '/booking-mail.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function bk_submit_fail(string $key, string $fallback, int $httpCode = 400): void
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $key, 'message' => bk_t($key, $fallback)]);
    exit;
}

// ── 1. Method ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bk_submit_fail('booking_error_generic', 'An error occurred.', 405);
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
    bk_submit_fail('booking_error_generic', 'An error occurred.', 403);
}

// ── 5. CSRF ──────────────────────────────────────────────────────────────────
if (!bk_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
    bk_submit_fail('booking_error_generic', 'An error occurred.', 403);
}

// ── 6. Rate limiting ─────────────────────────────────────────────────────────
$clientIp = bk_get_ip();
if (!bk_check_rate($clientIp)) {
    bk_submit_fail('booking_error_rate', 'Too many requests.', 429);
}

// ── 7. Input validation ──────────────────────────────────────────────────────
$typeKey   = trim($_POST['type'] ?? '');
$date      = trim($_POST['date'] ?? '');
$startTime = trim($_POST['start_time'] ?? '');
$formFields  = bk_get_form_fields();
$fieldLimits = ['first_name' => 100, 'last_name' => 100, 'email' => 254, 'phone' => 30, 'notes' => 2000];
$fieldValues = [];
foreach ($formFields as $fieldKey => $fieldCfg) {
    $fieldValues[$fieldKey] = $fieldCfg['enabled'] ? trim(strip_tags($_POST[$fieldKey] ?? '')) : '';
}
$firstName = $fieldValues['first_name'];
$lastName  = $fieldValues['last_name'];
$email     = $fieldValues['email'];
$phone     = $fieldValues['phone'];
$notes     = $fieldValues['notes'];

$callbackRequested = !empty($_POST['callback_requested']) && !empty(bk_load_settings()['enable_callback']);
$callbackDate      = trim($_POST['callback_date'] ?? '');
$callbackTime      = trim($_POST['callback_time'] ?? '');

$type = bk_get_appointment_type($typeKey);
if ($type === null) {
    bk_submit_fail('booking_error_validation', 'Please fill in all required fields correctly.');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
    bk_submit_fail('booking_error_validation', 'Please fill in all required fields correctly.');
}
if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) {
    bk_submit_fail('booking_error_validation', 'Please fill in all required fields correctly.');
}
foreach ($formFields as $fieldKey => $fieldCfg) {
    $fieldValue = $fieldValues[$fieldKey];
    if ($fieldValue === '') {
        if ($fieldCfg['required']) {
            bk_submit_fail('booking_error_validation', 'Please fill in all required fields correctly.');
        }
        continue;
    }
    if (mb_strlen($fieldValue) > $fieldLimits[$fieldKey]) {
        bk_submit_fail('booking_error_validation', 'Please fill in all required fields correctly.');
    }
    if ($fieldKey === 'email' && !filter_var($fieldValue, FILTER_VALIDATE_EMAIL)) {
        bk_submit_fail('booking_error_validation', 'Please fill in all required fields correctly.');
    }
}

if ($callbackRequested) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $callbackDate) || strtotime($callbackDate) === false) {
        bk_submit_fail('booking_error_validation', 'Please fill in all required fields correctly.');
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $callbackTime)) {
        bk_submit_fail('booking_error_validation', 'Please fill in all required fields correctly.');
    }

    $bkSettingsForCallback = bk_load_settings();
    if (!empty($bkSettingsForCallback['restrict_callback_to_availability'])) {
        if (!bk_time_within_availability($callbackDate, $callbackTime)) {
            bk_submit_fail('booking_error_callback_outside_hours', 'The requested callback time is outside our availability hours.');
        }
    }
}

$endTime = bk_compute_end_time($date, $startTime, $typeKey);
if ($endTime === null) {
    bk_submit_fail('booking_error_generic', 'An error occurred.', 500);
}

$appointment = [
    'id'                  => bk_generate_id(),
    'type'                => $typeKey,
    'date'                => $date,
    'start_time'          => $startTime,
    'end_time'            => $endTime,
    'first_name'          => $firstName,
    'last_name'           => $lastName,
    'email'               => $email,
    'phone'               => $phone,
    'notes'               => $notes,
    'callback_requested'  => $callbackRequested,
    'callback_date'       => $callbackRequested ? $callbackDate : '',
    'callback_time'       => $callbackRequested ? $callbackTime : '',
    'status'              => 'pending',
    'created_at'          => date('Y-m-d H:i:s'),
    // Stored in plaintext (previously hashed) so the admin can see it in
    // the Requests/History views and in the new-request notification email
    // — never exposed to the client themselves.
    'ip'                  => $clientIp,
];

// ── 8. Slot re-validation + save, both under one lock ────────────────────────
// The slot list shown client-side may be stale (another visitor could have
// booked it seconds ago), so it's always re-checked server-side — but the
// check alone still leaves a gap: two requests could both pass it before
// either has saved. Holding the lock across check-and-save closes that gap.
$id = bk_with_booking_lock(function () use ($date, $typeKey, $startTime, $appointment) {
    if (!bk_is_slot_available($date, $typeKey, $startTime)) {
        return 'slot_taken';
    }
    return bk_save_appointment($appointment);
});

if ($id === 'slot_taken') {
    bk_submit_fail('booking_error_slot_taken', 'This slot has just been booked by someone else.', 409);
}
if ($id === null) {
    bk_submit_fail('booking_error_generic', 'An error occurred.', 500);
}

bk_record_rate($clientIp);

// Notify — failures here don't roll back the booking; the slot is reserved
// regardless of mail delivery, and the admin can always see it in the panel.
$_bkSettings = bk_load_settings();
if (!empty($_bkSettings['send_pending_email'])) {
    bk_notify_client_pending($appointment);
}
bk_notify_admin_new_request($appointment);

echo json_encode([
    'success' => true,
    'message' => bk_t('booking_success_message', 'Your appointment request has been sent.'),
]);
