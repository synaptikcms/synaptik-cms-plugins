<?php
/**
 * Booking Plugin — Public API Endpoint
 *
 * GET /booking.php?action=month_availability&type=...&month=Y-m
 * Returns JSON: { "Y-m-d": true|false, ... } for every day of the given
 * month — whether at least one slot is available that day. Cheap to compute
 * (stops at the first free slot per day) and used to grey out/enable days
 * in the month-grid calendar without needing full per-slot data yet.
 *
 * GET /booking.php?action=slots&type=...&date=Y-m-d
 * Returns JSON: { "date": "Y-m-d", "slots": [{"time": "09:00", "available": true}, ...] }
 * for a single day — used once the visitor has picked a day on the month
 * grid. In 'hide' display mode (default), unavailable slots are omitted
 * entirely. In 'show_disabled' mode, every slot in the day's window is
 * included so the client can render unavailable ones greyed out.
 *
 * Also exposes a fresh CSRF token for the booking form (?action=csrf).
 */

require_once __DIR__ . '/booking-functions.php';
require_once __DIR__ . '/booking-i18n.php';
require_once __DIR__ . '/booking-security.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? '';

if ($action === 'csrf') {
    echo json_encode(['csrf' => bk_generate_csrf()]);
    exit;
}

if ($action === 'month_availability') {
    $typeKey   = trim($_GET['type'] ?? '');
    $monthParam = trim($_GET['month'] ?? '');

    if (bk_get_appointment_type($typeKey) === null) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_type']);
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}$/', $monthParam) || strtotime($monthParam . '-01') === false) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_month']);
        exit;
    }

    $monthStart  = strtotime($monthParam . '-01');
    $daysInMonth = (int)date('t', $monthStart);

    $result = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = $monthParam . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
        // Cheap presence check — bk_available_slots() already stops scanning
        // once the day's window is exhausted, so this isn't recomputed work,
        // just read for its length rather than displaying every slot.
        $result[$date] = count(bk_available_slots($date, $typeKey)) > 0;
    }

    echo json_encode(['month' => $monthParam, 'availability' => $result]);
    exit;
}

if ($action === 'slots') {
    $typeKey = trim($_GET['type'] ?? '');
    $date    = trim($_GET['date'] ?? '');

    if (bk_get_appointment_type($typeKey) === null) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_type']);
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_date']);
        exit;
    }

    $settings = bk_load_settings();
    $slots    = bk_day_slots_detailed($date, $typeKey);

    if (($settings['slot_display_mode'] ?? 'hide') !== 'show_disabled') {
        $slots = array_values(array_filter($slots, fn($slot) => $slot['available']));
    }

    echo json_encode(['date' => $date, 'slots' => $slots]);
    exit;
}

if ($action === 'callback_window') {
    // Lets the public form restrict the callback time picker to the day's
    // actual availability window client-side, mirroring the server-side
    // check in booking-submit.php. Returns restricted: false when the
    // setting is off, so the client never needs to duplicate that logic.
    $date = trim($_GET['date'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_date']);
        exit;
    }

    $settings = bk_load_settings();
    if (empty($settings['restrict_callback_to_availability'])) {
        echo json_encode(['restricted' => false]);
        exit;
    }

    $window = bk_day_window($date);
    if ($window === null) {
        echo json_encode(['restricted' => true, 'closed' => true]);
        exit;
    }

    echo json_encode(['restricted' => true, 'closed' => false, 'start' => $window['start'], 'end' => $window['end']]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown_action']);
