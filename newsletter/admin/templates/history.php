<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Send history view.
 *
 * One row per newsletter actually sent (free-form or digest). Records are
 * written once, at send time — see nl_record_sent() in the data layer.
 * Removing a row only deletes the history entry; it has no effect on the
 * emails already delivered.
 */

$history = nl_load_newsletters_sorted();
$csrf    = nl_generate_csrf();
$msg     = $_GET['msg'] ?? '';
?>
<?php if ($msg === 'newsletter_deleted'): ?>
    <div class="message success"><?php echo hsc(nl_t('nl_newsletter_deleted', 'Newsletter removed from history.', 'admin')); ?></div>
<?php endif; ?>

<div class="nl-panel">
    <h2><?php echo hsc(nl_t('nl_history_title', 'Send history', 'admin')); ?></h2>

    <?php if (empty($history)): ?>
        <p class="nl-empty"><?php echo hsc(nl_t('nl_no_history', 'No newsletters sent yet.', 'admin')); ?></p>
    <?php else: ?>
        <table class="nl-table">
            <thead>
                <tr>
                    <th><?php echo hsc(nl_t('nl_col_subject', 'Subject', 'admin')); ?></th>
                    <th><?php echo hsc(nl_t('nl_col_kind', 'Type', 'admin')); ?></th>
                    <th><?php echo hsc(nl_t('nl_col_recipients', 'Recipients', 'admin')); ?></th>
                    <th><?php echo hsc(nl_t('nl_col_sent_at', 'Sent on', 'admin')); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $id => $entry): ?>
                <tr>
                    <td><?php echo hsc($entry['subject'] ?? ''); ?></td>
                    <td><span class="nl-badge nl-badge--<?php echo hsc($entry['kind'] ?? 'custom'); ?>"><?php echo hsc(($entry['kind'] ?? '') === 'digest' ? nl_t('nl_kind_digest', 'Digest', 'admin') : nl_t('nl_kind_custom', 'Custom', 'admin')); ?></span></td>
                    <td><?php echo (int)($entry['recipient_count'] ?? 0); ?></td>
                    <td><?php echo hsc(nl_format_datetime($entry['sent_at'] ?? '')); ?></td>
                    <td class="nl-table-actions">
                        <form method="post" action="<?php echo hsc(nl_admin_actions_url()); ?>" data-confirm="<?php echo hsc(nl_t('nl_confirm_delete_newsletter', 'Remove this newsletter from history?', 'admin'), ENT_QUOTES); ?>">
                            <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                            <input type="hidden" name="action" value="delete_newsletter">
                            <input type="hidden" name="id" value="<?php echo hsc($id); ?>">
                            <button type="submit" class="nl-btn nl-btn--delete nl-btn--sm"><?php echo hsc(nl_t('nl_remove', 'Remove', 'admin')); ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
