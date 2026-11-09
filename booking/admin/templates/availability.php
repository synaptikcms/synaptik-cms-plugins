<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Availability view.
 * Weekly recurring hours per day (with an optional daily lunch break) plus
 * a list of date-exception rules — each covering a single date or a date
 * range, either fully closed or with custom hours for that period.
 */

$csrf = bk_generate_csrf();

$msg = $_GET['msg'] ?? '';

// A blocked save (see admin/actions.php's save_availability) stashes both
// the list of conflicting appointments and the not-yet-saved weekly/
// exceptions data in the session, so the form can be redisplayed exactly
// as the admin left it — they only need to remove the offending row(s) and
// resubmit, instead of re-entering everything. Read once and cleared here
// regardless of $msg, so a normal page visit afterwards doesn't keep
// re-showing stale conflict data.
$conflicts = $_SESSION['bk_availability_conflicts'] ?? [];
$pendingWeekly = $_SESSION['bk_availability_pending_weekly'] ?? null;
$pendingExceptions = $_SESSION['bk_availability_pending_exceptions'] ?? null;
unset($_SESSION['bk_availability_conflicts'], $_SESSION['bk_availability_pending_weekly'], $_SESSION['bk_availability_pending_exceptions']);

$availability = bk_load_availability();
if ($msg === 'conflict' && $pendingWeekly !== null) {
    $availability['weekly']     = $pendingWeekly;
    $availability['exceptions'] = $pendingExceptions ?? [];
}

$dayLabels = [
    '1' => bk_t('bk_day_mon', 'Monday', 'admin'),
    '2' => bk_t('bk_day_tue', 'Tuesday', 'admin'),
    '3' => bk_t('bk_day_wed', 'Wednesday', 'admin'),
    '4' => bk_t('bk_day_thu', 'Thursday', 'admin'),
    '5' => bk_t('bk_day_fri', 'Friday', 'admin'),
    '6' => bk_t('bk_day_sat', 'Saturday', 'admin'),
    '7' => bk_t('bk_day_sun', 'Sunday', 'admin'),
];

$statusLabels = [
    'pending'   => bk_t('bk_status_pending', 'Pending', 'admin'),
    'confirmed' => bk_t('bk_status_confirmed', 'Confirmed', 'admin'),
];

// Exceptions are stored keyed by a single date internally (bk_available_slots()
// checks one date at a time), but a range entered by the admin (e.g. a week of
// holidays) is expanded to one stored key per date at save time — see
// admin/actions.php. To edit a previously-saved range as a single row again,
// consecutive dates sharing identical closed/start/end values are collapsed
// back into one range row here.
$exceptionRows = [];
$sortedDates = array_keys($availability['exceptions']);
sort($sortedDates);

$prevDate = null;
$rangeStart = null;
foreach ($sortedDates as $date) {
    $entry = $availability['exceptions'][$date];
    $isContiguous = $prevDate !== null
        && strtotime($date) === strtotime($prevDate . ' +1 day')
        && $entry == $availability['exceptions'][$prevDate];

    if (!$isContiguous) {
        if ($rangeStart !== null) {
            $exceptionRows[] = ['start' => $rangeStart, 'end' => $prevDate, 'entry' => $availability['exceptions'][$rangeStart]];
        }
        $rangeStart = $date;
    }
    $prevDate = $date;
}
if ($rangeStart !== null) {
    $exceptionRows[] = ['start' => $rangeStart, 'end' => $prevDate, 'entry' => $availability['exceptions'][$rangeStart]];
}
?>
<?php if ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(bk_t('bk_availability_saved', 'Availability saved.', 'admin')); ?></div>
<?php endif; ?>

