<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Form editor view.
 * Name, recipient email override, success message, and a dynamic list of
 * fields (label, key, type, required, dropdown options). Field rows are
 * added/removed/reordered client-side by formbuilder-editor.js; this
 * template only renders the initial state from the loaded form (or a
 * single empty row for a new form).
 */

$editId = trim($_GET['id'] ?? '');
$form   = $editId !== '' ? fb_load_form($editId) : null;
$isNew  = $form === null;

if (!$isNew && $editId !== '' && $form === null) {
    // Stale/invalid id in the URL — fall back to a blank new-form state
    // rather than erroring, same forgiving behaviour as the rest of the admin.
    $isNew = true;
}

$form = $form ?? [
    'id'              => '',
    'name'            => '',
    'recipient_email' => '',
    'success_message' => '',
    'notify_by_email' => true,
    'fields'          => [fb_default_field()],
];

$csrf = fb_generate_csrf();
?>
<form method="post" action="<?php echo hsc(fb_admin_actions_url()); ?>" class="fb-form-editor" id="fb-editor-form">
    <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
    <input type="hidden" name="return_url" value="<?php echo hsc(fb_admin_page_url('forms')); ?>">
    <input type="hidden" name="action" value="save_form">
    <input type="hidden" name="id" value="<?php echo hsc($form['id']); ?>">

    <section class="fb-settings-section">
        <div class="fb-field">
            <label for="fb-name"><?php echo hsc(fb_t('fb_form_name', 'Form name', 'admin')); ?></label>
            <input type="text" id="fb-name" name="name" value="<?php echo hsc($form['name']); ?>" required>
        </div>

        <div class="fb-field fb-field--checkbox">
            <label>
                <input type="checkbox" id="fb-notify-by-email" <?php echo (!isset($form['notify_by_email']) || !empty($form['notify_by_email'])) ? 'checked' : ''; ?> data-fb-notify-checkbox>
                <?php echo hsc(fb_t('fb_notify_by_email', 'Receive submissions by email', 'admin')); ?>
            </label>
            <input type="hidden" name="notify_by_email" value="<?php echo (!isset($form['notify_by_email']) || !empty($form['notify_by_email'])) ? '1' : '0'; ?>" data-fb-notify-flag>
            <p class="fb-field-help"><?php echo hsc(fb_t('fb_notify_by_email_help', 'Submissions are always saved and visible under Submissions. When unchecked, no notification email is sent.', 'admin')); ?></p>
        </div>

        <div class="fb-field">
            <label for="fb-recipient"><?php echo hsc(fb_t('fb_recipient_email', 'Recipient email', 'admin')); ?></label>
            <input type="email" id="fb-recipient" name="recipient_email" value="<?php echo hsc($form['recipient_email']); ?>" placeholder="<?php echo hsc(fb_t('fb_recipient_email_placeholder', 'Leave empty to use the site contact address', 'admin')); ?>">
            <p class="fb-field-help"><?php echo hsc(fb_t('fb_recipient_email_help', 'The PDF recap of each submission is sent to this address. Leave empty to use the site\'s default contact email.', 'admin')); ?></p>
        </div>

        <div class="fb-field">
            <label for="fb-success"><?php echo hsc(fb_t('fb_success_message', 'Success message', 'admin')); ?></label>
            <input type="text" id="fb-success" name="success_message" value="<?php echo hsc($form['success_message']); ?>" placeholder="<?php echo hsc(fb_t('fb_success_message_placeholder', 'Thank you, your submission has been received.', 'admin')); ?>">
        </div>
    </section>

    <section class="fb-settings-section">
        <h2><?php echo hsc(fb_t('fb_fields_title', 'Fields', 'admin')); ?></h2>

        <div class="fb-fields-header">
            <span></span>
            <span><?php echo hsc(fb_t('fb_field_label', 'Label', 'admin')); ?></span>
            <span><?php echo hsc(fb_t('fb_field_key', 'Key', 'admin')); ?></span>
            <span><?php echo hsc(fb_t('fb_field_type', 'Type', 'admin')); ?></span>
            <span><?php echo hsc(fb_t('fb_field_width', 'Width', 'admin')); ?></span>
            <span><?php echo hsc(fb_t('fb_field_required', 'Required', 'admin')); ?></span>
            <span></span>
        </div>

        <div id="fb-fields-list">
            <?php foreach ($form['fields'] as $i => $field): ?>
            <?php include __DIR__ . '/partials/field-row.php'; ?>
            <?php endforeach; ?>
        </div>

        <button type="button" id="fb-add-field" class="fb-btn"><?php echo hsc(fb_t('fb_add_field', 'Add field', 'admin')); ?></button>
    </section>

    <div class="fb-form-actions">
        <button type="submit" class="fb-btn fb-btn--primary"><?php echo hsc(fb_t('fb_save_form', 'Save form', 'admin')); ?></button>
        <a href="<?php echo hsc(fb_admin_page_url('forms')); ?>" class="fb-btn"><?php echo hsc(fb_t('fb_cancel', 'Cancel', 'admin')); ?></a>
    </div>
</form>

<!-- Row template used by formbuilder-editor.js to append a new field row
     client-side, identical markup to partials/field-row.php with an
     empty/default field so both stay visually in sync. -->
<template id="fb-field-row-template">
    <?php $i = '__INDEX__'; $field = fb_default_field(); ?>
    <?php include __DIR__ . '/partials/field-row.php'; ?>
</template>

<script type="application/json" data-window-var="FB_EDITOR_STRINGS"><?php echo json_encode([
    'optionsPlaceholder' => fb_t('fb_options_placeholder', 'One option per line', 'admin'),
    'remove'             => fb_t('fb_remove_field', 'Remove', 'admin'),
]); ?></script>
<script src="assets/js/admin-boot.js"></script>
