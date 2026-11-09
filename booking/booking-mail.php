<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Booking Plugin — Mail
 *
 * Sends appointment/callback notification emails with an .ics attachment.
 * Uses PHP's native mail() with a hand-built MIME multipart body — no
 * external mail library, consistent with the CMS core's contact form
 * (contact-process.php), which the CSRF/rate-limit helpers below mirror.
 */

if (defined('BK_MAIL_LOADED')) return;
define('BK_MAIL_LOADED', true);

require_once __DIR__ . '/booking-ics.php';

// PHPMailer is vendored directly (no Composer in this CMS — see
// vendor/phpmailer/). Loaded unconditionally but cheaply: three class
// files, no autoloader overhead, and every send function below checks
// bk_smtp_enabled() before ever instantiating PHPMailer, so requiring the
// classes here has no behavioural effect for the (default) mail()-only
// path — it only matters once SMTP is turned on in Settings.
//
// Guarded with class_exists() rather than a bare require_once: multiple
// plugins (Booking, Newsletter) each vendor their own copy of the same
// PHPMailer\PHPMailer\* classes for independent installability, but every
// active plugin is loaded into the same PHP process on an admin request
// (see plugin-api.php's pl_load_active_plugins()) — require_once alone
// only prevents the *same* file from loading twice, not two *different*
// files declaring the *same* class, which is a fatal "Cannot redeclare
// class" error. class_exists() makes whichever plugin's copy loads first
// win, and every plugin's copy is functionally identical (same vendored
// version), so it doesn't matter which one actually wins.
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
    require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Whether the SMTP transport is configured and turned on. Every send
 * function in this file checks this before choosing between PHPMailer
 * (SMTP) and PHP's native mail() — see bk_get_mailer().
 */
function bk_smtp_enabled(): bool
{
    $settings = bk_load_settings();
    $smtp     = $settings['smtp'] ?? [];

    return !empty($smtp['enabled']) && trim($smtp['host'] ?? '') !== '';
}

/**
 * Builds a PHPMailer instance pre-configured for SMTP from the plugin's
 * stored settings (host/port/encryption/username in config.json, the
 * password in the private/ store — see bk_load_smtp_password()), with the
 * From/Reply-To already applied via bk_from_address().
 *
 * Returns null when SMTP isn't enabled/configured — callers fall back to
 * the existing native mail() path in that case, so turning SMTP off (or
 * never configuring it) reproduces the exact previous behaviour with zero
 * code-path changes elsewhere.
 *
 * Exceptions are enabled on the returned instance (constructor arg
 * `true`) so a connection/auth failure surfaces as a catchable
 * PHPMailerException in the calling bk_send_*() wrapper below, rather
 * than as a silently-swallowed false return the way mail() behaves — SMTP
 * failures (wrong password, blocked port, expired cert) are exactly the
 * kind of thing an admin needs to actually see, not just infer from a
 * missing email days later.
 */
function bk_get_mailer(): ?PHPMailer
{
    if (!bk_smtp_enabled()) return null;

    $settings = bk_load_settings();
    $smtp     = $settings['smtp'];
    $from     = bk_from_address();

    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host       = $smtp['host'];
    $mailer->Port       = (int)($smtp['port'] ?? 587);
    $mailer->SMTPAuth   = !empty($smtp['auth']);
    $mailer->CharSet    = PHPMailer::CHARSET_UTF8;
    $mailer->XMailer    = 'SynaptikCMS/Booking';

    $encryption = $smtp['encryption'] ?? 'tls';
    if ($encryption === 'tls') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'ssl') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mailer->SMTPSecure = '';
        $mailer->SMTPAutoTLS = false;
    }

    if ($mailer->SMTPAuth) {
        $mailer->Username = $smtp['username'] ?? '';
        $mailer->Password = bk_load_smtp_password();
    }

    $mailer->setFrom($from['email'], $from['name']);

    return $mailer;
}

/**
 * Strips CR/LF from any value going into a mail header — prevents header
 * injection. Same guard as the core contact form.
 */
function bk_sanitize_header(string $value): string
{
    return str_replace(["\r", "\n", "\r\n"], '', $value);
}

