<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Email templates view.
 *
 * One custom HTML body per appointment status (pending / confirmed /
 * refused / cancelled), authored with the CMS's own WYSIWYG editor (see
 * admin-page.php for how editor-common.js/editor.js get loaded for this
 * view only). Only one status is edited per page load — the editor
 * contract (editor.js) supports a single #content textarea per page, so
 * switching status reloads the page with ?email_type=... rather than
 * trying to host four editor instances at once.
 *
 * An empty template (the default) means the built-in i18n text is used
 * instead — see bk_notify_client_*() in booking-mail.php.
 */

$settings = bk_load_settings();
$csrf     = bk_generate_csrf();
$msg      = $_GET['msg'] ?? '';

$sendPendingEmail = !empty($settings['send_pending_email']);

$validTypes = ['confirmed', 'refused', 'cancelled'];
if ($sendPendingEmail) {
    array_unshift($validTypes, 'pending');
}
$emailType = $_GET['email_type'] ?? ($sendPendingEmail ? 'pending' : 'confirmed');
if (!in_array($emailType, $validTypes, true)) {
    $emailType = $sendPendingEmail ? 'pending' : 'confirmed';
}

$typeLabels = [];
if ($sendPendingEmail) {
    $typeLabels['pending'] = bk_t('bk_email_type_pending', 'Request received', 'admin');
}
$typeLabels['confirmed'] = bk_t('bk_email_type_confirmed', 'Appointment confirmed', 'admin');
$typeLabels['refused']   = bk_t('bk_email_type_refused', 'Request refused', 'admin');
$typeLabels['cancelled'] = bk_t('bk_email_type_cancelled', 'Appointment cancelled', 'admin');

$currentTemplate = $settings['email_templates'][$emailType] ?? '';
?>
<?php if ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(bk_t('bk_emails_saved', 'Email template saved.', 'admin')); ?></div>
<?php endif; ?>

<p class="bk-field-help"><?php echo hsc(bk_t('bk_emails_intro', 'Write a custom email for each appointment status. Leave a template empty to use the default text instead. Available placeholders: {first_name}, {last_name}, {date}, {time}, {type}.', 'admin')); ?></p>

<div class="bk-filters">
    <?php foreach ($typeLabels as $key => $label): ?>
    <a href="<?php echo hsc(bk_admin_page_url('emails', ['email_type' => $key])); ?>" class="<?php echo $emailType === $key ? 'active' : ''; ?>"><?php echo hsc($label); ?></a>
    <?php endforeach; ?>
</div>

<div class="bk-panel bk-panel--editor">
    <!-- Reproduces the minimal markup editor.js expects: a <form> wrapping
         a <textarea id="content">. Submitted directly to actions.php as a
         real page POST (no AJAX) — editor.js syncs the hidden #content-hidden
         input from the live WYSIWYG content on submit. -->
    <form method="post" action="<?php echo hsc(bk_admin_actions_url()); ?>" id="content-form">
        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
        <input type="hidden" name="return_url" value="<?php echo hsc(bk_admin_page_url('emails', ['email_type' => $emailType])); ?>">
        <input type="hidden" name="action" value="save_email_template">
        <input type="hidden" name="email_type" value="<?php echo hsc($emailType); ?>">

        <textarea id="content" name="content" rows="20"><?php echo hsc($currentTemplate); ?></textarea>

        <div class="bk-form-actions">
            <button type="submit" class="bk-btn bk-btn--primary"><?php echo hsc(bk_t('bk_emails_save', 'Save template', 'admin')); ?></button>
        </div>
    </form>
</div>
