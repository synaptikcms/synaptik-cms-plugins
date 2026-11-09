<?php
/**
 * Form Builder Plugin — PDF Re-download Endpoint
 *
 * Regenerates a submission's PDF recap on demand (the PDF itself is never
 * stored on disk — only the raw submitted values are, see
 * formbuilder-data-layer.php). Requires the core admin session and a valid
 * CSRF token passed as a query parameter, since this is a plain GET link
 * rather than a form submission.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/formbuilder-pdf.php';

fb_admin_require_login();

if (!fb_verify_csrf(trim($_GET['csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$formId = trim($_GET['form'] ?? '');
$file   = trim($_GET['file'] ?? '');

$submission = fb_load_submission($formId, $file);
$form       = fb_load_form($formId);

if ($submission === null || $form === null) {
    http_response_code(404);
    exit('Submission not found.');
}

$checkedLabel   = fb_t('fb_checkbox_yes', 'Yes', 'admin');
$uncheckedLabel = fb_t('fb_checkbox_no', 'No', 'admin');

$pdfContent = fb_build_submission_pdf($form, $submission['values'], $submission['submitted_at'], $checkedLabel, $uncheckedLabel);
$filename   = fb_sanitize_pdf_filename_for_download(($form['name'] !== '' ? $form['name'] : 'submission') . '-' . $formId);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfContent));
header('Cache-Control: no-store');
echo $pdfContent;
exit;

/**
 * Local filename sanitizer (distinct from fb_sanitize_pdf_filename() in
 * formbuilder-mail.php, which is CR/LF-safe for a MIME header — this one
 * only needs to be filesystem/HTTP-header safe for a Content-Disposition
 * value built directly here).
 */
function fb_sanitize_pdf_filename_for_download(string $name): string
{
    $name = preg_replace('/[^a-zA-Z0-9_.\-]/', '-', $name);
    $name = preg_replace('/-+/', '-', $name);
    return ($name !== '' ? trim($name, '-') : 'submission') . '.pdf';
}
