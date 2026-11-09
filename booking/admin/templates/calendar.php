<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Calendar view.
 * Simple month grid showing appointment counts per day (pending/confirmed),
 * with quick links into the filtered requests list.
 */

$monthParam = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}

$monthStart = strtotime($monthParam . '-01');
$daysInMonth = (int)date('t', $monthStart);
$firstWeekday = (int)date('N', $monthStart); // 1 = Monday

// PHP's date('F') always returns English month names regardless of locale —
// build the label from the plugin's own i18n instead so it follows the
// admin's active language like the day names below already do.
$_bkMonthNames = [
    1 => bk_t('bk_month_1', 'January', 'admin'), 2 => bk_t('bk_month_2', 'February', 'admin'),
    3 => bk_t('bk_month_3', 'March', 'admin'), 4 => bk_t('bk_month_4', 'April', 'admin'),
    5 => bk_t('bk_month_5', 'May', 'admin'), 6 => bk_t('bk_month_6', 'June', 'admin'),
    7 => bk_t('bk_month_7', 'July', 'admin'), 8 => bk_t('bk_month_8', 'August', 'admin'),
    9 => bk_t('bk_month_9', 'September', 'admin'), 10 => bk_t('bk_month_10', 'October', 'admin'),
    11 => bk_t('bk_month_11', 'November', 'admin'), 12 => bk_t('bk_month_12', 'December', 'admin'),
];
$monthLabel = $_bkMonthNames[(int)date('n', $monthStart)] . ' ' . date('Y', $monthStart);

$prevMonth = date('Y-m', strtotime('-1 month', $monthStart));
$nextMonth = date('Y-m', strtotime('+1 month', $monthStart));

// Group index entries by date for this month.
$index = bk_load_index();
$byDate = [];
foreach ($index as $entry) {
    if (strpos($entry['date'], $monthParam) === 0 && in_array($entry['status'], ['pending', 'confirmed'], true)) {
        $byDate[$entry['date']][] = $entry;
    }
}
?>
<div class="bk-calendar-admin">
    <div class="bk-month-nav">
        <a href="<?php echo hsc(bk_admin_page_url('calendar', ['month' => $prevMonth])); ?>">&larr;</a>
        <strong><?php echo hsc($monthLabel); ?></strong>
        <a href="<?php echo hsc(bk_admin_page_url('calendar', ['month' => $nextMonth])); ?>">&rarr;</a>
    </div>
    <div class="bk-month-grid">
        <?php
        $dayLabels = [
            bk_t('bk_day_mon', 'Mon', 'admin'), bk_t('bk_day_tue', 'Tue', 'admin'), bk_t('bk_day_wed', 'Wed', 'admin'),
            bk_t('bk_day_thu', 'Thu', 'admin'), bk_t('bk_day_fri', 'Fri', 'admin'), bk_t('bk_day_sat', 'Sat', 'admin'), bk_t('bk_day_sun', 'Sun', 'admin'),
        ];
        foreach ($dayLabels as $label): ?>
            <div class="bk-month-dayname"><?php echo hsc($label); ?></div>
        <?php endforeach; ?>

        <?php for ($i = 1; $i < $firstWeekday; $i++): ?>
            <div class="bk-month-cell bk-month-cell--empty"></div>
        <?php endfor; ?>

        <?php for ($day = 1; $day <= $daysInMonth; $day++):
            $date = $monthParam . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
            $entries = $byDate[$date] ?? [];
            $pendingCount = count(array_filter($entries, fn($e) => $e['status'] === 'pending'));
            $confirmedCount = count(array_filter($entries, fn($e) => $e['status'] === 'confirmed'));
        ?>
            <div class="bk-month-cell">
                <span class="bk-month-daynum"><?php echo $day; ?></span>
                <?php if ($pendingCount > 0): ?>
                    <a class="bk-badge bk-badge--pending" href="<?php echo hsc(bk_admin_page_url('requests', ['date' => $date])); ?>"><?php echo $pendingCount; ?></a>
                <?php endif; ?>
                <?php if ($confirmedCount > 0): ?>
                    <a class="bk-badge bk-badge--confirmed" href="<?php echo hsc(bk_admin_page_url('requests', ['date' => $date])); ?>"><?php echo $confirmedCount; ?></a>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
    <div class="bk-legend">
        <span class="bk-badge bk-badge--pending">•</span> <?php echo hsc(bk_t('bk_status_pending', 'Pending', 'admin')); ?>
        <span class="bk-badge bk-badge--confirmed">•</span> <?php echo hsc(bk_t('bk_status_confirmed', 'Confirmed', 'admin')); ?>
    </div>
</div>