/**
 * Encodes a MIME part body as quoted-printable (RFC 2045).
 *
 * Every text/plain and text/html part in this file was previously sent as
 * raw 8bit. That works until a single logical line (a long admin-authored
 * HTML template with no manual line breaks is the common case here) grows
 * past what RFC 5322 allows per line. Once that happens, PHP's mail() /
 * the local MTA folds the offending line to stay compliant, but it folds
 * blindly at a byte limit with no awareness of HTML structure. If the cut
 * lands inside a URL (e.g. the client cancellation link appended by
 * bk_append_cancel_link_html()), the inserted fold is rendered by some
 * mail clients as a literal space, corrupting the link (reported:
 * booking-cancel.php becoming bookin[space]g-cancel.php, a 404).
 *
 * quoted-printable avoids this: PHP's quoted_printable_encode() wraps at
 * 76 chars using soft line breaks (=\r\n) that decode back to nothing, so
 * no later MTA re-folding is needed and no stray whitespace can land
 * inside a URL.
 */
function bk_encode_quoted_printable(string $text): string
{
    return quoted_printable_encode($text);
}

/**
 * Sanitizes a translated .ics filename for use in a MIME
 * Content-Disposition header: strips CR/LF (header injection) and
 * transliterates accented characters to their closest ASCII equivalent,
 * since the filename is embedded raw (no RFC 2231 encoding) and not every
 * mail client renders a non-ASCII attachment name correctly.
 */
function bk_sanitize_ics_filename(string $filename): string
{
    $filename = bk_sanitize_header($filename);
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
    if ($transliterated !== false && $transliterated !== '') {
        $filename = $transliterated;
    }
    $filename = preg_replace('/[^a-zA-Z0-9_.\-]/', '-', $filename);
    $filename = preg_replace('/-+/', '-', $filename);
    return $filename !== '' ? $filename : 'appointment.ics';
}

/**
 * Sends a plain-text email with a single .ics attachment via a manually
 * built multipart/mixed MIME body.
 */
function bk_send_mail_with_ics(
    string $toEmail,
    string $toName,
    string $subject,
    string $bodyText,
    string $icsContent,
    string $icsFilename
): bool {
    if (trim($toEmail) === '') {
        return false;
    }
    $mailer = bk_get_mailer();
    if ($mailer !== null) {
        try {
            $mailer->addAddress($toEmail, $toName);
            $mailer->Subject = $subject;
            $mailer->isHTML(false);
            $mailer->Body    = $bodyText;
            $mailer->addStringAttachment($icsContent, $icsFilename, 'base64', 'text/calendar; method=PUBLISH');

            return $mailer->send();
        } catch (PHPMailerException $e) {
            bk_log_mail_error('bk_send_mail_with_ics', $mailer->ErrorInfo);
            return false;
        }
    }

    // Fallback: native mail() with a hand-built MIME body, unchanged from
    // before SMTP support existed — used whenever SMTP isn't configured.
    $boundary = 'bk-' . bin2hex(random_bytes(12));
    $from     = bk_from_address();

    $headers  = "From: " . bk_sanitize_header($from['name']) . ' <' . bk_sanitize_header($from['email']) . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: SynaptikCMS/Booking\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= bk_encode_quoted_printable($bodyText) . "\r\n\r\n";

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/calendar; charset=UTF-8; method=PUBLISH; name=\"{$icsFilename}\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"{$icsFilename}\"\r\n\r\n";
    $body .= chunk_split(base64_encode($icsContent)) . "\r\n";

    $body .= "--{$boundary}--\r\n";

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $recipient      = bk_sanitize_header($toName) . ' <' . bk_sanitize_header($toEmail) . '>';

    return mail($recipient, $encodedSubject, $body, $headers);
}

/**
 * Records an SMTP send failure so it's actually visible somewhere —
 * PHPMailer's own exception would otherwise be caught and silently
 * reduced to a `false` return, exactly the failure mode bk_get_mailer()'s
 * docblock calls out as worse than mail()'s. Writes to PHP's configured
 * error_log rather than anywhere in data/ or private/, since this is
 * operational/debugging information (a misconfigured host, an expired
 * app password), not appointment data.
 */