<?php if ($msg === 'conflict' && !empty($conflicts)): ?>
    <div class="bk-conflict-warning">
        <h2><?php echo hsc(bk_t('bk_availability_conflict_title', 'Availability not saved — existing appointments conflict', 'admin')); ?></h2>
        <p><?php echo hsc(bk_t('bk_availability_conflict_intro', 'The hours you tried to save would leave the following appointment(s) outside your availability. Refuse or cancel them from the Requests tab, then save availability again — nothing has been changed yet.', 'admin')); ?></p>
        <table class="bk-table">
            <thead>
                <tr>
                    <th><?php echo hsc(bk_t('bk_availability_conflict_col_date', 'Date', 'admin')); ?></th>
                    <th><?php echo hsc(bk_t('bk_settings_type_label', 'Label', 'admin')); ?></th>
                    <th><?php echo hsc(bk_t('bk_availability_conflict_col_client', 'Client', 'admin')); ?></th>
                    <th><?php echo hsc(bk_t('bk_availability_conflict_col_status', 'Status', 'admin')); ?></th>
                    <th><?php echo hsc(bk_t('bk_availability_conflict_reason', 'Conflict', 'admin')); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($conflicts as $conflict):
                    $a = $conflict['appointment'];
                    $type = bk_get_appointment_type($a['type']);
                    $reasonLabel = $conflict['reason'] === 'closed'
                        ? bk_t('bk_availability_conflict_closed', 'Falls on a day now marked closed', 'admin')
                        : bk_t('bk_availability_conflict_outside_hours', 'Falls outside the new hours', 'admin');
                ?>
                <tr>
                    <td>
                        <?php echo hsc($a['date']); ?><br>
                        <span class="bk-time"><?php echo hsc($a['start_time'] . '–' . $a['end_time']); ?></span>
                    </td>
                    <td><?php echo hsc($type['label'] ?? $a['type']); ?></td>
                    <td><?php echo hsc($a['first_name'] . ' ' . $a['last_name']); ?></td>
                    <td><span class="bk-status bk-status--<?php echo hsc($a['status']); ?>"><?php echo hsc($statusLabels[$a['status']] ?? $a['status']); ?></span></td>
                    <td><?php echo hsc($reasonLabel); ?></td>
                    <td><a class="bk-btn bk-btn--primary" href="<?php echo hsc(bk_admin_page_url('requests', ['date' => $a['date']])); ?>"><?php echo hsc(bk_t('bk_availability_conflict_view', 'View', 'admin')); ?></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<form method="post" action="<?php echo hsc(bk_admin_actions_url()); ?>" class="bk-settings-form">
    <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
    <input type="hidden" name="return_url" value="<?php echo hsc(bk_admin_page_url('availability')); ?>">
    <input type="hidden" name="action" value="save_availability">

    <h2><?php echo hsc(bk_t('bk_availability_weekly_title', 'Weekly hours', 'admin')); ?></h2>
    <table class="bk-table bk-table--compact" id="bk-weekly-table">
        <thead>
            <tr>
                <th></th>
                <th><?php echo hsc(bk_t('bk_availability_day_enabled', 'Open', 'admin')); ?></th>
                <th><?php echo hsc(bk_t('bk_availability_from', 'From', 'admin')); ?></th>
                <th><?php echo hsc(bk_t('bk_availability_to', 'To', 'admin')); ?></th>
                <th><?php echo hsc(bk_t('bk_availability_lunch_break', 'Lunch break', 'admin')); ?></th>
                <th><?php echo hsc(bk_t('bk_availability_from', 'From', 'admin')); ?></th>
                <th><?php echo hsc(bk_t('bk_availability_to', 'To', 'admin')); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($dayLabels as $dayNum => $label):
            $rule = $availability['weekly'][$dayNum] ?? ['enabled' => false, 'start' => '09:00', 'end' => '18:00', 'lunch_enabled' => false, 'lunch_start' => '12:00', 'lunch_end' => '13:00'];
        ?>
            <tr>
                <td><?php echo hsc($label); ?></td>
                <td><input type="checkbox" name="weekly[<?php echo $dayNum; ?>][enabled]" <?php echo !empty($rule['enabled']) ? 'checked' : ''; ?>></td>
                <td><input type="time" name="weekly[<?php echo $dayNum; ?>][start]" value="<?php echo hsc($rule['start']); ?>"></td>
                <td><input type="time" name="weekly[<?php echo $dayNum; ?>][end]" value="<?php echo hsc($rule['end']); ?>"></td>
                <td class="bk-lunch-toggle-cell"><input type="checkbox" name="weekly[<?php echo $dayNum; ?>][lunch_enabled]" class="bk-lunch-toggle" <?php echo !empty($rule['lunch_enabled']) ? 'checked' : ''; ?>></td>
                <td><input type="time" name="weekly[<?php echo $dayNum; ?>][lunch_start]" value="<?php echo hsc($rule['lunch_start'] ?? '12:00'); ?>" <?php echo empty($rule['lunch_enabled']) ? 'readonly' : ''; ?>></td>
                <td><input type="time" name="weekly[<?php echo $dayNum; ?>][lunch_end]" value="<?php echo hsc($rule['lunch_end'] ?? '13:00'); ?>" <?php echo empty($rule['lunch_enabled']) ? 'readonly' : ''; ?>></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="bk-field-help"><?php echo hsc(bk_t('bk_availability_lunch_break_help', 'When enabled, no appointment can be booked during this window, even if it only partially overlaps.', 'admin')); ?></p>

    <h2><?php echo hsc(bk_t('bk_availability_exceptions_title', 'Date exceptions', 'admin')); ?></h2>
    <p class="bk-field-help"><?php echo hsc(bk_t('bk_availability_exceptions_help', "Override the weekly hours for one or more consecutive dates — holidays, for example. Check 'Closed all day' to close entirely, or set custom hours that replace the normal schedule for those dates.", 'admin')); ?></p>
    <p class="bk-field-help"><?php echo hsc(bk_t('bk_availability_exception_hours_help', "These hours replace the day's normal schedule entirely — they are not a closure window within it.", 'admin')); ?></p>
    <div id="bk-exceptions-list">
        <?php foreach ($exceptionRows as $row):
            $exc = $row['entry'];
            $isClosed = !empty($exc['closed']);
        ?>
        <div class="bk-exception-row">
            <input type="date" name="exception_start_date[]" value="<?php echo hsc($row['start']); ?>" class="bk-exc-start">
            <span class="bk-exc-range-sep">&rarr;</span>
            <input type="date" name="exception_end_date[]" value="<?php echo hsc($row['end']); ?>" class="bk-exc-end">
            <!--
                exception_closed_flag[] is a hidden field always submitted in
                lockstep with the visible checkbox below (kept in sync by the
                'change' listener), guaranteeing one value per row regardless
                of checked state — the server never has to infer a row's
                checked status from whether a same-named field happened to be
                present, which is what desynced after row deletion before.

                The time fields below use readonly (not disabled) when the
                row is closed: a disabled input is excluded from form
                submission entirely, which silently shifted every later
                row's start/end time by one position whenever an earlier
                "closed all day" row preceded it — readonly still blocks
                editing but keeps the field (and its position) in the
                submitted data.
            -->
            <input type="hidden" name="exception_closed_flag[]" value="<?php echo $isClosed ? '1' : '0'; ?>" class="bk-exc-closed-flag">
            <label><input type="checkbox" class="bk-exc-closed" <?php echo $isClosed ? 'checked' : ''; ?>> <?php echo hsc(bk_t('bk_availability_exception_closed', 'Closed all day', 'admin')); ?></label>
            <input type="time" name="exception_start_time[]" value="<?php echo hsc($exc['start'] ?? ''); ?>" <?php echo $isClosed ? 'readonly' : ''; ?>>
            <input type="time" name="exception_end_time[]" value="<?php echo hsc($exc['end'] ?? ''); ?>" <?php echo $isClosed ? 'readonly' : ''; ?>>
            <button type="button" class="bk-btn bk-btn--delete bk-remove-row">&times;</button>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" id="bk-add-exception" class="bk-btn"><?php echo hsc(bk_t('bk_availability_add_exception', 'Add exception', 'admin')); ?></button>

    <div class="bk-form-actions">
        <button type="submit" class="bk-btn bk-btn--primary"><?php echo hsc(bk_t('bk_availability_save', 'Save availability', 'admin')); ?></button>
    </div>
</form>
<?php
$availabilityData = ['closedAllDay' => bk_t('bk_availability_exception_closed', 'Closed all day', 'admin')];
$availabilityJsV  = @filemtime(__DIR__ . '/../../assets/js/availability.js') ?: time();
?>
<script type="application/json" id="bk-availability-data"><?php echo json_encode($availabilityData); ?></script>
<script src="<?php echo hsc(bk_plugin_base_url() . 'assets/js/availability.js'); ?>?v=<?php echo $availabilityJsV; ?>"></script>
