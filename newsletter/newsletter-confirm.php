<?php
/**
 * Newsletter Plugin — Confirmation Link Handler
 *
 * GET /plugins/newsletter/newsletter-confirm.php?token=...
 * Verifies the signed token from the confirmation email, marks the
 * subscriber as confirmed, sends the welcome email, and shows a small
 * standalone HTML page (this is a link a human clicks in their inbox —
 * not an AJAX call, so it renders a page rather than JSON).
 */

require_once __DIR__ . '/newsletter-functions.php';
require_once __DIR__ . '/newsletter-i18n.php';
require_once __DIR__ . '/newsletter-security.php';
require_once __DIR__ . '/newsletter-mail.php';

$token = $_GET['token'] ?? '';
$subscriberId = nl_verify_action_token($token, 'confirm');

$success = false;
if ($subscriberId !== null) {
    $subscriber = nl_get_subscriber($subscriberId);
    if ($subscriber !== null) {
        $success = nl_confirm_subscriber($subscriberId);
        if ($success) {
            nl_send_welcome_email($subscriber['email']);
        }
    }
}

$title   = $success ? nl_t('newsletter_confirm_page_title_success', 'Subscription confirmed') : nl_t('newsletter_confirm_page_title_error', 'Invalid or expired link');
$message = $success ? nl_t('newsletter_confirm_page_message_success', 'Thank you — your subscription is now active.') : nl_t('newsletter_confirm_page_message_error', 'This confirmation link is invalid or has already been used.');

$siteUrl = nl_site_base_url();
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
a.nl-btn{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:0.6rem 1.4rem;border-radius:6px;font-weight:600;font-size:0.9rem}
</style>
</head>
<body>
<div class="nl-card">
    <div class="nl-icon"><?php echo $success ? '✓' : '✕'; ?></div>
    <h1><?php echo htmlspecialchars($title); ?></h1>
    <p><?php echo htmlspecialchars($message); ?></p>
    <a class="nl-btn" href="<?php echo htmlspecialchars($siteUrl); ?>"><?php echo htmlspecialchars(nl_t('newsletter_back_to_site', 'Back to the site')); ?></a>
</div>
</body>
</html>
