<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Redirects dashboard.
 * Add-redirect form, the existing redirects table (edit inline via a
 * pre-filled row switch, delete), and the 404-fallback toggle.
 */

$redirects = rd_load_redirects_sorted();
$settings  = rd_load_settings();
$csrf      = rd_generate_csrf();
$msg       = $_GET['msg'] ?? '';

// Pre-fill the form for an edit — ?edit={id} switches the form into
// "update" mode without a separate page/view.
$editId     = $_GET['edit'] ?? '';
$editRecord = $editId !== '' ? rd_get_redirect($editId) : null;
?>
<?php if ($msg === 'created'): ?>
    <div class="message success"><?php echo hsc(rd_t('rd_created', 'Redirect created.', 'admin')); ?></div>
<?php elseif ($msg === 'updated'): ?>
    <div class="message success"><?php echo hsc(rd_t('rd_updated', 'Redirect updated.', 'admin')); ?></div>
<?php elseif ($msg === 'deleted'): ?>
    <div class="message success"><?php echo hsc(rd_t('rd_deleted', 'Redirect removed.', 'admin')); ?></div>
<?php elseif ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(rd_t('rd_settings_saved', 'Settings saved.', 'admin')); ?></div>
<?php elseif ($msg === 'duplicate'): ?>
    <div class="message error"><?php echo hsc(rd_t('rd_error_duplicate', 'A redirect for this URL already exists.', 'admin')); ?></div>
<?php elseif ($msg === 'self_redirect'): ?>
    <div class="message error"><?php echo hsc(rd_t('rd_error_self_redirect', 'The destination cannot be the same as the source.', 'admin')); ?></div>
<?php elseif ($msg === 'invalid'): ?>
    <div class="message error"><?php echo hsc(rd_t('rd_error_invalid', 'Please fill in both the source and destination.', 'admin')); ?></div>
<?php endif; ?>

