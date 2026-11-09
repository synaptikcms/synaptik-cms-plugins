<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Sender settings view.
 *
 * Sender identity used by every outgoing email (confirmation, welcome,
 * digest, custom newsletter) plus the admin dashboard widget toggle. The
 * transport itself — PHP mail() or SMTP — is configured on the separate
 * SMTP tab (templates/smtp.php).
 */

$settings = nl_load_settings();
$csrf     = nl_generate_csrf();
$msg      = $_GET['msg'] ?? '';
?>
<?php if ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(nl_t('nl_settings_saved', 'Settings saved.', 'admin')); ?></div>
<?php endif; ?>

<div class="nl-panel">
    <h2><?php echo hsc(nl_t('nl_settings_shortcode_title', 'Signup form shortcode', 'admin')); ?></h2>
    <p class="nl-panel-hint"><?php echo hsc(nl_t('nl_settings_shortcode_hint', 'Paste this shortcode in any page, article or project to display the signup form.', 'admin')); ?></p>
    <code class="nl-shortcode">[newsletter_signup]</code>
</div>

<form method="post" action="<?php echo hsc(nl_admin_actions_url()); ?>" class="nl-settings-form">
    <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
    <input type="hidden" name="action" value="save_settings">

    <div class="nl-panel">
        <h2><?php echo hsc(nl_t('nl_settings_title', 'Sender settings', 'admin')); ?></h2>
        <p class="nl-panel-hint"><?php echo hsc(nl_t('nl_settings_hint', 'The name and address your subscribers see in their inbox.', 'admin')); ?></p>

        <div class="nl-field">
            <label for="nl-from-name"><?php echo hsc(nl_t('nl_settings_from_name', 'Sender name', 'admin')); ?></label>
            <input type="text" id="nl-from-name" name="from_name" value="<?php echo hsc($settings['from_name']); ?>" placeholder="<?php echo hsc(nl_t('nl_settings_from_name_placeholder', 'Defaults to the site title', 'admin')); ?>">
        </div>

        <div class="nl-field">
            <label for="nl-from-email"><?php echo hsc(nl_t('nl_settings_from_email', 'Sender email', 'admin')); ?></label>
            <input type="email" id="nl-from-email" name="from_email" value="<?php echo hsc($settings['from_email']); ?>" placeholder="noreply@<?php echo hsc($_SERVER['HTTP_HOST'] ?? 'yourdomain.com'); ?>">
        </div>

        <div class="nl-field">
            <label for="nl-name-field"><?php echo hsc(nl_t('nl_settings_name_field', 'Name field on the signup form', 'admin')); ?></label>
            <select id="nl-name-field" name="name_field">
                <?php foreach (['off', 'optional', 'required'] as $nameOpt): ?>
                <option value="<?php echo hsc($nameOpt); ?>"<?php echo ($settings['name_field'] ?? 'off') === $nameOpt ? ' selected' : ''; ?>><?php echo hsc(nl_t('nl_settings_name_field_' . $nameOpt, $nameOpt, 'admin')); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="nl-field">
            <label for="nl-button-color"><?php echo hsc(nl_t('nl_settings_button_color', 'Subscribe button color', 'admin')); ?></label>
            <input type="color" id="nl-button-color" name="button_color" value="<?php echo hsc(nl_button_color() ?: '#2563eb'); ?>">
            <div class="nl-field--checkbox"><label><input type="checkbox" name="button_color_default"<?php echo nl_button_color() === '' ? ' checked' : ''; ?>> <?php echo hsc(nl_t('nl_settings_button_color_default', 'Use the theme\'s default button color', 'admin')); ?></label></div>
        </div>

        <div class="nl-field">
            <label for="nl-name-fallback"><?php echo hsc(nl_t('nl_settings_name_fallback', 'Text used when a subscriber has no name', 'admin')); ?></label>
            <input type="text" id="nl-name-fallback" name="name_fallback" maxlength="100" value="<?php echo hsc($settings['name_fallback'] ?? ''); ?>" placeholder="<?php echo hsc(nl_t('nl_settings_name_fallback_placeholder', 'Leave empty to insert nothing', 'admin')); ?>">
        </div>

        <div class="nl-field nl-field--checkbox">
            <label>
                <input type="checkbox" name="show_dashboard_widget" <?php echo !empty($settings['show_dashboard_widget']) ? 'checked' : ''; ?>>
                <?php echo hsc(nl_t('nl_settings_show_dashboard_widget', 'Show subscribers widget on the admin dashboard', 'admin')); ?>
            </label>
        </div>

        <div class="nl-form-actions">
            <button type="submit" class="nl-btn nl-btn--primary"><?php echo hsc(nl_t('nl_settings_save', 'Save settings', 'admin')); ?></button>
        </div>
    </div>
</form>
