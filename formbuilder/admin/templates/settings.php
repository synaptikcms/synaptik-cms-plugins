<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Settings view.
 * Sender email/name used on outgoing notification emails. Per-form
 * recipient override lives on the form itself (see edit.php); this is only
 * the fallback sender identity, mirroring the Booking plugin's settings.
 */

$settings = fb_load_settings();
$csrf     = fb_generate_csrf();
$msg      = $_GET['msg'] ?? '';
?>
<?php if ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(fb_t('fb_settings_saved', 'Settings saved.', 'admin')); ?></div>
<?php endif; ?>

<form method="post" action="<?php echo hsc(fb_admin_actions_url()); ?>" class="fb-settings-form">
    <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
    <input type="hidden" name="return_url" value="<?php echo hsc(fb_admin_page_url('settings')); ?>">
    <input type="hidden" name="action" value="save_settings">

    <section class="fb-settings-section">
        <h2><?php echo hsc(fb_t('fb_settings_sender_title', 'Sender identity', 'admin')); ?></h2>

        <div class="fb-field">
            <label for="fb-from-email"><?php echo hsc(fb_t('fb_settings_from_email', 'Sender email address', 'admin')); ?></label>
            <input type="email" id="fb-from-email" name="from_email" value="<?php echo hsc($settings['from_email']); ?>" placeholder="noreply@<?php echo hsc($_SERVER['HTTP_HOST'] ?? ''); ?>">
            <p class="fb-field-help"><?php echo hsc(fb_t('fb_settings_from_email_help', 'Leave empty to use noreply@ followed by your domain name.', 'admin')); ?></p>
        </div>

        <div class="fb-field">
            <label for="fb-from-name"><?php echo hsc(fb_t('fb_settings_from_name', 'Sender name', 'admin')); ?></label>
            <input type="text" id="fb-from-name" name="from_name" value="<?php echo hsc($settings['from_name']); ?>" placeholder="<?php echo hsc(fb_t('fb_settings_from_name_placeholder', 'Your site title', 'admin')); ?>">
        </div>
    </section>

    <div class="fb-form-actions">
        <button type="submit" class="fb-btn fb-btn--primary"><?php echo hsc(fb_t('fb_settings_save', 'Save settings', 'admin')); ?></button>
    </div>
</form>