function bk_log_mail_error(string $context, string $errorInfo): void
{
    error_log(sprintf('[Synaptik CMS Booking] SMTP send failed in %s: %s', $context, $errorInfo));
    // Also kept in-process so a same-request caller (admin/actions.php's
    // send_smtp_test) can surface the actual PHPMailer message to the
    // admin directly, instead of sending them to hunt through a server
    // error log they may not have access to or know how to find.
    $GLOBALS['_bk_last_mail_error'] = $errorInfo;
}

/**
 * Returns the ErrorInfo from the most recent failed SMTP send this
 * request, or '' if none. See bk_log_mail_error().
 */
function bk_last_mail_error(): string
{
    return $GLOBALS['_bk_last_mail_error'] ?? '';
}

/**
 * Sends a plain-text email without attachment (used for refusal/cancellation
 * notices where no calendar event needs updating).
 */
function bk_send_plain_mail(string $toEmail, string $toName, string $subject, string $bodyText): bool
{
    if (trim($toEmail) === '') {
        return false;
    }
    $mailer = bk_get_mailer();
    if ($mailer !== null) {
        try {
            $mailer->addAddress($toEmail, $toName);
            $mailer->Subject = $subject;
            $mailer->isHTML(false);
            $mailer->Body    = $bodyText;

            return $mailer->send();
        } catch (PHPMailerException $e) {
            bk_log_mail_error('bk_send_plain_mail', $mailer->ErrorInfo);
            return false;
        }
    }

    $from = bk_from_address();

    $headers  = "From: " . bk_sanitize_header($from['name']) . ' <' . bk_sanitize_header($from['email']) . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: SynaptikCMS/Booking\r\n";

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $recipient      = bk_sanitize_header($toName) . ' <' . bk_sanitize_header($toEmail) . '>';

    return mail($recipient, $encodedSubject, $bodyText, $headers);
}

/**
 * Converts a small subset of HTML to plain text for the fallback part of a
 * multipart email — not a full HTML-to-text converter, just enough so
 * clients without HTML rendering see something readable rather than raw
 * tags. Mirrors the Newsletter plugin's nl_html_to_plain_text().
 */
