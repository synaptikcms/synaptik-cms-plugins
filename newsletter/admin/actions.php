<?php
/**
 * Newsletter Plugin — Admin Actions
 *
 * Handles all state-changing admin operations. Every action requires the
 * core admin session and a valid CSRF token.
 *
 * send_digest_batch and send_custom_batch respond with JSON instead of a
 * redirect: the admin page's JS drives them in a loop (offset by offset)
 * so a large subscriber list is mailed in small chunks rather than one
 * long-running mail() loop that risks a PHP timeout or tripping the
 * host's outbound rate limits. Every other action is a simple form POST
 * with a redirect back to the tab it was triggered from.
 */

require_once __DIR__ . '/bootstrap.php';
nl_admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . nl_admin_page_url());
    exit;
}

$action = $_POST['action'] ?? '';

// ── Shared batch-sending logic (digest and custom newsletters alike) ────────
// Both actions send the exact same way — only how $subject/$body are first
// computed (offset 0) differs. Recording history happens once, when the
// last batch completes.
function nl_handle_send_batch(string $kind): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    if (!nl_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
        exit;
    }

    $offset    = max(0, (int)($_POST['offset'] ?? 0));
    $batchSize = 20; // small enough to stay well under typical shared-hosting execution/mail-rate limits

    // Subject/body are computed once by the first call (offset 0) and
    // passed back by the client on every subsequent call, so every batch
    // sends the exact same content even if the underlying data changes
    // mid-send (e.g. a new article published while a digest is going out).
    //
    // For a digest, $body carries the *unrendered* template (still
    // containing {articles}/{site_name}/{email}) rather than final HTML —
    // nl_send_newsletter_to_subscriber() renders it once per recipient so
    // {email} resolves to each person's own address. See
    // nl_render_digest_template() in newsletter-mail.php.
    $subject = trim($_POST['subject'] ?? '');
    $body    = $_POST['body'] ?? '';

    if ($offset === 0) {
        if ($kind === 'digest') {
            $settings = nl_load_settings();
            $articles = nl_articles_since($settings['last_digest_sent_at']);

            if (empty($articles)) {
                echo json_encode(['success' => false, 'error' => 'no_new_articles']);
                exit;
            }

            $coreSettings = function_exists('loadConfig') ? loadConfig() : [];
            $subject = nl_t('newsletter_digest_subject', 'Newsletter') . ' — ' . ($coreSettings['site_title'] ?? '');
            // $body carries only the article-cards HTML here, NOT the final
            // wrapped email — nl_send_newsletter_to_subscriber() calls
            // nl_render_digest_template() per recipient below (isDigestTemplate
            // = true) to apply the admin's custom wrapper (or the built-in
            // default) and resolve {site_name}/{email} for that recipient.
            $body = nl_build_digest_articles_html($articles);
        } else {
            // 'custom' — subject and body come from the composer form.
            $subject = trim($_POST['subject'] ?? '');
            $body    = $_POST['body'] ?? '';

            if ($subject === '' || $body === '') {
                echo json_encode(['success' => false, 'error' => 'empty_composer']);
                exit;
            }
        }
    }

    if ($subject === '' || $body === '') {
        echo json_encode(['success' => false, 'error' => 'invalid_batch_state']);
        exit;
    }

    // Keyed by subscriber ID (not array_values()'d) — the ID is needed to
    // build each recipient's personal unsubscribe link.
    $confirmed = nl_load_confirmed_subscribers();
    $ids       = array_keys($confirmed);
    $total     = count($ids);

    if ($offset === 0 && $total === 0) {
        echo json_encode(['success' => false, 'error' => 'no_subscribers']);
        exit;
    }

    $batchIds = array_slice($ids, $offset, $batchSize);
    foreach ($batchIds as $id) {
        nl_send_newsletter_to_subscriber($id, $confirmed[$id]['email'], $subject, $body, $kind === 'digest');
    }

    $newOffset = $offset + count($batchIds);
    $done      = $newOffset >= $total;

    if ($done) {
        if ($kind === 'digest') {
            $settings = nl_load_settings();
            $settings['last_digest_sent_at'] = date('Y-m-d H:i:s');
            nl_save_settings($settings);
        }
        // For history display, store the fully-rendered wrapper (template +
        // article cards) rather than the raw {articles}-only fragment used
        // for the actual per-recipient sends — {email} has no single
        // correct value here (it differed per recipient), so it's rendered
        // with an empty string, leaving {site_name} as the only placeholder
        // that resolves meaningfully in a history record.
        $historyBody = ($kind === 'digest') ? nl_render_digest_template($body, '') : $body;
        nl_record_newsletter_sent($subject, $historyBody, $kind, $total);
    }

    echo json_encode([
        'success' => true,
        'sent'    => $newOffset,
        'total'   => $total,
        'done'    => $done,
        'subject' => $subject,
        'body'    => $body,
    ]);
    exit;
}

if ($action === 'send_digest_batch') {
    nl_handle_send_batch('digest');
}

if ($action === 'send_custom_batch') {
    nl_handle_send_batch('custom');
}

// ── All other actions: classic form POST + redirect ─────────────────────────

