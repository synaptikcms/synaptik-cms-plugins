<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — History view.
 * Read-only list of past appointments (any status), for record-keeping.
 * No confirm/refuse/cancel actions here — those only make sense for
 * upcoming appointments, handled in the Requests view instead.
 */

$filterStatus = trim($_GET['status'] ?? '');

$appointments = bk_load_all_appointments();
$appointments = array_filter($appointments, fn($a) => bk_appointment_is_past($a));

if ($filterStatus !== '') {
    $appointments = array_filter($appointments, fn($a) => $a['status'] === $filterStatus);
}

// Most recent first.
usort($appointments, function ($a, $b) {
    return strcmp($b['date'] . $b['start_time'], $a['date'] . $a['start_time']);
});

$statusLabels = [
    'pending'   => bk_t('bk_status_pending', 'Pending', 'admin'),
    'confirmed' => bk_t('bk_status_confirmed', 'Confirmed', 'admin'),
    'cancelled' => bk_t('bk_status_cancelled', 'Cancelled', 'admin'),
    'refused'   => bk_t('bk_status_refused', 'Refused', 'admin'),
];
?>

<div class="bk-filters">
    <a href="<?php echo hsc(bk_admin_page_url('history')); ?>" class="<?php echo $filterStatus === '' ? 'active' : ''; ?>">All</a>
    <?php foreach ($statusLabels as $key => $label): ?>
        <a href="<?php echo hsc(bk_admin_page_url('history', ['status' => $key])); ?>" class="<?php echo $filterStatus === $key ? 'active' : ''; ?>"><?php echo hsc($label); ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($appointments)): ?>
    <p class="bk-empty"><?php echo hsc(bk_t('bk_no_history', 'No past appointments yet.', 'admin')); ?></p>
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
            </td>
            <td class="bk-notes-cell">
                <?php if (!empty($a['notes'])): ?>
                    <span class="bk-notes-preview" title="<?php echo hsc($a['notes']); ?>"><?php echo hsc($a['notes']); ?></span>
                <?php else: ?>
                    <span class="bk-notes-empty">—</span>
                <?php endif; ?>
            </td>
            <td><span class="bk-status bk-status--<?php echo hsc($a['status']); ?>"><?php echo hsc($statusLabels[$a['status']] ?? $a['status']); ?></span></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
