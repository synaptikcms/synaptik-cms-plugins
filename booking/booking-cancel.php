<?php
/**
 * Booking Plugin — Client Cancellation Link Handler
 *
 * GET /plugins/booking/booking-cancel.php?token=...
 * Verifies the signed token from a pending/confirmed notification email and
 * shows a confirmation page with the appointment's details — nothing is
 * cancelled yet. This two-step flow protects against accidental
 * cancellation from a link preview, an email client's automatic link
 * prefetching, or a security scanner following the link on the client's
 * behalf, none of which should be able to trigger a real state change.
 *
 * POST /plugins/booking/booking-cancel.php?token=...
 * Submitted by the confirmation page's own form. Re-verifies the same
 * token, then actually cancels the appointment. Only pending or confirmed
 * appointments can be cancelled this way; an already refused/cancelled
 * appointment, an invalid/forged token, or a request past the configured
 * cancellation cutoff (see bk_client_cancellation_allowed()) shows a
 * distinct message instead at either step.
 *
 * Notifies the admin (bk_notify_admin_cancelled_by_client()) on success —
 * no confirmation email is sent back to the client, since they are the one
 * who just performed the action and the result page already tells them.
 */

require_once __DIR__ . '/booking-functions.php';
require_once __DIR__ . '/booking-i18n.php';
require_once __DIR__ . '/booking-security.php';
require_once __DIR__ . '/booking-mail.php';

$token         = $_GET['token'] ?? $_POST['token'] ?? '';
$appointmentId = bk_verify_action_token($token, 'cancel');
$appointment   = $appointmentId !== null ? bk_load_appointment($appointmentId) : null;
$isCancellable = $appointment !== null && in_array($appointment['status'], ['pending', 'confirmed'], true);
$isTooLate     = $isCancellable && !bk_client_cancellation_allowed($appointment);

$siteUrl = bk_site_base_url();

/**
 * Renders one of the page states (confirm / success / too-late / error)
 * with a shared shell — kept as a single small template rather than
 * separate files, since only the icon/title/message/body region changes
 * between them.
 */
function bk_render_cancel_page(string $title, string $message, string $icon, string $bodyHtml = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(bk_current_locale('front')); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?php echo htmlspecialchars($title); ?></title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#1e293b;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:1.5rem}
.bk-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:2.5rem;max-width:420px;text-align:center}
.bk-icon{font-size:2.5rem;margin-bottom:0.75rem}
h1{font-size:1.25rem;margin:0 0 0.5rem}
p{color:#64748b;margin:0 0 1.5rem;line-height:1.5}
.bk-details{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.9rem 1rem;margin:0 0 1.5rem;text-align:left;font-size:0.85rem;color:#334155}
.bk-details strong{color:#1e293b}
.bk-actions{display:flex;gap:0.6rem;justify-content:center;flex-wrap:wrap}
.bk-btn{display:inline-block;text-decoration:none;padding:0.6rem 1.4rem;border-radius:6px;font-weight:600;font-size:0.9rem;border:1px solid transparent;cursor:pointer;font-family:inherit}
.bk-btn--primary{background:#2563eb;color:#fff}
.bk-btn--danger{background:#dc2626;color:#fff}
.bk-btn--secondary{background:#fff;color:#334155;border-color:#e2e8f0}
</style>
</head>
<body>
<div class="bk-card">
    <div class="bk-icon"><?php echo $icon; ?></div>
    <h1><?php echo htmlspecialchars($title); ?></h1>
    <p><?php echo htmlspecialchars($message); ?></p>
    <?php echo $bodyHtml; ?>
</div>
</body>
</html>
    <?php
}

$backLink = '<a class="bk-btn bk-btn--primary" href="' . htmlspecialchars($siteUrl) . '">' . htmlspecialchars(bk_t('booking_back_to_site', 'Back to the site')) . '</a>';

// ── Invalid or forged token, or appointment no longer cancellable ──────────
if (!$isCancellable) {
    bk_render_cancel_page(
        bk_t('booking_cancel_page_title_error', 'Invalid or expired link'),
        bk_t('booking_cancel_page_message_error', 'This cancellation link is invalid or the appointment can no longer be cancelled.'),
        '✕',
        $backLink
    );
    exit;
}

// ── Past the configured cancellation cutoff ─────────────────────────────────
// Checked before the POST branch too: a link opened just before the cutoff
// and submitted just after it must not slip through and cancel anyway.
if ($isTooLate) {
    bk_render_cancel_page(
        bk_t('booking_cancel_too_late_title', 'Too late to cancel online'),
        bk_t('booking_cancel_too_late_message', 'This appointment is coming up too soon to be cancelled automatically. Please contact us directly if you still need to cancel or reschedule.'),
        '⏰',
        $backLink
    );
    exit;
}

// ── Step 2: POST — actually cancel ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $success = bk_update_appointment($appointmentId, ['status' => 'cancelled']);
    if ($success) {
        bk_notify_admin_cancelled_by_client(array_merge($appointment, ['status' => 'cancelled']));

        bk_render_cancel_page(
            bk_t('booking_cancel_page_title_success', 'Appointment cancelled'),
            bk_t('booking_cancel_page_message_success', 'Your appointment has been cancelled.'),
            '✓',
            $backLink
        );
        exit;
    }

    bk_render_cancel_page(
        bk_t('booking_cancel_page_title_error', 'Invalid or expired link'),
        bk_t('booking_error_generic', 'An error occurred. Please try again.'),
        '✕',
        $backLink
    );
    exit;
}

// ── Step 1: GET — show the confirmation prompt, nothing cancelled yet ──────
$type = bk_get_appointment_type($appointment['type']);
$dateFormatted = function_exists('format_date') ? format_date($appointment['date']) : bk_format_date_fallback($appointment['date']);

$details = '<div class="bk-details">'
    . '<div>' . htmlspecialchars($type['label'] ?? $appointment['type']) . '</div>'
    . '<div><strong>' . htmlspecialchars($dateFormatted) . '</strong> — ' . htmlspecialchars($appointment['start_time']) . '</div>'
    . '</div>';

$form = '<form method="post" action="' . htmlspecialchars($_SERVER['REQUEST_URI']) . '">'
    . '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">'
    . '<div class="bk-actions">'
    . '<button type="submit" class="bk-btn bk-btn--danger">' . htmlspecialchars(bk_t('booking_cancel_confirm_button', 'Yes, cancel my appointment')) . '</button>'
    . '<a class="bk-btn bk-btn--secondary" href="' . htmlspecialchars($siteUrl) . '">' . htmlspecialchars(bk_t('booking_cancel_keep_button', 'No, keep it')) . '</a>'
    . '</div>'
    . '</form>';

bk_render_cancel_page(
    bk_t('booking_cancel_confirm_title', 'Cancel this appointment?'),
    bk_t('booking_cancel_confirm_message', 'Please confirm you want to cancel the appointment below. This cannot be undone.'),
    '⚠',
    $details . $form
);
