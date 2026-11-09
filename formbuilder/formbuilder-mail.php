<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Form Builder Plugin — Mail
 *
 * Sends the submission notification email with the generated PDF recap
 * attached. Uses PHP's native mail() with a hand-built MIME multipart
 * body — no external mail library, same approach as contact-process.php
 * and the Booking plugin.
 */

if (defined('FB_MAIL_LOADED')) return;
define('FB_MAIL_LOADED', true);

/**
 * Sanitizes a translated PDF filename for a MIME Content-Disposition
 * header: strips CR/LF and transliterates accented characters, since the
 * filename is embedded raw (no RFC 2231 encoding).
 */
function fb_sanitize_pdf_filename(string $filename): string
{
    $filename = fb_sanitize_header($filename);
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
    if ($transliterated !== false && $transliterated !== '') {
        $filename = $transliterated;
    }
    $filename = preg_replace('/[^a-zA-Z0-9_.\-]/', '-', $filename);
    $filename = preg_replace('/-+/', '-', $filename);
    return $filename !== '' ? $filename : 'submission.pdf';
}

/**
 * Sends a plain-text email with a single PDF attachment via a manually
 * built multipart/mixed MIME body.
 */
function fb_send_mail_with_pdf(
    string $toEmail,
    string $toName,
    string $subject,
    string $bodyText,
    string $pdfContent,
    string $pdfFilename
): bool {
    $boundary = 'fb-' . bin2hex(random_bytes(12));
    $from     = fb_from_address();

    $headers  = "From: " . fb_sanitize_header($from['name']) . ' <' . fb_sanitize_header($from['email']) . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: SynaptikCMS/FormBuilder\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $bodyText . "\r\n\r\n";

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: application/pdf; name=\"{$pdfFilename}\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"{$pdfFilename}\"\r\n\r\n";
    $body .= chunk_split(base64_encode($pdfContent)) . "\r\n";

    $body .= "--{$boundary}--\r\n";

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $recipient      = fb_sanitize_header($toName) . ' <' . fb_sanitize_header($toEmail) . '>';

    return mail($recipient, $encodedSubject, $body, $headers);
}

/**
 * Sends the submission notification to the form's recipient (or the site's
 * default contact address) with the PDF recap attached. No-op (returns
 * false without attempting anything) when the form's notify_by_email flag
 * is off — the submission itself is always saved regardless, this only
 * gates the email step.
 */
function fb_notify_submission(array $form, array $values, string $pdfContent, string $submittedAt): bool
{
    if (isset($form['notify_by_email']) && !$form['notify_by_email']) {
        return false;
    }

    $toEmail = trim($form['recipient_email'] ?? '');

    if ($toEmail === '') {
        $coreConfig = fb_core_config();
        $toEmail = trim($coreConfig['contact_email'] ?? '');
    }

    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $formName = $form['name'] !== '' ? $form['name'] : fb_t('fb_default_form_name', 'Form', 'admin');

    $subject = str_replace(
        '{form_name}',
        fb_sanitize_header($formName),
        fb_t('fb_notification_subject', 'New submission — {form_name}', 'admin')
    );

    $body  = str_replace('{form_name}', $formName, fb_t('fb_notification_body_intro', 'A new submission was received for "{form_name}". The full recap is attached as a PDF.', 'admin')) . "\n\n";
    $body .= fb_t('fb_notification_body_date', 'Date', 'admin') . ": " . $submittedAt . "\n";

    $filename = fb_sanitize_pdf_filename($formName . '.pdf');

    return fb_send_mail_with_pdf($toEmail, $formName, $subject, $body, $pdfContent, $filename);
}
