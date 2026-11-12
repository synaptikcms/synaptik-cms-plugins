<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
$settings = df_load_settings();
$csrf     = df_generate_csrf();
$msg      = $_GET['msg'] ?? '';
?>
<?php if ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(df_t('df_settings_saved', 'Settings saved.', 'admin')); ?></div>
<?php endif; ?>

<div class="df-panel">
    <h2><?php echo hsc(df_t('df_settings_title', 'Underlining behavior', 'admin')); ?></h2>
    <p class="df-panel-hint"><?php echo hsc(df_t('df_settings_hint', 'By default, only the first occurrence of a term is underlined on a given page. Turn this on to underline every occurrence instead. Each definition can also override this individually — see the Active toggle when editing a definition.', 'admin')); ?></p>

    <form method="post" action="<?php echo hsc(df_admin_actions_url()); ?>" class="df-settings-form">
        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
        <input type="hidden" name="action" value="save_settings">

        <label class="df-checkbox-label">
            <input type="checkbox" id="df-wrap-all-default" name="wrap_all_by_default" value="1"
                   data-df-wrap-all-toggle="df-max-occurrences-default"
                   <?php echo !empty($settings['wrap_all_by_default']) ? 'checked' : ''; ?>>
            <?php echo hsc(df_t('df_settings_wrap_all', 'Underline every occurrence by default', 'admin')); ?>
        </label>

        <div class="df-field df-field--inline">
            <label for="df-max-occurrences-default"><?php echo hsc(df_t('df_settings_max_occurrences', 'Number of first occurrences to underline', 'admin')); ?></label>
            <input type="number" id="df-max-occurrences-default" name="max_occurrences_default" min="1" max="99"
                   value="<?php echo (int)($settings['max_occurrences_default'] ?? 1); ?>"
                   <?php echo !empty($settings['wrap_all_by_default']) ? 'disabled' : ''; ?>>
        </div>

        <label class="df-checkbox-label">
            <input type="checkbox" name="skip_headings" value="1" <?php echo !empty($settings['skip_headings']) ? 'checked' : ''; ?>>
            <?php echo hsc(df_t('df_settings_skip_headings', 'Never underline terms in titles and headings (h1–h6)', 'admin')); ?>
        </label>

        <div class="df-form-actions">
            <button type="submit" class="df-btn df-btn--primary"><?php echo hsc(df_t('df_settings_save', 'Save settings', 'admin')); ?></button>
        </div>
    </form>
</div>