<div class="rd-panel">
    <h2><?php echo hsc($editRecord !== null ? rd_t('rd_edit_title', 'Edit redirect', 'admin') : rd_t('rd_add_title', 'New redirect', 'admin')); ?></h2>
    <p class="rd-panel-hint"><?php echo hsc(rd_t('rd_add_hint', 'When a visitor requests the source URL, they are sent to the destination instead. Use 301 for a permanent move (transfers SEO ranking) or 302 for a temporary one.', 'admin')); ?></p>

    <form method="post" action="<?php echo hsc(rd_admin_actions_url()); ?>" class="rd-form">
        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
        <input type="hidden" name="action" value="<?php echo $editRecord !== null ? 'update_redirect' : 'create_redirect'; ?>">
        <?php if ($editRecord !== null): ?>
        <input type="hidden" name="id" value="<?php echo hsc($editId); ?>">
        <?php endif; ?>

        <div class="rd-field">
            <label for="rd-from"><?php echo hsc(rd_t('rd_field_from', 'Source path', 'admin')); ?></label>
            <input type="text" id="rd-from" name="from" required
                   value="<?php echo hsc($editRecord['from'] ?? ''); ?>"
                   placeholder="/ancien-article/">
        </div>

        <div class="rd-field">
            <label for="rd-to"><?php echo hsc(rd_t('rd_field_to', 'Destination', 'admin')); ?></label>
            <input type="text" id="rd-to" name="to" required
                   value="<?php echo hsc($editRecord['to'] ?? ''); ?>"
                   placeholder="/article/nouveau-titre/ <?php echo hsc(rd_t('rd_or_full_url', 'or a full https:// URL', 'admin')); ?>">
        </div>

        <div class="rd-field rd-field--inline">
            <label for="rd-status"><?php echo hsc(rd_t('rd_field_status', 'Redirect type', 'admin')); ?></label>
            <select id="rd-status" name="status_code">
                <option value="301" <?php echo (int)($editRecord['status_code'] ?? 301) === 301 ? 'selected' : ''; ?>>301 — <?php echo hsc(rd_t('rd_status_permanent', 'Permanent', 'admin')); ?></option>
                <option value="302" <?php echo (int)($editRecord['status_code'] ?? 301) === 302 ? 'selected' : ''; ?>>302 — <?php echo hsc(rd_t('rd_status_temporary', 'Temporary', 'admin')); ?></option>
            </select>
        </div>

        <div class="rd-form-actions">
            <button type="submit" class="rd-btn rd-btn--primary">
                <?php echo hsc($editRecord !== null ? rd_t('rd_save_changes', 'Save changes', 'admin') : rd_t('rd_create_button', 'Create redirect', 'admin')); ?>
            </button>
            <?php if ($editRecord !== null): ?>
            <a href="<?php echo hsc(rd_admin_page_url()); ?>" class="rd-btn rd-btn--outline"><?php echo hsc(rd_t('rd_cancel', 'Cancel', 'admin')); ?></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="rd-panel">
    <h2><?php echo hsc(rd_t('rd_list_title', 'Existing redirects', 'admin')); ?> (<?php echo count($redirects); ?>)</h2>

    <?php if (empty($redirects)): ?>
        <p class="rd-empty"><?php echo hsc(rd_t('rd_no_redirects', 'No redirects yet.', 'admin')); ?></p>
    <?php else: ?>
        <table class="rd-table">
            <thead>
                <tr>
                    <th><?php echo hsc(rd_t('rd_col_from', 'Source', 'admin')); ?></th>
                    <th><?php echo hsc(rd_t('rd_col_to', 'Destination', 'admin')); ?></th>
                    <th><?php echo hsc(rd_t('rd_col_type', 'Type', 'admin')); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($redirects as $id => $entry): ?>
                <tr>
                    <td><code><?php echo hsc($entry['from'] ?? ''); ?></code></td>
                    <td><code><?php echo hsc($entry['to'] ?? ''); ?></code></td>
                    <td><span class="rd-badge rd-badge--<?php echo (int)($entry['status_code'] ?? 301); ?>"><?php echo (int)($entry['status_code'] ?? 301); ?></span></td>
                    <td class="rd-table-actions">
                        <a href="<?php echo hsc(rd_admin_page_url(['edit' => $id])); ?>" class="rd-btn rd-btn--sm rd-btn--outline"><?php echo hsc(rd_t('rd_edit', 'Edit', 'admin')); ?></a>
                        <form method="post" action="<?php echo hsc(rd_admin_actions_url()); ?>" data-confirm="<?php echo hsc(rd_t('rd_confirm_delete', 'Remove this redirect?', 'admin'), ENT_QUOTES); ?>">
                            <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                            <input type="hidden" name="action" value="delete_redirect">
                            <input type="hidden" name="id" value="<?php echo hsc($id); ?>">
                            <button type="submit" class="rd-btn rd-btn--delete rd-btn--sm"><?php echo hsc(rd_t('rd_remove', 'Remove', 'admin')); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="rd-panel">
    <h2><?php echo hsc(rd_t('rd_fallback_title', '404 fallback', 'admin')); ?></h2>
    <p class="rd-panel-hint"><?php echo hsc(rd_t('rd_fallback_hint', 'When a visitor reaches a page that no longer exists and no redirect above matches, send them to the homepage instead of the theme\'s 404 page. Always temporary (302) — it never tells search engines the homepage replaced the missing page.', 'admin')); ?></p>

    <form method="post" action="<?php echo hsc(rd_admin_actions_url()); ?>" class="rd-settings-form">
        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
        <input type="hidden" name="action" value="save_settings">

        <label class="rd-checkbox-label">
            <input type="checkbox" name="fallback_404_home" value="1" <?php echo !empty($settings['fallback_404_home']) ? 'checked' : ''; ?>>
            <?php echo hsc(rd_t('rd_fallback_checkbox', 'Redirect unresolved 404 pages to the homepage', 'admin')); ?>
        </label>

        <div class="rd-form-actions">
            <button type="submit" class="rd-btn rd-btn--primary"><?php echo hsc(rd_t('rd_settings_save', 'Save settings', 'admin')); ?></button>
        </div>
    </form>
</div>
