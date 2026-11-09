<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — SMTP view.
 *
 * Configures the SMTP transport used by PHPMailer (see bk_get_mailer() in
 * booking-mail.php). When disabled (the default), every notification email
 * keeps going out through PHP's native mail() exactly as before — nothing
 * here changes behaviour until this is explicitly turned on and saved.
 *
 * The password field is intentionally never pre-filled with the stored
 * value (see bk_load_smtp_password() in booking-functions.php) — only a
 * placeholder indicates one is already saved. Leaving the field empty on
 * save keeps the existing password; this avoids ever echoing a secret back
 * into an HTML attribute, and avoids the password appearing in this page's
 * own HTML source.
 */

$settings = bk_load_settings();
$smtp     = $settings['smtp'];
$hasPassword = bk_load_smtp_password() !== '';
$csrf = bk_generate_csrf();
$msg = $_GET['msg'] ?? '';
?>
<?php if ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(bk_t('bk_smtp_saved', 'SMTP settings saved.', 'admin')); ?></div>
<?php elseif ($msg === 'brevo_applied'): ?>
    <div class="message success"><?php echo hsc(bk_t('bk_smtp_brevo_applied', 'Brevo server settings applied. Enter your SMTP login and key, then save.', 'admin')); ?></div>
<?php elseif ($msg === 'test_sent'): ?>
    <div class="message success"><?php echo hsc(bk_t('bk_smtp_test_sent', 'Test email sent successfully.', 'admin')); ?></div>
<?php elseif ($msg === 'test_failed'): ?>
    <div class="message error">
        <?php echo hsc(bk_t('bk_smtp_test_failed', 'The test email could not be sent.', 'admin')); ?>
        <?php if (!empty($_SESSION['bk_smtp_test_error'])): ?>
            <br><code><?php echo hsc($_SESSION['bk_smtp_test_error']); unset($_SESSION['bk_smtp_test_error']); ?></code>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p class="bk-field-help">
    <?php echo hsc(bk_t(
        'bk_smtp_intro',
        "By default, this plugin sends emails through your hosting provider's built-in mail server. That works, but delivery quality (spam placement, bounce visibility) varies a lot between hosts. Connecting a dedicated SMTP provider (Brevo, Mailjet, Amazon SES, or your email provider's own SMTP) generally improves this. Leave disabled if you're not sure — the plugin will keep working exactly as before.",
        'admin'
    )); ?>
</p>

<section class="bk-settings-section">
    <h2><?php echo hsc(bk_t('bk_smtp_brevo_title', 'Brevo (free plan available)', 'admin')); ?></h2>
    <p class="bk-field-help"><?php echo hsc(bk_t('bk_smtp_brevo_hint', 'Fills in the Brevo server and turns SMTP on.', 'admin')); ?></p>
    <form method="post" action="<?php echo hsc(bk_admin_actions_url()); ?>" class="bk-settings-form">
        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
        <input type="hidden" name="return_url" value="<?php echo hsc(bk_admin_page_url('smtp')); ?>">
        <input type="hidden" name="action" value="smtp_preset_brevo">
        <div class="bk-form-actions">
            <button type="submit" class="bk-btn"><?php echo hsc(bk_t('bk_smtp_brevo_apply', 'Apply Brevo settings', 'admin')); ?></button>
        </div>
    </form>
</section>

