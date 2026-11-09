<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Settings view.
 * Notification email, booking-window rules, and the list of appointment
 * types (label + duration), editable/addable/removable dynamically.
 */

$settings = bk_load_settings();
$csrf = bk_generate_csrf();
$msg = $_GET['msg'] ?? '';
?>
<?php if ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(bk_t('bk_settings_saved', 'Settings saved.', 'admin')); ?></div>
<?php endif; ?>

<form method="post" action="<?php echo hsc(bk_admin_actions_url()); ?>" class="bk-settings-form">
    <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
    <input type="hidden" name="return_url" value="<?php echo hsc(bk_admin_page_url('settings')); ?>">
    <input type="hidden" name="action" value="save_settings">

    <section class="bk-settings-section">
    <h2><?php echo hsc(bk_t('bk_settings_notifications_title', 'Sender & notifications', 'admin')); ?></h2>

    <div class="bk-field">
        <label for="bk-admin-email"><?php echo hsc(bk_t('bk_settings_admin_email', 'Notification email address', 'admin')); ?></label>
        <input type="email" id="bk-admin-email" name="admin_email" value="<?php echo hsc($settings['admin_email']); ?>" required>
    </div>

    <div class="bk-field">
        <label for="bk-from-email"><?php echo hsc(bk_t('bk_settings_from_email', 'Sender email address', 'admin')); ?></label>
        <input type="email" id="bk-from-email" name="from_email" value="<?php echo hsc($settings['from_email']); ?>" placeholder="noreply@<?php echo hsc($_SERVER['HTTP_HOST'] ?? ''); ?>">
        <p class="bk-field-help"><?php echo hsc(bk_t('bk_settings_from_email_help', 'Leave empty to use noreply@ followed by your domain name.', 'admin')); ?></p>
    </div>

    <div class="bk-field">
        <label for="bk-from-name"><?php echo hsc(bk_t('bk_settings_from_name', 'Sender name', 'admin')); ?></label>
        <input type="text" id="bk-from-name" name="from_name" value="<?php echo hsc($settings['from_name']); ?>" placeholder="<?php echo hsc(bk_t('bk_settings_from_name_placeholder', 'Your site title', 'admin')); ?>">
    </div>

    <div class="bk-field bk-field--checkbox">
        <label>
            <input type="checkbox" name="send_pending_email" <?php echo !empty($settings['send_pending_email']) ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_settings_send_pending_email', 'Send a receipt email to the client on submission', 'admin')); ?>
        </label>
        <p class="bk-field-help"><?php echo hsc(bk_t('bk_settings_send_pending_email_help', 'When enabled, the client receives an acknowledgement email as soon as they submit their request. Disable it if you confirm appointments immediately and prefer to send only the confirmation email.', 'admin')); ?></p>
    </div>

    <div class="bk-field bk-field--checkbox">
        <label>
            <input type="checkbox" name="show_dashboard_widget" <?php echo !empty($settings['show_dashboard_widget']) ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_settings_show_dashboard_widget', 'Show appointments widget on the admin dashboard', 'admin')); ?>
        </label>
    </div>

    <div class="bk-field bk-field--checkbox">
        <label>
            <input type="checkbox" name="show_cancel_link_in_email" <?php echo !empty($settings['show_cancel_link_in_email']) ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_settings_show_cancel_link_in_email', 'Include a cancellation link in the client\'s emails', 'admin')); ?>
        </label>
        <p class="bk-field-help"><?php echo hsc(bk_t('bk_settings_show_cancel_link_in_email_help', 'When enabled, the pending and confirmation emails end with a small link letting the client cancel their own appointment. Disable it if cancellations must always go through you.', 'admin')); ?></p>
    </div>
    </section>

    <section class="bk-settings-section">
    <h2><?php echo hsc(bk_t('bk_settings_booking_window_title', 'Booking window', 'admin')); ?></h2>

    <div class="bk-field">
        <label for="bk-slot-interval"><?php echo hsc(bk_t('bk_settings_slot_interval', 'Slot interval (minutes)', 'admin')); ?></label>
        <input type="number" id="bk-slot-interval" name="slot_interval" min="5" step="5" value="<?php echo (int)$settings['slot_interval']; ?>">
    </div>

    <div class="bk-field">
        <label for="bk-min-notice"><?php echo hsc(bk_t('bk_settings_min_notice', 'Minimum notice (hours)', 'admin')); ?></label>
        <input type="number" id="bk-min-notice" name="min_notice_hours" min="0" value="<?php echo (int)$settings['min_notice_hours']; ?>">
    </div>

    <div class="bk-field">
        <label for="bk-max-advance"><?php echo hsc(bk_t('bk_settings_max_advance', 'Maximum advance booking (days)', 'admin')); ?></label>
        <input type="number" id="bk-max-advance" name="max_advance_days" min="1" value="<?php echo (int)$settings['max_advance_days']; ?>">
    </div>

    <div class="bk-field bk-field--checkbox">
        <label>
            <input type="checkbox" id="bk-enable-callback" name="enable_callback" <?php echo !empty($settings['enable_callback']) ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_settings_enable_callback', 'Enable callback request option on the booking form', 'admin')); ?>
        </label>
        <p class="bk-field-help"><?php echo hsc(bk_t('bk_settings_enable_callback_help', 'When disabled, the callback checkbox and date/time fields are hidden from the public form. Existing bookings with a callback request are unaffected.', 'admin')); ?></p>
    </div>

    <div class="bk-field bk-field--checkbox" id="bk-restrict-callback-wrap">
        <label>
            <input type="checkbox" id="bk-restrict-callback" name="restrict_callback_to_availability" <?php echo !empty($settings['restrict_callback_to_availability']) ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_settings_restrict_callback', 'Restrict requested callback times to configured availability hours', 'admin')); ?>
        </label>
        <p class="bk-field-help"><?php echo hsc(bk_t('bk_settings_restrict_callback_help', 'When enabled, visitors can only request a callback within the hours set in the Availability tab. When disabled, any time of day can be requested.', 'admin')); ?></p>
    </div>

    <div class="bk-field">
        <label><?php echo hsc(bk_t('bk_settings_slot_display_mode', 'Unavailable time slots', 'admin')); ?></label>
        <label class="bk-radio-option">
            <input type="radio" name="slot_display_mode" value="hide" <?php echo ($settings['slot_display_mode'] ?? 'hide') === 'hide' ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_settings_slot_display_hide', 'Hide them — only bookable times are shown', 'admin')); ?>
        </label>
        <label class="bk-radio-option">
            <input type="radio" name="slot_display_mode" value="show_disabled" <?php echo ($settings['slot_display_mode'] ?? 'hide') === 'show_disabled' ? 'checked' : ''; ?>>
            <?php echo hsc(bk_t('bk_settings_slot_display_show', 'Show them greyed out — visitors see the full schedule', 'admin')); ?>
        </label>
    </div>

    <div class="bk-field">
        <label for="bk-cancel-notice"><?php echo hsc(bk_t('bk_settings_cancel_notice', 'Client cancellation cutoff (hours before appointment)', 'admin')); ?></label>
        <input type="number" id="bk-cancel-notice" name="client_cancel_min_notice_hours" min="0" value="<?php echo (int)($settings['client_cancel_min_notice_hours'] ?? 48); ?>">
        <p class="bk-field-help"><?php echo hsc(bk_t('bk_settings_cancel_notice_help', 'The one-click cancellation link in confirmation emails stops working this many hours before the appointment. Past this point, clients see a polite message asking them to contact you directly. Set to 0 to allow cancellation right up until the appointment starts.', 'admin')); ?></p>
    </div>
    </section>

    <section class="bk-settings-section">
    <h2><?php echo hsc(bk_t('bk_ff_title', 'Booking form fields', 'admin')); ?></h2>
    <p class="bk-field-help"><?php echo hsc(bk_t('bk_ff_help', 'Choose which client fields appear on the form, which ones are required, and customise their label and placeholder. Leave a label or placeholder empty to use the default text. At least one of email and phone always stays enabled, and without an email address the client receives no confirmation emails.', 'admin')); ?></p>
    <div class="bk-ff-row bk-ff-row--header">
        <span><?php echo hsc(bk_t('bk_ff_col_field', 'Field', 'admin')); ?></span>
        <span><?php echo hsc(bk_t('bk_ff_col_show', 'Show', 'admin')); ?></span>
        <span><?php echo hsc(bk_t('bk_ff_col_required', 'Required', 'admin')); ?></span>
        <span><?php echo hsc(bk_t('bk_ff_col_label', 'Label', 'admin')); ?></span>
        <span><?php echo hsc(bk_t('bk_ff_col_placeholder', 'Placeholder', 'admin')); ?></span>
    </div>
    <?php
    $ffDefaults = [
        'first_name' => bk_t('booking_first_name', 'First name'),
        'last_name'  => bk_t('booking_last_name', 'Last name'),
        'email'      => bk_t('booking_email', 'Email'),
        'phone'      => bk_t('booking_phone', 'Phone number'),
        'notes'      => bk_t('booking_notes', 'Notes'),
    ];
    foreach (bk_get_form_fields() as $ffKey => $ffCfg): ?>
    <div class="bk-ff-row">
        <span class="bk-ff-name"><?php echo hsc(bk_t('bk_ff_' . $ffKey, $ffKey, 'admin')); ?></span>
        <input type="checkbox" name="ff_<?php echo hsc($ffKey); ?>_enabled" <?php echo $ffCfg['enabled'] ? 'checked' : ''; ?> aria-label="<?php echo hsc(bk_t('bk_ff_col_show', 'Show', 'admin')); ?>">
        <input type="checkbox" name="ff_<?php echo hsc($ffKey); ?>_required" <?php echo $ffCfg['required'] ? 'checked' : ''; ?> aria-label="<?php echo hsc(bk_t('bk_ff_col_required', 'Required', 'admin')); ?>">
        <input type="text" name="ff_<?php echo hsc($ffKey); ?>_label" maxlength="200" value="<?php echo hsc($ffCfg['label']); ?>" placeholder="<?php echo hsc($ffDefaults[$ffKey]); ?>">
        <input type="text" name="ff_<?php echo hsc($ffKey); ?>_placeholder" maxlength="200" value="<?php echo hsc($ffCfg['placeholder']); ?>">
    </div>
    <?php endforeach; ?>
    </section>

    <section class="bk-settings-section">
    <h2><?php echo hsc(bk_t('bk_settings_ics_title', 'Calendar invite (.ics)', 'admin')); ?></h2>
    <p class="bk-field-help"><?php echo hsc(bk_t('bk_settings_ics_intro', 'Controls the calendar event attached to the client\'s confirmation emails. Leave a field empty to use the default. Click a placeholder to insert it into the focused field.', 'admin')); ?></p>

    <div class="bk-token-bar" id="bk-ics-token-bar">
        <button type="button" class="bk-token-btn" data-token="{site_name}">{site_name}</button>
        <button type="button" class="bk-token-btn" data-token="{type}">{type}</button>
        <button type="button" class="bk-token-btn" data-token="{first_name}">{first_name}</button>
        <button type="button" class="bk-token-btn" data-token="{last_name}">{last_name}</button>
        <button type="button" class="bk-token-btn" data-token="{full_name}">{full_name}</button>
        <button type="button" class="bk-token-btn" data-token="{date}">{date}</button>
        <button type="button" class="bk-token-btn" data-token="{time}">{time}</button>
    </div>

    <div class="bk-field">
        <label for="bk-ics-summary"><?php echo hsc(bk_t('bk_settings_ics_summary', 'Event title', 'admin')); ?></label>
        <input type="text" id="bk-ics-summary" name="ics_summary_template" value="<?php echo hsc($settings['ics_summary_template'] ?? ''); ?>" placeholder="<?php echo hsc(bk_t('bk_settings_ics_summary_placeholder', 'Appointment — {type}', 'admin')); ?>">
    </div>

    <div class="bk-field">
        <label for="bk-ics-description"><?php echo hsc(bk_t('bk_settings_ics_description', 'Event description', 'admin')); ?></label>
        <textarea id="bk-ics-description" name="ics_description_template" rows="3"><?php echo hsc($settings['ics_description_template'] ?? ''); ?></textarea>
    </div>

    <div class="bk-field">
        <label for="bk-ics-location"><?php echo hsc(bk_t('bk_settings_ics_location', 'Location', 'admin')); ?></label>
        <input type="text" id="bk-ics-location" name="ics_location" value="<?php echo hsc($settings['ics_location'] ?? ''); ?>" placeholder="<?php echo hsc(bk_t('bk_settings_ics_location_placeholder', '12 rue de la Santé, 75001 Paris', 'admin')); ?>">
    </div>

    <div class="bk-field">
        <label for="bk-ics-url"><?php echo hsc(bk_t('bk_settings_ics_url', 'URL', 'admin')); ?></label>
        <input type="url" id="bk-ics-url" name="ics_url" value="<?php echo hsc($settings['ics_url'] ?? ''); ?>" placeholder="https://<?php echo hsc($_SERVER['HTTP_HOST'] ?? ''); ?>">
    </div>
    </section>

    <section class="bk-settings-section">
    <h2><?php echo hsc(bk_t('bk_settings_types_title', 'Appointment types', 'admin')); ?></h2>
    <p class="bk-field-help"><?php echo hsc(bk_t('bk_settings_types_help', 'The internal key identifies the type in the system and is never shown to visitors — use lowercase letters, numbers and underscores only. Changing it later will not update appointments already booked under the old key.', 'admin')); ?></p>
    <div class="bk-type-row bk-type-row--header">
        <span><?php echo hsc(bk_t('bk_settings_type_key', 'Internal key', 'admin')); ?></span>
        <span><?php echo hsc(bk_t('bk_settings_type_label', 'Label', 'admin')); ?></span>
        <span><?php echo hsc(bk_t('bk_settings_type_duration', 'Duration (minutes)', 'admin')); ?></span>
        <span></span>
    </div>
    <div id="bk-types-list">
        <?php foreach ($settings['appointment_types'] as $key => $type): ?>
        <div class="bk-type-row">
            <input type="text" name="type_key[]" value="<?php echo hsc($key); ?>" placeholder="<?php echo hsc(bk_t('bk_settings_type_key_placeholder', 'e.g. first_visit', 'admin')); ?>" pattern="[a-z0-9_]+" required>
            <input type="text" name="type_label[]" value="<?php echo hsc($type['label']); ?>" placeholder="<?php echo hsc(bk_t('bk_settings_type_label_placeholder', 'e.g. First appointment', 'admin')); ?>" required>
            <input type="number" name="type_duration[]" value="<?php echo (int)$type['duration']; ?>" min="5" step="5" required>
            <button type="button" class="bk-btn bk-btn--delete bk-remove-row"><?php echo hsc(bk_t('bk_settings_remove_type', 'Remove', 'admin')); ?></button>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" id="bk-add-type" class="bk-btn"><?php echo hsc(bk_t('bk_settings_add_type', 'Add type', 'admin')); ?></button>
    </section>

    <div class="bk-form-actions">
        <button type="submit" class="bk-btn bk-btn--primary"><?php echo hsc(bk_t('bk_settings_save', 'Save settings', 'admin')); ?></button>
    </div>
</form>

<?php
$settingsScriptData = [
    'typeKeyPlaceholder'   => bk_t('bk_settings_type_key_placeholder', 'e.g. first_visit', 'admin'),
    'typeLabelPlaceholder' => bk_t('bk_settings_type_label_placeholder', 'e.g. First appointment', 'admin'),
    'removeType'           => bk_t('bk_settings_remove_type', 'Remove', 'admin'),
];
$settingsJsV = @filemtime(__DIR__ . '/../../assets/js/settings.js') ?: time();
?>
<script type="application/json" id="bk-settings-data"><?php echo json_encode($settingsScriptData); ?></script>
<script src="<?php echo hsc(bk_plugin_base_url() . 'assets/js/settings.js'); ?>?v=<?php echo $settingsJsV; ?>"></script>