function bk_html_to_plain_text(string $html): string
{
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $text = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $text);
    $text = preg_replace('/<li[^>]*>/i', '• ', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/**
 * Wraps admin-authored HTML (from the WYSIWYG email template editor) in a
 * minimal email-safe document shell. Mirrors the Newsletter plugin's
 * nl_wrap_email_html() — inline styles only, single-column, since email
 * clients strip <style> blocks unpredictably.
 */
function bk_wrap_email_html(string $contentHtml): string
{
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
        . '<body style="margin:0;padding:0;background:#f4f4f5;">'
        . '<div style="max-width:600px;margin:0 auto;padding:24px 20px;background:#ffffff;font-family:-apple-system,Helvetica,Arial,sans-serif;color:#1e293b;line-height:1.6;">'
        . $contentHtml
        . '</div></body></html>';
}

/**
 * Appends a discreet, small-print cancellation link to an HTML email body —
 * used on the client's pending/confirmed notification emails so they can
 * cancel their own appointment without contacting the admin. Applied
 * uniformly to both the built-in default text and any admin-authored
 * custom template, so the link is never accidentally left out. Checks the
 * 'show_cancel_link_in_email' setting itself (rather than leaving it to each
 * call site) so the toggle can't be missed at one of the four call sites.
 */
function bk_append_cancel_link_html(string $bodyHtml, string $appointmentId): string
{
    if (empty(bk_load_settings()['show_cancel_link_in_email'])) {
        return $bodyHtml;
    }

    $link = htmlspecialchars(bk_build_client_cancel_link($appointmentId));
    $text = htmlspecialchars(bk_t('booking_cancel_link_text', 'Need to cancel? Click here.'));

    $footer = '<p style="margin-top:28px;font-size:11px;color:#94a3b8;">'
        . '<a href="' . $link . '" style="color:#94a3b8;">' . $text . '</a>'
        . '</p>';

    return $bodyHtml . $footer;
}

/**
 * Plain-text equivalent of bk_append_cancel_link_html(), for the default
 * (non-custom-template) bodies passed to bk_send_mail_with_ics(), which
 * builds its own plain-text-only MIME part rather than going through
 * bk_send_html_mail()'s HTML+plain-text pair.
 */
function bk_append_cancel_link_plain(string $bodyText, string $appointmentId): string
{
    $link = bk_build_client_cancel_link($appointmentId);
    $text = bk_t('booking_cancel_link_text', 'Need to cancel? Click here.');

    return $bodyText . "\n" . $text . "\n" . $link . "\n";
}

/**
 * Builds the .ics content attached to a client notification email
 * (pending/confirmed), using the admin's configured summary/description
 * templates (Settings tab) when set, falling back to the previous
 * hardcoded "{type}" summary / no description otherwise. Shared by
 * bk_notify_client_pending() and bk_notify_client_confirmed() so both
 * stay in sync.
 */
function bk_build_client_ics(array $appointment, ?array $type, array $settings): string
{
    $fromHost = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $summaryTemplate = trim($settings['ics_summary_template'] ?? '');
    $summary = $summaryTemplate !== ''
        ? bk_render_template($summaryTemplate, $appointment, $type)
        : bk_t('booking_ics_summary', 'Appointment') . ' — ' . ($type['label'] ?? '');

    $descriptionTemplate = trim($settings['ics_description_template'] ?? '');
    $description = $descriptionTemplate !== ''
        ? bk_render_template($descriptionTemplate, $appointment, $type)
        : '';

    $location = trim($settings['ics_location'] ?? '');
    $location = $location !== '' ? bk_render_template($location, $appointment, $type) : '';

    $url = trim($settings['ics_url'] ?? '');
    $url = $url !== '' ? bk_render_template($url, $appointment, $type) : '';

    return bk_build_ics(
        'bk-' . $appointment['id'] . '@' . $fromHost,
        $summary,
        $description,
        $appointment['date'] . ' ' . $appointment['start_time'],
        (int)($type['duration'] ?? 30),
        $settings['timezone'],
        $settings['admin_email'],
        $appointment['email'],
        $location,
        $url
    );
}

/**
 * Sends an HTML email (admin-authored template) with an auto-generated
 * plain-text fallback (multipart/alternative), optionally with an .ics
 * attachment (multipart/mixed wrapping the alternative part). Used whenever
 * a custom template is configured for the appointment's status — see
 * bk_notify_*() below, which fall back to bk_send_plain_mail()/
 * bk_send_mail_with_ics() when no template is set.
 */
function bk_send_html_mail(string $toEmail, string $toName, string $subject, string $bodyHtml, ?string $icsContent = null, ?string $icsFilename = null): bool
{
    if (trim($toEmail) === '') {
        return false;
    }
    $mailer = bk_get_mailer();
    if ($mailer !== null) {
        try {
            $mailer->addAddress($toEmail, $toName);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body    = bk_wrap_email_html($bodyHtml);
            $mailer->AltBody = bk_html_to_plain_text($bodyHtml);

            if ($icsContent !== null && $icsFilename !== null) {
                $mailer->addStringAttachment($icsContent, $icsFilename, 'base64', 'text/calendar; method=PUBLISH');
            }

            return $mailer->send();
        } catch (PHPMailerException $e) {
            bk_log_mail_error('bk_send_html_mail', $mailer->ErrorInfo);
            return false;
        }
    }

    // Fallback: native mail() with a hand-built MIME body, unchanged from
    // before SMTP support existed — used whenever SMTP isn't configured.
    $from        = bk_from_address();
    $altBoundary = 'bk-alt-' . bin2hex(random_bytes(12));
    $plain       = bk_html_to_plain_text($bodyHtml);

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $recipient      = bk_sanitize_header($toName) . ' <' . bk_sanitize_header($toEmail) . '>';

    if ($icsContent === null) {
        $headers  = "From: " . bk_sanitize_header($from['name']) . ' <' . bk_sanitize_header($from['email']) . ">\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n";
        $headers .= "X-Mailer: SynaptikCMS/Booking\r\n";

        $body  = "--{$altBoundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= bk_encode_quoted_printable($plain) . "\r\n\r\n";
        $body .= "--{$altBoundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= bk_encode_quoted_printable(bk_wrap_email_html($bodyHtml)) . "\r\n\r\n";
        $body .= "--{$altBoundary}--";

        return mail($recipient, $encodedSubject, $body, $headers);
    }

    // With an .ics attachment: multipart/mixed at the top level, wrapping
    // the multipart/alternative (text+html) part above as its first part.
    $mixedBoundary = 'bk-mix-' . bin2hex(random_bytes(12));

    $headers  = "From: " . bk_sanitize_header($from['name']) . ' <' . bk_sanitize_header($from['email']) . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$mixedBoundary}\"\r\n";
    $headers .= "X-Mailer: SynaptikCMS/Booking\r\n";

    $body  = "--{$mixedBoundary}\r\n";
    $body .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
    $body .= "--{$altBoundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= bk_encode_quoted_printable($plain) . "\r\n\r\n";
    $body .= "--{$altBoundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= bk_encode_quoted_printable(bk_wrap_email_html($bodyHtml)) . "\r\n\r\n";
    $body .= "--{$altBoundary}--\r\n\r\n";

    $body .= "--{$mixedBoundary}\r\n";
    $body .= "Content-Type: text/calendar; charset=UTF-8; method=PUBLISH; name=\"{$icsFilename}\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"{$icsFilename}\"\r\n\r\n";
    $body .= chunk_split(base64_encode($icsContent)) . "\r\n";
    $body .= "--{$mixedBoundary}--\r\n";

    return mail($recipient, $encodedSubject, $body, $headers);
}

