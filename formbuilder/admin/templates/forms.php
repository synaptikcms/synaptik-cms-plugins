<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Forms list view.
 * Shows every form with its field count and the shortcode to paste into
 * content, plus links to edit or delete it.
 */

$forms = fb_load_form_index();
$msg   = $_GET['msg'] ?? '';
$csrf  = fb_generate_csrf();
?>
<?php if ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(fb_t('fb_form_saved', 'Form saved.', 'admin')); ?></div>
<?php elseif ($msg === 'deleted'): ?>
    <div class="message success"><?php echo hsc(fb_t('fb_form_deleted', 'Form deleted.', 'admin')); ?></div>
<?php endif; ?>

<div class="fb-list-header">
    <a href="<?php echo hsc(fb_admin_page_url('edit')); ?>" class="fb-btn fb-btn--primary"><?php echo hsc(fb_t('fb_new_form', 'New form', 'admin')); ?></a>
</div>

<?php if (empty($forms)): ?>
    <p class="fb-empty"><?php echo hsc(fb_t('fb_no_forms', 'No forms yet. Create your first one to get started.', 'admin')); ?></p>
<?php else: ?>
<table class="fb-table">
    <thead>
        <tr>
            <th><?php echo hsc(fb_t('fb_col_name', 'Name', 'admin')); ?></th>
            <th><?php echo hsc(fb_t('fb_col_fields', 'Fields', 'admin')); ?></th>
            <th><?php echo hsc(fb_t('fb_col_shortcode', 'Shortcode', 'admin')); ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($forms as $form): ?>
        <tr>
            <td><?php echo hsc($form['name'] !== '' ? $form['name'] : $form['id']); ?></td>
            <td><?php echo (int)$form['field_count']; ?></td>
            <td><code class="fb-shortcode-tag" data-fb-copy>[form id="<?php echo hsc($form['id']); ?>"]</code></td>
            <td class="fb-row-actions">
                <a href="<?php echo hsc(fb_admin_page_url('edit', ['id' => $form['id']])); ?>" class="fb-btn"><?php echo hsc(fb_t('fb_action_edit', 'Edit', 'admin')); ?></a>
                <form method="post" action="<?php echo hsc(fb_admin_actions_url()); ?>" class="fb-inline-form" data-confirm="<?php echo hsc(fb_t('fb_delete_confirm', 'Permanently delete this form? Past submissions are kept.', 'admin')); ?>">
                    <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                    <input type="hidden" name="return_url" value="<?php echo hsc(fb_admin_page_url('forms')); ?>">
                    <input type="hidden" name="action" value="delete_form">
                    <input type="hidden" name="id" value="<?php echo hsc($form['id']); ?>">
                    <button type="submit" class="fb-btn fb-btn--delete"><?php echo hsc(fb_t('fb_action_delete', 'Delete', 'admin')); ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php
$fbFormsJsV = @filemtime(__DIR__ . '/../../assets/js/forms.js') ?: time();
?>
<script type="application/json" id="fb-forms-data"><?php echo json_encode(['clickToCopy' => fb_t('fb_click_to_copy', 'Click to copy', 'admin')]); ?></script>
<script src="<?php echo hsc(fb_plugin_base_url() . 'assets/js/forms.js'); ?>?v=<?php echo $fbFormsJsV; ?>"></script>
