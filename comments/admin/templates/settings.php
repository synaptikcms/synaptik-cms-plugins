<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Comments plugin settings.
 * Moderation mode, per-type activation, keyword blacklist, hCaptcha toggle.
 */

$settings = cm_load_settings();
$csrf     = cm_generate_csrf();
$msg      = $_GET['msg'] ?? '';

$hcaptchaConfigured = cm_core_hcaptcha_secret() !== '' && cm_core_hcaptcha_site_key() !== '';
?>
<?php if ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(cm_t('cm_settings_saved', 'Settings saved.', 'admin')); ?></div>
<?php endif; ?>

<form method="post" action="<?php echo hsc(cm_admin_actions_url()); ?>" class="cm-a-form">
    <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
    <input type="hidden" name="action" value="save_settings">

    <div class="cm-a-panel">
        <h2><?php echo hsc(cm_t('cm_settings_moderation_title', 'Moderation', 'admin')); ?></h2>
        <div class="cm-a-radio-group">
            <label class="cm-a-radio-label">
                <input type="radio" name="moderation_mode" value="manual" <?php echo $settings['moderation_mode'] === 'manual' ? 'checked' : ''; ?>>
                <span>
                    <strong><?php echo hsc(cm_t('cm_mode_manual', 'Manual approval', 'admin')); ?></strong>
                    <span class="cm-a-radio-hint"><?php echo hsc(cm_t('cm_mode_manual_hint', 'Every comment stays hidden until you approve it.', 'admin')); ?></span>
                </span>
            </label>
            <label class="cm-a-radio-label">
                <input type="radio" name="moderation_mode" value="auto" <?php echo $settings['moderation_mode'] === 'auto' ? 'checked' : ''; ?>>
                <span>
                    <strong><?php echo hsc(cm_t('cm_mode_auto', 'Auto-publish', 'admin')); ?></strong>
                    <span class="cm-a-radio-hint"><?php echo hsc(cm_t('cm_mode_auto_hint', 'Comments appear immediately. You can still moderate them afterward.', 'admin')); ?></span>
                </span>
            </label>
        </div>
    </div>

    <div class="cm-a-panel">
        <h2><?php echo hsc(cm_t('cm_settings_types_title', 'Enabled on', 'admin')); ?></h2>
        <p class="cm-a-panel-hint"><?php echo hsc(cm_t('cm_settings_types_hint', 'Comments only appear where your theme includes the [comments] shortcode in its template — this only controls which content types allow it.', 'admin')); ?></p>
        <div class="cm-a-checkbox-group">
            <label class="cm-a-checkbox-label">
                <input type="checkbox" name="enabled_article" value="1" <?php echo !empty($settings['enabled_types']['article']) ? 'checked' : ''; ?>>
                <?php echo hsc(cm_t('cm_type_article', 'Articles', 'admin')); ?>
            </label>
            <label class="cm-a-checkbox-label">
                <input type="checkbox" name="enabled_page" value="1" <?php echo !empty($settings['enabled_types']['page']) ? 'checked' : ''; ?>>
                <?php echo hsc(cm_t('cm_type_page', 'Pages', 'admin')); ?>
            </label>
            <label class="cm-a-checkbox-label">
                <input type="checkbox" name="enabled_project" value="1" <?php echo !empty($settings['enabled_types']['project']) ? 'checked' : ''; ?>>
                <?php echo hsc(cm_t('cm_type_project', 'Projects', 'admin')); ?>
            </label>
        </div>
    </div>

    <div class="cm-a-panel">
        <h2><?php echo hsc(cm_t('cm_settings_blacklist_title', 'Keyword blacklist', 'admin')); ?></h2>
        <p class="cm-a-panel-hint"><?php echo hsc(cm_t('cm_settings_blacklist_hint', 'One word or phrase per line. A comment containing any of these is silently held for moderation instead of being rejected outright.', 'admin')); ?></p>
        <textarea name="blacklist" rows="6" class="cm-a-textarea" placeholder="casino&#10;viagra&#10;click here"><?php echo hsc(implode("\n", $settings['blacklist'])); ?></textarea>
    </div>

    <div class="cm-a-panel">
        <h2><?php echo hsc(cm_t('cm_settings_dashboard_title', 'Admin dashboard', 'admin')); ?></h2>
        <label class="cm-a-checkbox-label">
            <input type="checkbox" name="show_dashboard_widget" value="1" <?php echo !empty($settings['show_dashboard_widget']) ? 'checked' : ''; ?>>
            <?php echo hsc(cm_t('cm_settings_dashboard_widget', 'Show pending comments widget on the dashboard', 'admin')); ?>
        </label>
    </div>

    <div class="cm-a-panel">
        <h2><?php echo hsc(cm_t('cm_settings_hcaptcha_title', 'hCaptcha', 'admin')); ?></h2>
        <?php if (!$hcaptchaConfigured): ?>
        <p class="cm-a-panel-hint"><?php echo hsc(cm_t('cm_settings_hcaptcha_missing', 'Configure your hCaptcha site key and secret key under Settings → Contact first — the comment form reuses that same configuration.', 'admin')); ?></p>
        <?php endif; ?>
        <label class="cm-a-checkbox-label">
            <input type="checkbox" name="use_hcaptcha" value="1" <?php echo !empty($settings['use_hcaptcha']) ? 'checked' : ''; ?> <?php echo !$hcaptchaConfigured ? 'disabled' : ''; ?>>
            <?php echo hsc(cm_t('cm_settings_hcaptcha_enable', 'Require hCaptcha on the comment form', 'admin')); ?>
        </label>
    </div>

    <div class="cm-a-form-actions">
        <button type="submit" class="cm-a-btn cm-a-btn--primary"><?php echo hsc(cm_t('cm_settings_save', 'Save settings', 'admin')); ?></button>
    </div>
</form>
