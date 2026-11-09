<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Submissions view.
 * Lists past submissions across all forms, filterable to one form via the
 * dropdown. Each row can be deleted (the PDF itself is never stored on
 * disk — it is generated on demand for re-download, and freshly at
 * submission time for the notification email).
 */

require_once __DIR__ . '/../../formbuilder-pdf.php';

$forms       = fb_load_form_index();
$filterForm  = trim($_GET['form'] ?? '');
$filterForm  = ($filterForm !== '' && isset($forms[$filterForm])) ? $filterForm : null;

$submissions = fb_list_submissions($filterForm, 50);
$csrf        = fb_generate_csrf();
$msg         = $_GET['msg'] ?? '';
?>
<?php if ($msg === 'deleted'): ?>
    <div class="message success"><?php echo hsc(fb_t('fb_submission_deleted', 'Submission deleted.', 'admin')); ?></div>
<?php endif; ?>

<form method="get" class="fb-filter-form">
    <input type="hidden" name="action" value="plugin_page">
    <input type="hidden" name="slug" value="formbuilder">
    <input type="hidden" name="view" value="submissions">
    <label for="fb-filter-form-select"><?php echo hsc(fb_t('fb_filter_by_form', 'Filter by form', 'admin')); ?></label>
    <select id="fb-filter-form-select" name="form" data-autosubmit>
        <option value=""><?php echo hsc(fb_t('fb_all_forms', 'All forms', 'admin')); ?></option>
        <?php foreach ($forms as $form): ?>
        <option value="<?php echo hsc($form['id']); ?>" <?php echo $filterForm === $form['id'] ? 'selected' : ''; ?>>
            <?php echo hsc($form['name'] !== '' ? $form['name'] : $form['id']); ?>
        </option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (empty($submissions)): ?>
    <p class="fb-empty"><?php echo hsc(fb_t('fb_no_submissions', 'No submissions yet.', 'admin')); ?></p>
<?php else: ?>
<table class="fb-table">
    <thead>
        <tr>
            <th><?php echo hsc(fb_t('fb_col_date', 'Date', 'admin')); ?></th>
            <th><?php echo hsc(fb_t('fb_col_form', 'Form', 'admin')); ?></th>
            <th><?php echo hsc(fb_t('fb_col_summary', 'Summary', 'admin')); ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($submissions as $submission):
            $formName = $forms[$submission['form_id']]['name'] ?? $submission['form_id'];
            $firstValues = array_slice($submission['values'], 0, 2, true);
            $summary = implode(' · ', array_filter(array_map(fn($v) => is_string($v) ? $v : '', $firstValues)));
            $ts = strtotime($submission['submitted_at']);
        ?>
        <tr>
            <td><?php echo hsc($ts !== false ? date('Y-m-d H:i', $ts) : $submission['submitted_at']); ?></td>
            <td><?php echo hsc($formName); ?></td>
            <td><?php echo hsc($summary !== '' ? $summary : '—'); ?></td>
            <td class="fb-row-actions">
                <a href="<?php echo hsc(fb_plugin_base_url() . 'admin/download-pdf.php?form=' . urlencode($submission['form_id']) . '&file=' . urlencode($submission['_file']) . '&csrf=' . urlencode($csrf)); ?>" class="fb-btn"><?php echo hsc(fb_t('fb_download_pdf', 'Download PDF', 'admin')); ?></a>
                <form method="post" action="<?php echo hsc(fb_admin_actions_url()); ?>" class="fb-inline-form" data-confirm="<?php echo hsc(fb_t('fb_submission_delete_confirm', 'Permanently delete this submission?', 'admin')); ?>">
                    <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                    <input type="hidden" name="return_url" value="<?php echo hsc(fb_admin_page_url('submissions', $filterForm ? ['form' => $filterForm] : [])); ?>">
                    <input type="hidden" name="action" value="delete_submission">
                    <input type="hidden" name="form_id" value="<?php echo hsc($submission['form_id']); ?>">
                    <input type="hidden" name="file" value="<?php echo hsc($submission['_file']); ?>">
                    <button type="submit" class="fb-btn fb-btn--delete"><?php echo hsc(fb_t('fb_action_delete', 'Delete', 'admin')); ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