// ─── High-level notification helpers ────────────────────────────────────────

/**
 * Sends the "request received" email to the client right after submission
 * (status: pending — the appointment is not yet confirmed).
 */
function bk_notify_client_pending(array $appointment): bool
{
    $settings = bk_load_settings();
    $type     = bk_get_appointment_type($appointment['type']);
    $toName   = trim($appointment['first_name'] . ' ' . $appointment['last_name']);

    $ics = bk_build_client_ics($appointment, $type, $settings);

    $customTemplate = trim($settings['email_templates']['pending'] ?? '');
    if ($customTemplate !== '') {
        $rendered = bk_render_email_template($customTemplate, $appointment, $type);
        $rendered = bk_append_cancel_link_html($rendered, $appointment['id']);
        return bk_send_html_mail(
            $appointment['email'],
            $toName,
            bk_t('booking_pending_subject', 'Your appointment request has been received'),
            $rendered,
            $ics,
            bk_sanitize_ics_filename(bk_t('booking_ics_filename', 'appointment.ics'))
        );
    }

    $dateFormatted = bk_format_date_i18n($appointment['date']);
    $intro = htmlspecialchars(bk_t('booking_pending_body_intro', 'We have received your appointment request for:'));
    $html  = '<p>' . $intro . '</p>';
    $html .= '<p><strong>' . htmlspecialchars($dateFormatted . ' ' . $appointment['start_time']) . '</strong><br>';
    $html .= htmlspecialchars($type['label'] ?? '') . '</p>';
    $html  = bk_append_cancel_link_html($html, $appointment['id']);

    return bk_send_html_mail(
        $appointment['email'],
        $toName,
        bk_t('booking_pending_subject', 'Your appointment request has been received'),
        $html,
        $ics,
        bk_sanitize_ics_filename(bk_t('booking_ics_filename', 'appointment.ics'))
    );
}

/**
 * Sends the admin notification when a new appointment request comes in,
 * including a separate .ics for the callback reminder if one was requested.
 */
