<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Digest template view.
 *
 * Custom HTML wrapper for the article digest email, authored with the
 * CMS's own WYSIWYG editor (see admin-page.php for how editor-common.js/
 * editor.js get loaded for this view). Must contain {articles} somewhere
 * — that's where the article cards (image, title, summary, read-more
 * link) get inserted at send time. See nl_render_digest_template() in
 * newsletter-mail.php.
 *
 * An empty template (the default) falls back to a minimal built-in
 * wrapper (greeting, articles, sign-off) — see the same function.
 */

$settings = nl_load_settings();
$csrf     = nl_generate_csrf();
$msg      = $_GET['msg'] ?? '';

$currentTemplate = $settings['digest_template'] ?? '';
?>
<?php if ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(nl_t('nl_digest_template_saved', 'Digest template saved.', 'admin')); ?></div>
<?php endif; ?>

<div class="nl-panel">
    <p class="nl-panel-hint"><strong><?php echo hsc(nl_t('nl_digest_template_summary', 'This is the layout of the automatic digest email (the one sent by "Send digest now" on the Overview tab): your greeting and sign-off, around the list of new articles. It does not affect newsletters written in Compose.', 'admin')); ?></strong></p>
    <p class="nl-panel-hint"><?php echo hsc(nl_t(
        'nl_digest_template_intro',
        'Customize the email sent when you click "Send digest now". Leave empty to use the built-in default (a short greeting, the articles, and a sign-off). Must include the {articles} placeholder — that\'s where each new article\'s image, title, and summary are inserted.',
        'admin'
    )); ?></p>

    <div class="nl-token-bar" id="nl-digest-token-bar">
        <button type="button" class="nl-token-btn" data-token="{articles}">{articles}</button>
        <button type="button" class="nl-token-btn" data-token="{site_name}">{site_name}</button>
        <button type="button" class="nl-token-btn" data-token="{name}">{name}</button>
        <button type="button" class="nl-token-btn" data-token="{email}">{email}</button>
    </div>
    <p class="nl-panel-hint">
        <?php echo hsc(nl_t(
            'nl_digest_template_email_note',
            '{name} is the subscriber\'s name (collected by the signup form when enabled in Settings, otherwise the fallback text). {email} is their address.',
            'admin'
        )); ?>
    </p>
</div>

<div class="nl-panel nl-panel--editor">
    <!-- Reproduces the minimal markup editor.js expects: a <form> wrapping
         a <textarea id="content">. Submitted directly to actions.php as a
         real page POST (no AJAX) — editor.js syncs the hidden #content-hidden
         input from the live WYSIWYG content on submit. Mirrors the Booking
         plugin's emails.php. -->
    <form method="post" action="<?php echo hsc(nl_admin_actions_url()); ?>" id="content-form">
        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
        <input type="hidden" name="return_url" value="<?php echo hsc(nl_admin_page_url(['view' => 'digest_template'])); ?>">
        <input type="hidden" name="action" value="save_digest_template">

        <textarea id="content" name="digest_template" rows="16"><?php echo hsc($currentTemplate); ?></textarea>

        <div class="nl-form-actions">
            <button type="submit" class="nl-btn nl-btn--primary"><?php echo hsc(nl_t('nl_digest_template_save', 'Save template', 'admin')); ?></button>
        </div>
    </form>
</div>

<?php $nlDigestTokenBarV = @filemtime(__DIR__ . '/../../assets/js/digest-token-bar.js') ?: time(); ?>
<script src="<?php echo hsc(nl_plugin_base_url() . 'assets/js/digest-token-bar.js'); ?>?v=<?php echo $nlDigestTokenBarV; ?>"></script>
