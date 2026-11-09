<?php
/**
 * Newsletter Plugin — Unsubscribe Link Handler
 *
 * GET /plugins/newsletter/newsletter-unsubscribe.php?token=...
 * Verifies the signed token from a newsletter email and shows a
 * confirmation page — nothing is unsubscribed yet. Mirrors the Booking
 * plugin's two-step cancellation flow (booking-cancel.php): this protects
 * against accidental unsubscription from a link preview, an email
 * client's automatic link prefetching, or a security scanner following
 * the link on the subscriber's behalf, none of which should be able to
 * trigger a real state change.
 *
 * A confirmation click is still "at least as easy as subscribing" (the
 * CAN-SPAM/GDPR bar this used to cite for the one-click design) — it's
 * one extra click, not a login, a form, or a repeat of the original
 * signup. What actually violates that expectation is a link that fires
 * on preview/prefetch without the subscriber intending anything, which is
 * exactly what the one-click version was vulnerable to.
 *
 * POST /plugins/newsletter/newsletter-unsubscribe.php?token=...
 * Submitted by the confirmation page's own form. Re-verifies the same
 * token, then actually removes the subscriber.
 */

require_once __DIR__ . '/newsletter-functions.php';
require_once __DIR__ . '/newsletter-i18n.php';
require_once __DIR__ . '/newsletter-security.php';

$token        = $_GET['token'] ?? $_POST['token'] ?? '';
$subscriberId = nl_verify_action_token($token, 'unsubscribe');
$subscriber   = $subscriberId !== null ? nl_get_subscriber($subscriberId) : null;
// A token can verify successfully (valid signature) even after the
// subscriber has already been removed (link opened twice, or opened
// after already unsubscribing another way) — treated as invalid here
// rather than showing a stale confirmation prompt for someone who no
// longer exists to unsubscribe.
$isValid = $subscriberId !== null && $subscriber !== null;

$siteUrl = nl_site_base_url();

/**
 * Renders one of the page states (confirm / success / error) with a
 * shared shell — mirrors the Booking plugin's bk_render_cancel_page().
 */
function nl_render_unsubscribe_page(string $title, string $message, string $icon, string $bodyHtml = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(nl_current_locale('front')); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?php echo htmlspecialchars($title); ?></title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#1e293b;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:1.5rem}
.nl-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:2.5rem;max-width:420px;text-align:center}
.nl-icon{font-size:2.5rem;margin-bottom:0.75rem}
h1{font-size:1.25rem;margin:0 0 0.5rem}
p{color:#64748b;margin:0 0 1.5rem;line-height:1.5}
.nl-actions{display:flex;gap:0.6rem;justify-content:center;flex-wrap:wrap}
.nl-btn{display:inline-block;text-decoration:none;padding:0.6rem 1.4rem;border-radius:6px;font-weight:600;font-size:0.9rem;border:1px solid transparent;cursor:pointer;font-family:inherit}
.nl-btn--primary{background:#2563eb;color:#fff}
.nl-btn--danger{background:#dc2626;color:#fff}
.nl-btn--secondary{background:#fff;color:#334155;border-color:#e2e8f0}
</style>
</head>
<body>
<div class="nl-card">
    <div class="nl-icon"><?php echo $icon; ?></div>
    <h1><?php echo htmlspecialchars($title); ?></h1>
    <p><?php echo htmlspecialchars($message); ?></p>
    <?php echo $bodyHtml; ?>
</div>
</body>
</html>
    <?php
}

$backLink = '<a class="nl-btn nl-btn--primary" href="' . htmlspecialchars($siteUrl) . '">' . htmlspecialchars(nl_t('newsletter_back_to_site', 'Back to the site')) . '</a>';

// ── Invalid or forged token, or subscriber already gone ────────────────────
if (!$isValid) {
    nl_render_unsubscribe_page(
        nl_t('newsletter_unsub_page_title_error', 'Invalid link'),
        nl_t('newsletter_unsub_page_message_error', 'This unsubscribe link is invalid.'),
        '✕',
        $backLink
    );
    exit;
}

// ── Step 2: POST — actually unsubscribe ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Deleting an already-absent subscriber still reports success — the
    // end state the visitor cares about (not subscribed) is already true.
    $success = nl_delete_subscriber($subscriberId);

    if ($success) {
        nl_render_unsubscribe_page(
            nl_t('newsletter_unsub_page_title_success', 'Unsubscribed'),
            nl_t('newsletter_unsub_page_message_success', 'You have been removed from the newsletter list.'),
            '✓',
            $backLink
        );
        exit;
    }

    nl_render_unsubscribe_page(
        nl_t('newsletter_unsub_page_title_error', 'Invalid link'),
        nl_t('newsletter_error_generic', 'An error occurred. Please try again.'),
        '✕',
        $backLink
    );
    exit;
}

// ── Step 1: GET — show the confirmation prompt, nothing unsubscribed yet ───
$details = '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.9rem 1rem;margin:0 0 1.5rem;text-align:left;font-size:0.85rem;color:#334155;">'
    . htmlspecialchars($subscriber['email'] ?? '')
    . '</div>';

$form = '<form method="post" action="' . htmlspecialchars($_SERVER['REQUEST_URI']) . '">'
    . '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">'
    . '<div class="nl-actions">'
    . '<button type="submit" class="nl-btn nl-btn--danger">' . htmlspecialchars(nl_t('newsletter_unsub_confirm_button', 'Yes, unsubscribe me')) . '</button>'
    . '<a class="nl-btn nl-btn--secondary" href="' . htmlspecialchars($siteUrl) . '">' . htmlspecialchars(nl_t('newsletter_unsub_keep_button', 'No, keep my subscription')) . '</a>'
    . '</div>'
    . '</form>';

nl_render_unsubscribe_page(
    nl_t('newsletter_unsub_confirm_title', 'Unsubscribe from the newsletter?'),
    nl_t('newsletter_unsub_confirm_message', 'Please confirm you want to stop receiving the newsletter at the address below.'),
    '⚠',
    $details . $form
);
