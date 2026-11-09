<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Subscribers view.
 *
 * Confirmed subscribers (the actual send list) and, below them, sign-ups
 * still awaiting double opt-in confirmation. The pending ones were only
 * ever surfaced as a number on the overview before — listing them makes
 * it possible to see who never confirmed and to remove stale entries.
 */

$subscribers = nl_load_subscribers();
$confirmed   = array_filter($subscribers, fn($r) => ($r['status'] ?? '') === 'confirmed');
$pending     = array_filter($subscribers, fn($r) => ($r['status'] ?? '') !== 'confirmed');
$csrf        = nl_generate_csrf();
$msg         = $_GET['msg'] ?? '';
?>
<?php if ($msg === 'deleted'): ?>
    <div class="message success"><?php echo hsc(nl_t('nl_subscriber_deleted', 'Subscriber removed.', 'admin')); ?></div>
<?php endif; ?>

<div class="nl-panel">
    <h2><?php echo hsc(nl_t('nl_subscribers_title', 'Confirmed subscribers', 'admin')); ?> (<?php echo count($confirmed); ?>)</h2>
    <p class="nl-panel-hint"><?php echo hsc(nl_t('nl_subscribers_hint', 'These are the addresses a newsletter or digest is sent to.', 'admin')); ?></p>

    <?php if (empty($confirmed)): ?>
        <p class="nl-empty"><?php echo hsc(nl_t('nl_no_subscribers', 'No confirmed subscribers yet.', 'admin')); ?></p>
    <?php else: ?>
        <table class="nl-table">
            <thead>
                <tr>
                    <th><?php echo hsc(nl_t('nl_col_name', 'Name', 'admin')); ?></th>
                    <th><?php echo hsc(nl_t('nl_col_email', 'Email', 'admin')); ?></th>
                    <th><?php echo hsc(nl_t('nl_col_confirmed_at', 'Confirmed on', 'admin')); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($confirmed as $id => $subscriber): ?>
                <tr>
                    <td><?php echo hsc($subscriber['name'] ?? ''); ?></td>
                    <td><?php echo hsc($subscriber['email']); ?></td>
                    <td><?php echo hsc(nl_format_datetime($subscriber['confirmed_at'] ?? '')); ?></td>
                    <td class="nl-table-actions">
                        <form method="post" action="<?php echo hsc(nl_admin_actions_url()); ?>" data-confirm="<?php echo hsc(nl_t('nl_confirm_delete', 'Remove this subscriber?', 'admin'), ENT_QUOTES); ?>">
                            <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                            <input type="hidden" name="action" value="delete_subscriber">
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

<div class="nl-panel">
    <h2><?php echo hsc(nl_t('nl_pending_title', 'Awaiting confirmation', 'admin')); ?> (<?php echo count($pending); ?>)</h2>
    <p class="nl-panel-hint"><?php echo hsc(nl_t('nl_pending_hint', 'These people signed up but never clicked the confirmation link, so they receive nothing.', 'admin')); ?></p>

    <?php if (empty($pending)): ?>
        <p class="nl-empty"><?php echo hsc(nl_t('nl_no_pending', 'Nobody is awaiting confirmation.', 'admin')); ?></p>
    <?php else: ?>
        <table class="nl-table">
            <thead>
                <tr>
                    <th><?php echo hsc(nl_t('nl_col_name', 'Name', 'admin')); ?></th>
                    <th><?php echo hsc(nl_t('nl_col_email', 'Email', 'admin')); ?></th>
                    <th><?php echo hsc(nl_t('nl_col_signed_up_at', 'Signed up on', 'admin')); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending as $id => $subscriber): ?>
                <tr>
                    <td><?php echo hsc($subscriber['name'] ?? ''); ?></td>
                    <td><?php echo hsc($subscriber['email']); ?></td>
                    <td><?php echo hsc(nl_format_datetime($subscriber['created_at'] ?? '')); ?></td>
                    <td class="nl-table-actions">
                        <form method="post" action="<?php echo hsc(nl_admin_actions_url()); ?>" data-confirm="<?php echo hsc(nl_t('nl_confirm_delete', 'Remove this subscriber?', 'admin'), ENT_QUOTES); ?>">
                            <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                            <input type="hidden" name="action" value="delete_subscriber">
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