function bk_notify_admin_new_request(array $appointment): bool
{
    $settings = bk_load_settings();
    if (empty($settings['admin_email'])) return false;

    $type       = bk_get_appointment_type($appointment['type']);
    $fromHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $clientName = trim($appointment['first_name'] . ' ' . $appointment['last_name']);

    $body  = bk_t('booking_admin_new_request_intro', 'New appointment request:') . "\n\n";
    $body .= bk_t('booking_admin_label_type', 'Type') . ":  " . ($type['label'] ?? $appointment['type']) . "\n";
    $body .= bk_t('booking_admin_label_date', 'Date') . ":  " . $appointment['date'] . ' ' . $appointment['start_time'] . "\n";
    $body .= bk_t('booking_admin_label_name', 'Name') . ":  " . $clientName . "\n";
    $body .= bk_t('booking_admin_label_email', 'Email') . ": " . $appointment['email'] . "\n";
    $body .= bk_t('booking_admin_label_phone', 'Phone') . ": " . $appointment['phone'] . "\n";
    if (!empty($appointment['ip'])) {
        // Admin-only — never included in any bk_notify_client_*() email.
        $body .= bk_t('booking_admin_label_ip', 'IP') . ":    " . $appointment['ip'] . "\n";
    }
    if (!empty($appointment['notes'])) {
        $body .= bk_t('booking_admin_label_notes', 'Notes') . ": " . $appointment['notes'] . "\n";
    }

    if (!empty($appointment['callback_requested'])) {
        $body .= "\n" . str_replace(
            ['{date}', '{time}'],
            [$appointment['callback_date'], $appointment['callback_time']],
            bk_t('booking_admin_callback_requested_for', 'Callback requested for: {date} {time}')
        ) . "\n";

        $callbackIcs = bk_build_ics(
            'bk-callback-' . $appointment['id'] . '@' . $fromHost,
            bk_t('booking_callback_ics_summary', 'Phone callback') . ' — ' . $clientName,
            'Phone: ' . $appointment['phone'],
            $appointment['callback_date'] . ' ' . $appointment['callback_time'],
            15,
            $settings['timezone'],
            $settings['admin_email']
        );

        // Two separate emails: one for the appointment record, one dedicated
        // to the callback so it drops into the calendar as its own event.
        bk_send_mail_with_ics(
            $settings['admin_email'],
            'Admin',
            str_replace('{name}', $clientName, bk_t('booking_admin_callback_subject', 'Callback reminder — {name}')),
            str_replace(
                ['{date}', '{time}', '{phone}'],
                [$appointment['callback_date'], $appointment['callback_time'], $appointment['phone']],
                bk_t('booking_admin_callback_body', "Callback requested for {date} {time}\nPhone: {phone}")
            ),
            $callbackIcs,
            bk_sanitize_ics_filename(bk_t('booking_callback_ics_filename', 'callback.ics'))
        );
    }

    $appointmentIcs = bk_build_ics(
        'bk-' . $appointment['id'] . '@' . $fromHost,
        bk_t('booking_ics_summary', 'Appointment') . ' — ' . $clientName,
        $body,
        $appointment['date'] . ' ' . $appointment['start_time'],
        (int)($type['duration'] ?? 30),
        $settings['timezone'],
        $settings['admin_email'],
        $appointment['email']
    );

    return bk_send_mail_with_ics(
        $settings['admin_email'],
        'Admin',
        str_replace('{name}', $clientName, bk_t('booking_admin_new_request_subject', 'New appointment request — {name}')),
        $body,
        $appointmentIcs,
        bk_sanitize_ics_filename(bk_t('booking_ics_filename', 'appointment.ics'))
    );
}

/**
 * Sends the confirmation email once the admin approves the appointment.
 */
function bk_notify_client_confirmed(array $appointment): bool
{
    $settings = bk_load_settings();
    $type     = bk_get_appointment_type($appointment['type']);
    $toName   = trim($appointment['first_name'] . ' ' . $appointment['last_name']);

    $ics = bk_build_client_ics($appointment, $type, $settings);

    $customTemplate = trim($settings['email_templates']['confirmed'] ?? '');
    if ($customTemplate !== '') {
        $rendered = bk_render_email_template($customTemplate, $appointment, $type);
        $rendered = bk_append_cancel_link_html($rendered, $appointment['id']);
        return bk_send_html_mail(
            $appointment['email'],
            $toName,
            bk_t('booking_confirmed_subject', 'Your appointment is confirmed'),
            $rendered,
            $ics,
            bk_sanitize_ics_filename(bk_t('booking_ics_filename', 'appointment.ics'))
        );
    }

    $dateFormatted = bk_format_date_i18n($appointment['date']);
    $intro = htmlspecialchars(bk_t('booking_confirmed_body_intro', 'Your appointment has been confirmed for:'));
    $html  = '<p>' . $intro . '</p>';
    $html .= '<p><strong>' . htmlspecialchars($dateFormatted . ' ' . $appointment['start_time']) . '</strong><br>';
    $html .= htmlspecialchars($type['label'] ?? '') . '</p>';
    $html  = bk_append_cancel_link_html($html, $appointment['id']);

    return bk_send_html_mail(
        $appointment['email'],
        $toName,
        bk_t('booking_confirmed_subject', 'Your appointment is confirmed'),
        $html,
        $ics,
        bk_sanitize_ics_filename(bk_t('booking_ics_filename', 'appointment.ics'))
    );
}