<form method="post" action="<?php echo hsc(bk_admin_actions_url()); ?>" class="bk-settings-form">
    <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
    <input type="hidden" name="return_url" value="<?php echo hsc(bk_admin_page_url('smtp')); ?>">
    <input type="hidden" name="action" value="save_smtp_settings">

    <section class="bk-settings-section">
    <h2><?php echo hsc(bk_t('bk_smtp_section_title', 'SMTP transport', 'admin')); ?></h2>

    <div class="bk-field bk-field--checkbox">
        <label>
            <input type="checkbox" id="bk-smtp-enabled" name="smtp_enabled" <?php echo !empty($smtp['enabled']) ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_smtp_enabled', 'Send emails via SMTP instead of the server\'s default mail function', 'admin')); ?>
        </label>
    </div>

    <div class="bk-field">
        <label for="bk-smtp-host"><?php echo hsc(bk_t('bk_smtp_host', 'SMTP host', 'admin')); ?></label>
        <input type="text" id="bk-smtp-host" name="smtp_host" value="<?php echo hsc($smtp['host']); ?>" placeholder="smtp-relay.brevo.com">
    </div>

    <div class="bk-field">
        <label for="bk-smtp-port"><?php echo hsc(bk_t('bk_smtp_port', 'Port', 'admin')); ?></label>
        <input type="number" id="bk-smtp-port" name="smtp_port" min="1" max="65535" value="<?php echo (int)$smtp['port']; ?>">
        <p class="bk-field-help"><?php echo hsc(bk_t('bk_smtp_port_help', 'Common values: 587 (STARTTLS, recommended), 465 (SSL), 25 (unencrypted, not recommended).', 'admin')); ?></p>
    </div>

    <div class="bk-field">
        <label><?php echo hsc(bk_t('bk_smtp_encryption', 'Encryption', 'admin')); ?></label>
        <label class="bk-radio-option">
            <input type="radio" name="smtp_encryption" value="tls" <?php echo ($smtp['encryption'] ?? 'tls') === 'tls' ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_smtp_encryption_tls', 'STARTTLS (recommended, usually port 587)', 'admin')); ?>
        </label>
        <label class="bk-radio-option">
            <input type="radio" name="smtp_encryption" value="ssl" <?php echo ($smtp['encryption'] ?? 'tls') === 'ssl' ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_smtp_encryption_ssl', 'SSL/TLS (usually port 465)', 'admin')); ?>
        </label>
        <label class="bk-radio-option">
            <input type="radio" name="smtp_encryption" value="none" <?php echo ($smtp['encryption'] ?? 'tls') === 'none' ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_smtp_encryption_none', 'None (not recommended)', 'admin')); ?>
        </label>
    </div>

    <div class="bk-field bk-field--checkbox">
        <label>
            <input type="checkbox" id="bk-smtp-auth" name="smtp_auth" <?php echo !empty($smtp['auth']) ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_smtp_auth', 'This server requires a username and password', 'admin')); ?>
        </label>
    </div>

    <div class="bk-field">
        <label for="bk-smtp-username"><?php echo hsc(bk_t('bk_smtp_username', 'Username', 'admin')); ?></label>
        <input type="text" id="bk-smtp-username" name="smtp_username" value="<?php echo hsc($smtp['username']); ?>" autocomplete="off">
    </div>

    <div class="bk-field">
        <label for="bk-smtp-password"><?php echo hsc(bk_t('bk_smtp_password', 'Password', 'admin')); ?></label>
        <input type="password" id="bk-smtp-password" name="smtp_password" value="" autocomplete="new-password" placeholder="<?php echo $hasPassword ? hsc(bk_t('bk_smtp_password_saved_placeholder', '•••••••• (saved — leave empty to keep it)', 'admin')) : ''; ?>">
        <p class="bk-field-help"><?php echo hsc(bk_t('bk_smtp_password_help', 'Leave empty when saving to keep the currently stored password unchanged. Type a new one to replace it.', 'admin')); ?></p>
    </div>
    </section>

    <div class="bk-form-actions">
        <button type="submit" class="bk-btn bk-btn--primary"><?php echo hsc(bk_t('bk_smtp_save', 'Save SMTP settings', 'admin')); ?></button>
    </div>
</form>

<section class="bk-settings-section">
    <h2><?php echo hsc(bk_t('bk_smtp_test_title', 'Test this configuration', 'admin')); ?></h2>
    <p class="bk-field-help"><?php echo hsc(bk_t('bk_smtp_test_help', 'Sends a short test email using the settings currently saved above (save your changes first). Uses SMTP if enabled, otherwise the server\'s default mail function — useful to confirm either path is actually working.', 'admin')); ?></p>

    <form method="post" action="<?php echo hsc(bk_admin_actions_url()); ?>" class="bk-settings-form">
        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
        <input type="hidden" name="return_url" value="<?php echo hsc(bk_admin_page_url('smtp')); ?>">
        <input type="hidden" name="action" value="send_smtp_test">

        <div class="bk-field">
            <label for="bk-smtp-test-email"><?php echo hsc(bk_t('bk_smtp_test_email', 'Send test to', 'admin')); ?></label>
            <input type="email" id="bk-smtp-test-email" name="test_email" value="<?php echo hsc($settings['admin_email']); ?>" required>
        </div>

        <div class="bk-form-actions">
            <button type="submit" class="bk-btn"><?php echo hsc(bk_t('bk_smtp_test_send', 'Send test email', 'admin')); ?></button>
        </div>
    </form>
</section>