if (!nl_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

switch ($action) {

    case 'delete_subscriber':
        $id = trim($_POST['id'] ?? '');
        nl_delete_subscriber($id);
        header('Location: ' . nl_admin_page_url(['view' => 'subscribers', 'msg' => 'deleted']));
        exit;

    case 'delete_newsletter':
        $id = trim($_POST['id'] ?? '');
        nl_delete_newsletter($id);
        header('Location: ' . nl_admin_page_url(['view' => 'history', 'msg' => 'newsletter_deleted']));
        exit;

    case 'save_settings':
        $settings = nl_load_settings();

        $fromEmail = trim($_POST['from_email'] ?? '');
        if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $settings['from_email'] = $fromEmail;
        }
        $settings['from_name'] = trim(strip_tags($_POST['from_name'] ?? ''));
        $nameField = (string)($_POST['name_field'] ?? 'off');
        $settings['name_field']    = in_array($nameField, ['off', 'optional', 'required'], true) ? $nameField : 'off';
        $settings['name_fallback'] = nl_clean_name((string)($_POST['name_fallback'] ?? ''));
        $buttonColor = (string)($_POST['button_color'] ?? '');
        $settings['button_color'] = (empty($_POST['button_color_default']) && preg_match('/^#[0-9a-fA-F]{6}$/', $buttonColor)) ? strtolower($buttonColor) : '';
        $settings['show_dashboard_widget'] = !empty($_POST['show_dashboard_widget']);

        nl_save_settings($settings);
        header('Location: ' . nl_admin_page_url(['view' => 'settings', 'msg' => 'saved']));
        exit;

    case 'save_digest_template':
        $settings = nl_load_settings();
        // Same trust level as the free-form composer's WYSIWYG output and
        // the Booking plugin's email templates: stored as-is, since only an
        // authenticated admin can reach this endpoint. Must contain
        // {articles} to actually show any content — not enforced server-side
        // (an admin might legitimately want a template that only shows a
        // greeting one week), same latitude given to the other WYSIWYG
        // templates in this codebase.
        $settings['digest_template'] = trim($_POST['digest_template'] ?? '');

        nl_save_settings($settings);
        header('Location: ' . nl_admin_page_url(['view' => 'digest_template', 'msg' => 'saved']));
        exit;

    case 'save_smtp_settings':
        $settings = nl_load_settings();

        $smtp = $settings['smtp'];
        $smtp['enabled']  = !empty($_POST['smtp_enabled']);
        $smtp['host']     = trim(strip_tags($_POST['smtp_host'] ?? ''));
        $smtp['port']     = max(1, min(65535, (int)($_POST['smtp_port'] ?? 587)));
        $smtp['auth']     = !empty($_POST['smtp_auth']);
        $smtp['username'] = trim(strip_tags($_POST['smtp_username'] ?? ''));

        $encryption = trim($_POST['smtp_encryption'] ?? 'tls');
        $smtp['encryption'] = in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls';

        $settings['smtp'] = $smtp;
        nl_save_settings($settings);

        // Password field is submitted empty to mean "keep the existing
        // one" (see templates/smtp.php) — only overwrite the stored
        // password when the admin actually typed something.
        $submittedPassword = $_POST['smtp_password'] ?? '';
        if ($submittedPassword !== '') {
            nl_save_smtp_password($submittedPassword);
        }

        header('Location: ' . nl_admin_page_url(['view' => 'smtp', 'msg' => 'saved']));
        exit;

    case 'smtp_preset_brevo':
        $settings = nl_load_settings();
        $settings['smtp'] = array_merge($settings['smtp'], [
            'enabled'    => true,
            'host'       => 'smtp-relay.brevo.com',
            'port'       => 587,
            'encryption' => 'tls',
            'auth'       => true,
        ]);
        nl_save_settings($settings);
        header('Location: ' . nl_admin_page_url(['view' => 'smtp', 'msg' => 'brevo_applied']));
        exit;

    case 'send_smtp_test':
        $testEmail = trim($_POST['test_email'] ?? '');
        if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            header('Location: ' . nl_admin_page_url(['view' => 'smtp', 'msg' => 'test_failed']));
            exit;
        }

        // Goes through the exact same nl_send_plain_mail() every
        // transactional email uses — SMTP if enabled and configured,
        // native mail() otherwise — so a successful test is a genuine
        // signal the currently-saved configuration works.
        $testSubject = nl_t('newsletter_smtp_test_subject', 'Synaptik CMS Newsletter — test email');
        $testBody    = nl_t('newsletter_smtp_test_body', 'This is a test email from the Newsletter plugin’s SMTP settings. If you received this, your configuration is working.');

        $sent = nl_send_plain_mail($testEmail, $testSubject, $testBody);

        if (!$sent) {
            // nl_send_plain_mail() logs the underlying PHPMailer error via
            // nl_log_mail_error() when SMTP is in use, and keeps a
            // same-request copy retrievable via nl_last_mail_error() —
            // shown directly on the SMTP page so the admin doesn't need
            // server log access to see why the test failed.
            $detail = nl_last_mail_error();
            $_SESSION['nl_smtp_test_error'] = $detail !== '' ? $detail : nl_t('newsletter_smtp_test_error_generic', "Check whether your host's mail function is configured, or enable SMTP above.");
            header('Location: ' . nl_admin_page_url(['view' => 'smtp', 'msg' => 'test_failed']));
            exit;
        }

        header('Location: ' . nl_admin_page_url(['view' => 'smtp', 'msg' => 'test_sent']));
        exit;

    default:
        header('Location: ' . nl_admin_page_url());
        exit;
}