/**
 * Sends the refusal notice — no .ics, since no event should end up on the
 * client's calendar.
 */
function bk_notify_client_refused(array $appointment): bool
{
    $settings = bk_load_settings();
    $type     = bk_get_appointment_type($appointment['type']);
    $toName   = trim($appointment['first_name'] . ' ' . $appointment['last_name']);

    $customTemplate = trim($settings['email_templates']['refused'] ?? '');
    if ($customTemplate !== '') {
        return bk_send_html_mail(
            $appointment['email'],
            $toName,
            bk_t('booking_refused_subject', 'About your appointment request'),
            bk_render_email_template($customTemplate, $appointment, $type)
        );
    }

    return bk_send_plain_mail(
        $appointment['email'],
        $toName,
        bk_t('booking_refused_subject', 'About your appointment request'),
        bk_t('booking_refused_body', 'Unfortunately we are unable to confirm your appointment request.')
    );
}

/**
 * Sends the cancellation notice for a previously confirmed appointment.
 */
function bk_notify_client_cancelled(array $appointment): bool
{
    $settings = bk_load_settings();
    $type     = bk_get_appointment_type($appointment['type']);
    $toName   = trim($appointment['first_name'] . ' ' . $appointment['last_name']);

    $customTemplate = trim($settings['email_templates']['cancelled'] ?? '');
    if ($customTemplate !== '') {
        return bk_send_html_mail(
            $appointment['email'],
            $toName,
            bk_t('booking_cancelled_subject', 'Your appointment has been cancelled'),
            bk_render_email_template($customTemplate, $appointment, $type)
        );
    }

    $body = str_replace(
        ['{date}', '{time}'],
        [$appointment['date'], $appointment['start_time']],
        bk_t('booking_cancelled_body', 'Your appointment scheduled for {date} at {time} has been cancelled.')
    );

    return bk_send_plain_mail(
        $appointment['email'],
        $toName,
        bk_t('booking_cancelled_subject', 'Your appointment has been cancelled'),
        $body
    );
}

/**
 * Sends the admin notification when a client cancels their own appointment
 * via the one-click link in their notification email — distinct from
 * bk_notify_admin_new_request() (new pending request) so the admin can tell
 * at a glance which event triggered the email. No .ics: the event is being
 * removed, not scheduled.
 */
function bk_notify_admin_cancelled_by_client(array $appointment): bool
{
    $settings = bk_load_settings();
    if (empty($settings['admin_email'])) return false;

    $type       = bk_get_appointment_type($appointment['type']);
    $clientName = trim($appointment['first_name'] . ' ' . $appointment['last_name']);

    $body  = bk_t('booking_admin_cancelled_intro', 'An appointment was cancelled by the client:') . "\n\n";
    $body .= bk_t('booking_admin_label_type', 'Type') . ":  " . ($type['label'] ?? $appointment['type']) . "\n";
    $body .= bk_t('booking_admin_label_date', 'Date') . ":  " . $appointment['date'] . ' ' . $appointment['start_time'] . "\n";
    $body .= bk_t('booking_admin_label_name', 'Name') . ":  " . $clientName . "\n";
    $body .= bk_t('booking_admin_label_email', 'Email') . ": " . $appointment['email'] . "\n";
    $body .= bk_t('booking_admin_label_phone', 'Phone') . ": " . $appointment['phone'] . "\n";

    return bk_send_plain_mail(
        $settings['admin_email'],
        'Admin',
        str_replace('{name}', $clientName, bk_t('booking_admin_cancelled_subject', 'Appointment cancelled by client — {name}')),
        $body
    );
}
