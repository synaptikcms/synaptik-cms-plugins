<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Newsletter composer.
 *
 * Reuses the CMS's own WYSIWYG editor (editor-common.js + editor.js — see
 * admin-page.php for how those get loaded on this view). The editor's
 * contract, inferred from editor.js: it looks for a <textarea id="content">
 * inside a <form>, replaces it with the rich-text toolbar + contenteditable
 * area, and mirrors content into a hidden #content-hidden input with
 * name="content" — added automatically, do not declare it manually. On
 * submit, editor.js syncs the textarea/hidden input from the live editor
 * content before the browser's native form submission proceeds.
 *
 * No <form action="..."> submission actually happens here — sending is
 * AJAX-driven by newsletter-admin.js (send_custom_batch), which reads the
 * synced #content value directly rather than doing a real page submit.
 */

$csrf     = nl_generate_csrf();
$settings = nl_load_settings();
$confirmedCount = count(nl_load_confirmed_subscribers());
?>
<div class="nl-panel">
    <div class="nl-field">
        <label for="nl-subject"><?php echo hsc(nl_t('nl_compose_subject', 'Subject line', 'admin')); ?></label>
        <input type="text" id="nl-subject" name="subject" maxlength="200" placeholder="<?php echo hsc(nl_t('nl_compose_subject_placeholder', 'What is this newsletter about?', 'admin')); ?>">
    </div>
</div>

<div class="nl-panel">
    <p class="nl-panel-hint"><?php echo hsc(nl_t('nl_compose_tokens_hint', 'Click a variable to insert it at the cursor. It is replaced for each recipient when sent.', 'admin')); ?></p>
    <div class="nl-token-bar" id="nl-digest-token-bar">
        <button type="button" class="nl-token-btn" data-token="{name}">{name}</button>
        <button type="button" class="nl-token-btn" data-token="{email}">{email}</button>
        <button type="button" class="nl-token-btn" data-token="{site_name}">{site_name}</button>
    </div>
</div>

<div class="nl-panel nl-panel--editor">
    <!-- Reproduces the minimal markup editor.js expects: a <form> wrapping
         a <textarea id="content">. No actual server-side submit target is
         used — see the class doc comment above. -->
    <form id="content-form">
        <textarea id="content" name="content" rows="20"></textarea>
    </form>
</div>

<div class="nl-panel">
    <p class="nl-panel-hint">
        <?php echo hsc(sprintf(
            nl_t('nl_compose_recipient_hint', 'This will be sent to %d confirmed subscriber(s).', 'admin'),
            $confirmedCount
        )); ?>
    </p>

    <button type="button" id="nl-send-custom" class="nl-btn nl-btn--primary" data-batch-action="send_custom_batch" data-endpoint="<?php echo hsc(nl_admin_actions_url()); ?>" data-csrf="<?php echo hsc($csrf); ?>" <?php echo $confirmedCount === 0 ? 'disabled' : ''; ?>>
        <?php echo hsc(nl_t('nl_compose_send_button', 'Send newsletter', 'admin')); ?>
    </button>

    <div id="nl-digest-progress" class="nl-digest-progress" hidden>
        <div class="nl-progress-bar"><div class="nl-progress-fill"></div></div>
        <p class="nl-progress-label"></p>
    </div>
    <div id="nl-digest-result" class="nl-digest-result" hidden></div>
</div>

<?php $nlComposeTokenV = @filemtime(__DIR__ . '/../../assets/js/digest-token-bar.js') ?: time(); ?>
<script src="<?php echo hsc(nl_plugin_base_url() . 'assets/js/digest-token-bar.js'); ?>?v=<?php echo $nlComposeTokenV; ?>"></script>

<script type="application/json" data-window-var="NL_ADMIN_LANG"><?php echo json_encode([
    'sending'          => nl_t('nl_digest_sending', 'Sending…', 'admin'),
    'sent_progress'    => nl_t('nl_digest_sent_progress', 'Sent {sent} of {total}', 'admin'),
    'done'             => nl_t('nl_digest_done', 'Sent to {total} subscriber(s).', 'admin'),
    'no_new_articles'  => nl_t('nl_digest_no_new_articles', 'No new articles since the last digest.', 'admin'),
    'no_subscribers'   => nl_t('nl_no_confirmed_subscribers', 'No confirmed subscribers to send to.', 'admin'),
    'empty_composer'   => nl_t('nl_empty_composer', 'Write a subject and some content first.', 'admin'),
    'error'            => nl_t('nl_digest_error', 'Something went wrong while sending. Please try again.', 'admin'),
    'confirm_send'     => nl_t('nl_confirm_send', 'Send this newsletter now? This cannot be undone.', 'admin'),
], JSON_UNESCAPED_UNICODE); ?></script>
<script src="<?php echo hsc(nl_core_admin_asset_url('assets/js/admin-boot.js')); ?>"></script>
