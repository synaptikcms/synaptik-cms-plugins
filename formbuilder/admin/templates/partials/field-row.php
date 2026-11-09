<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Single field row partial.
 * Shared between edit.php's initial render (looped over $form['fields'])
 * and the <template> used by formbuilder-editor.js to append new rows —
 * both include this same file so the two never drift out of sync.
 *
 * Expects $field (an array shaped like fb_default_field()) in scope. $i is
 * unused for submission purposes — field_required_flag[] below always
 * submits one value per row (1 or 0), so row order stays reliably aligned
 * with field_label[]/field_key[]/etc. even after rows are added or removed
 * client-side. A plain checkbox name="field_required[]" would instead
 * vanish from $_POST entirely when unchecked, desyncing every array after it.
 */

$options = !empty($field['options']) ? implode("\n", $field['options']) : '';
$isCheckboxType = $field['type'] === 'checkbox';
$isSeparatorType = $field['type'] === 'separator';
$fieldWidth = ($field['width'] ?? 'full') === 'half' ? 'half' : 'full';
$separatorStyle = ($field['style'] ?? 'space') === 'line' ? 'line' : 'space';
$hideCellClass = $isSeparatorType ? ' fb-cell-invisible' : '';
?>
<div class="fb-field-row" data-fb-field-row>
    <span class="fb-drag-handle" data-fb-drag-handle draggable="true" title="<?php echo hsc(fb_t('fb_drag_to_reorder', 'Drag to reorder', 'admin')); ?>" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>
    </span>
    <input type="text" name="field_label[]" class="<?php echo trim('fb-cell' . $hideCellClass); ?>" value="<?php echo hsc($field['label']); ?>" placeholder="<?php echo hsc(fb_t('fb_field_label_placeholder', 'e.g. Full name', 'admin')); ?>">
    <input type="text" name="field_key[]" class="<?php echo trim('fb-cell' . $hideCellClass); ?>" value="<?php echo hsc($field['key']); ?>" placeholder="<?php echo hsc(fb_t('fb_field_key_placeholder', 'e.g. full_name', 'admin')); ?>" pattern="[a-z0-9_]*">
    <select name="field_type[]" data-fb-field-type>
        <option value="text" <?php echo $field['type'] === 'text' ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_type_text', 'Text', 'admin')); ?></option>
        <option value="textarea" <?php echo $field['type'] === 'textarea' ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_type_textarea', 'Long text', 'admin')); ?></option>
        <option value="email" <?php echo $field['type'] === 'email' ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_type_email', 'Email', 'admin')); ?></option>
        <option value="tel" <?php echo $field['type'] === 'tel' ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_type_tel', 'Phone', 'admin')); ?></option>
        <option value="number" <?php echo $field['type'] === 'number' ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_type_number', 'Number', 'admin')); ?></option>
        <option value="dropdown" <?php echo $field['type'] === 'dropdown' ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_type_dropdown', 'Dropdown', 'admin')); ?></option>
        <option value="checkbox" <?php echo $field['type'] === 'checkbox' ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_type_checkbox', 'Checkbox', 'admin')); ?></option>
        <option value="separator" <?php echo $isSeparatorType ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_type_separator', 'Separator', 'admin')); ?></option>
    </select>
    <select name="field_width[]" data-fb-field-width class="<?php echo trim('fb-cell' . $hideCellClass); ?>">
        <option value="full" <?php echo $fieldWidth === 'full' ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_width_full', 'Full width', 'admin')); ?></option>
        <option value="half" <?php echo $fieldWidth === 'half' ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_width_half', 'Half width', 'admin')); ?></option>
    </select>
    <label class="fb-required-toggle <?php echo $isSeparatorType ? 'fb-cell-invisible' : ''; ?>">
        <input type="checkbox" data-fb-required-checkbox <?php echo !empty($field['required']) ? 'checked' : ''; ?> <?php echo ($isCheckboxType || $isSeparatorType) ? 'disabled' : ''; ?>>
        <input type="hidden" name="field_required[]" value="<?php echo !empty($field['required']) ? '1' : '0'; ?>" data-fb-required-flag>
    </label>
    <button type="button" class="fb-btn fb-btn--delete fb-remove-field"><?php echo hsc(fb_t('fb_remove_field', 'Remove', 'admin')); ?></button>

    <div class="fb-field-options" data-fb-field-options <?php echo $field['type'] !== 'dropdown' ? 'hidden' : ''; ?>>
        <label><?php echo hsc(fb_t('fb_dropdown_options', 'Dropdown options (one per line)', 'admin')); ?></label>
        <textarea name="field_options[]" rows="3" placeholder="<?php echo hsc(fb_t('fb_options_placeholder', 'One option per line', 'admin')); ?>"><?php echo hsc($options); ?></textarea>
    </div>

    <div class="fb-field-options" data-fb-field-separator <?php echo !$isSeparatorType ? 'hidden' : ''; ?>>
        <label><?php echo hsc(fb_t('fb_separator_style', 'Separator style', 'admin')); ?></label>
        <select name="field_separator_style[]">
            <option value="space" <?php echo $separatorStyle === 'space' ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_separator_space', 'Blank space', 'admin')); ?></option>
            <option value="line" <?php echo $separatorStyle === 'line' ? 'selected' : ''; ?>><?php echo hsc(fb_t('fb_separator_line', 'Horizontal line', 'admin')); ?></option>
        </select>
    </div>
</div>
