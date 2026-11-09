<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Requests view.
 * Full appointment list, optionally filtered by date or status, with
 * inline confirm/refuse/cancel/delete actions (each a mini CSRF-protected
 * form posting to actions.php).
 */

$filterDate   = trim($_GET['date'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$csrf = bk_generate_csrf();
$actionsUrl = bk_admin_actions_url();

$msg = $_GET['msg'] ?? '';
$msgMap = [
    'confirmed' => bk_t('bk_confirm_success', 'Appointment confirmed. Notification email sent.', 'admin'),
    'refused'   => bk_t('bk_refuse_success', 'Appointment refused. The client has been notified.', 'admin'),
    'cancelled' => bk_t('bk_cancel_success', 'Appointment cancelled. The client has been notified.', 'admin'),
    'deleted'   => '',
];

$appointments = bk_load_all_appointments();

// Requests only ever shows upcoming appointments — past ones (regardless
// of status) belong in the History view instead, where confirm/refuse/
// cancel actions no longer make sense.
$appointments = array_filter($appointments, fn($a) => !bk_appointment_is_past($a));

// Newest first.
usort($appointments, function ($a, $b) {
    return strcmp($b['date'] . $b['start_time'], $a['date'] . $a['start_time']);
});

if ($filterDate !== '') {
    $appointments = array_filter($appointments, fn($a) => $a['date'] === $filterDate);
}
if ($filterStatus !== '') {
    $appointments = array_filter($appointments, fn($a) => $a['status'] === $filterStatus);
}

$statusLabels = [
    'pending'   => bk_t('bk_status_pending', 'Pending', 'admin'),
    'confirmed' => bk_t('bk_status_confirmed', 'Confirmed', 'admin'),
    'cancelled' => bk_t('bk_status_cancelled', 'Cancelled', 'admin'),
    'refused'   => bk_t('bk_status_refused', 'Refused', 'admin'),
];

// actions.php redirects back here after each POST — give it the return URL
// for the current filter state so the redirect lands on the right filter.
$returnUrl = bk_admin_page_url('requests', array_filter(['date' => $filterDate, 'status' => $filterStatus]));
?>
<?php if ($msg !== '' && isset($msgMap[$msg]) && $msgMap[$msg] !== ''): ?>
    <div class="message success"><?php echo hsc($msgMap[$msg]); ?></div>
<?php endif; ?>

<div class="bk-filters">
    <a href="<?php echo hsc(bk_admin_page_url('requests')); ?>" class="<?php echo $filterStatus === '' ? 'active' : ''; ?>">All</a>
    <?php foreach ($statusLabels as $key => $label): ?>
        <a href="<?php echo hsc(bk_admin_page_url('requests', ['status' => $key])); ?>" class="<?php echo $filterStatus === $key ? 'active' : ''; ?>"><?php echo hsc($label); ?></a>
    <?php endforeach; ?>
    <?php if ($filterDate !== ''): ?>
        <span class="bk-filter-date"><?php echo hsc($filterDate); ?> <a href="<?php echo hsc(bk_admin_page_url('requests')); ?>">&times;</a></span>
    <?php endif; ?>
</div>

<?php if (empty($appointments)): ?>
    <p class="bk-empty"><?php echo hsc(bk_t('bk_no_requests', 'No appointment requests yet.', 'admin')); ?></p>
<?php else: ?>
<table class="bk-table">
    <thead>
        <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Client</th>
            <th>Contact</th>
            <th>Notes</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($appointments as $a):
            $type = bk_get_appointment_type($a['type']);
        ?>
        <tr>
            <td>
                <?php echo hsc(function_exists('format_date') ? format_date($a['date']) : bk_format_date_fallback($a['date'])); ?><br>
                <span class="bk-time"><?php echo hsc($a['start_time'] . '–' . $a['end_time']); ?></span>
            </td>
            <td><?php echo hsc($type['label'] ?? $a['type']); ?></td>
            <td><?php echo hsc($a['first_name'] . ' ' . $a['last_name']); ?></td>
            <td>
                <a href="mailto:<?php echo hsc($a['email']); ?>"><?php echo hsc($a['email']); ?></a><br>
                <?php echo hsc($a['phone']); ?>
                <?php if (!empty($a['ip'])): ?>
                    <br><span class="bk-ip-tag"><?php echo hsc($a['ip']); ?></span>
                <?php endif; ?>
                <?php if (!empty($a['callback_requested'])): ?>
                    <br><span class="bk-callback-tag" title="<?php echo hsc(str_replace(['{date}', '{time}'], [$a['callback_date'], $a['callback_time']], bk_t('bk_callback_on', 'Callback requested for {date} at {time}', 'admin'))); ?>">
                        📞 <?php echo hsc(bk_t('bk_callback_requested', 'Callback requested', 'admin')); ?>
                    </span>
                <?php endif; ?>
            </td>
            <td class="bk-notes-cell">
                <?php if (!empty($a['notes'])): ?>
                    <span class="bk-notes-preview" title="<?php echo hsc($a['notes']); ?>"><?php echo hsc($a['notes']); ?></span>
                <?php else: ?>
                    <span class="bk-notes-empty">—</span>
                <?php endif; ?>
            </td>
            <td><span class="bk-status bk-status--<?php echo hsc($a['status']); ?>"><?php echo hsc($statusLabels[$a['status']] ?? $a['status']); ?></span></td>
            <td class="bk-row-actions">
                <?php if ($a['status'] === 'pending'): ?>
                    <form method="post" action="<?php echo hsc($actionsUrl); ?>" class="bk-inline-form">
                        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                        <input type="hidden" name="id" value="<?php echo hsc($a['id']); ?>">
                        <input type="hidden" name="return_url" value="<?php echo hsc($returnUrl); ?>">
                        <input type="hidden" name="action" value="confirm_appointment">
                        <button type="submit" class="bk-btn bk-btn--confirm"><?php echo hsc(bk_t('bk_action_confirm', 'Confirm', 'admin')); ?></button>
                    </form>
                    <form method="post" action="<?php echo hsc($actionsUrl); ?>" class="bk-inline-form">
                        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                        <input type="hidden" name="id" value="<?php echo hsc($a['id']); ?>">
                        <input type="hidden" name="return_url" value="<?php echo hsc($returnUrl); ?>">
                        <input type="hidden" name="action" value="refuse_appointment">
                        <button type="submit" class="bk-btn bk-btn--refuse"><?php echo hsc(bk_t('bk_action_refuse', 'Refuse', 'admin')); ?></button>
                    </form>
                <?php elseif ($a['status'] === 'confirmed'): ?>
                    <form method="post" action="<?php echo hsc($actionsUrl); ?>" class="bk-inline-form">
                        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                        <input type="hidden" name="id" value="<?php echo hsc($a['id']); ?>">
                        <input type="hidden" name="return_url" value="<?php echo hsc($returnUrl); ?>">
                        <input type="hidden" name="action" value="cancel_appointment">
                        <button type="submit" class="bk-btn bk-btn--refuse"><?php echo hsc(bk_t('bk_action_cancel', 'Cancel', 'admin')); ?></button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?php echo hsc($actionsUrl); ?>" class="bk-inline-form" data-confirm="<?php echo hsc(bk_t('bk_delete_confirm', 'Permanently delete this appointment?', 'admin'), ENT_QUOTES); ?>">
                        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                        <input type="hidden" name="id" value="<?php echo hsc($a['id']); ?>">
                        <input type="hidden" name="return_url" value="<?php echo hsc($returnUrl); ?>">
                        <input type="hidden" name="action" value="delete_appointment">
                        <button type="submit" class="bk-btn bk-btn--delete"><?php echo hsc(bk_t('bk_action_delete', 'Delete', 'admin')); ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
