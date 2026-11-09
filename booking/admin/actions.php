<?php
/**
 * Booking Plugin — Admin Actions
 *
 * Handles all state-changing admin operations. Every action requires the
 * core admin session and a valid CSRF token, then redirects back to the
 * page the form was submitted from (return_url), with a status flag
 * appended. Never renders HTML itself — POST-only endpoint.
 */

require_once __DIR__ . '/bootstrap.php';
bk_admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . bk_admin_page_url('calendar'));
    exit;
}

if (!bk_verify_csrf(trim($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

/**
 * Resolves a safe redirect target from the submitted return_url, appending
 * a status flag. Only accepts a same-host URL pointing at admin/index.php
 * (with action=plugin_page) — anything else (different host, unexpected
 * path) falls back to the default requests view, closing off open-redirect
 * abuse via a tampered return_url field.
 */
function bk_safe_redirect(string $defaultView, array $params = []): void
{
    $fallback = bk_admin_page_url($defaultView, $params);
    $return   = $_POST['return_url'] ?? '';

    if ($return !== '') {
        $parsed      = parse_url($return);
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';

        $isSameHost = $parsed !== false
            && isset($parsed['path'])
            && (empty($parsed['host']) || strcasecmp($parsed['host'], $currentHost) === 0)
            && basename($parsed['path']) === 'index.php';

        if ($isSameHost) {
            parse_str($parsed['query'] ?? '', $query);
            $query = array_merge($query, $params);

            $scheme = $parsed['scheme'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http');
            $host   = $parsed['host'] ?? $currentHost;

            // Reconstruct as an absolute URL whenever a host was present in
            // the submitted value, to avoid the exact relative-path bug this
            // replaced (a bare path here would resolve against actions.php's
            // own location under /plugins/booking/admin/, not /admin/).
            $fallback = (!empty($parsed['host']) ? $scheme . '://' . $host : '') . $parsed['path'] . '?' . http_build_query($query);
        }
    }

    header('Location: ' . $fallback);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'confirm_appointment':
        $id = trim($_POST['id'] ?? '');
        $appointment = bk_load_appointment($id);
        if ($appointment !== null && $appointment['status'] === 'pending') {
            bk_update_appointment($id, ['status' => 'confirmed']);
            bk_notify_client_confirmed(array_merge($appointment, ['status' => 'confirmed']));
        }
        bk_safe_redirect('requests', ['msg' => 'confirmed']);

    case 'refuse_appointment':
        $id = trim($_POST['id'] ?? '');
        $appointment = bk_load_appointment($id);
        if ($appointment !== null && $appointment['status'] === 'pending') {
            bk_update_appointment($id, ['status' => 'refused']);
            bk_notify_client_refused($appointment);
        }
        bk_safe_redirect('requests', ['msg' => 'refused']);

    case 'cancel_appointment':
        $id = trim($_POST['id'] ?? '');
        $appointment = bk_load_appointment($id);
        if ($appointment !== null && $appointment['status'] === 'confirmed') {
            bk_update_appointment($id, ['status' => 'cancelled']);
            bk_notify_client_cancelled($appointment);
        }
        bk_safe_redirect('requests', ['msg' => 'cancelled']);

    case 'delete_appointment':
        $id = trim($_POST['id'] ?? '');
        bk_delete_appointment($id);
        bk_safe_redirect('requests', ['msg' => 'deleted']);

    case 'save_availability':
        $weekly = [];
        for ($day = 1; $day <= 7; $day++) {
            $lunchEnabled = !empty($_POST['weekly'][$day]['lunch_enabled']);
            $lunchStart   = preg_match('/^\d{2}:\d{2}$/', $_POST['weekly'][$day]['lunch_start'] ?? '') ? $_POST['weekly'][$day]['lunch_start'] : '12:00';
            $lunchEnd     = preg_match('/^\d{2}:\d{2}$/', $_POST['weekly'][$day]['lunch_end'] ?? '') ? $_POST['weekly'][$day]['lunch_end'] : '13:00';

            // A lunch break with end <= start would silently block the rest
            // of the day (every slot from lunch_start onward "overlaps") —
            // rather than let that happen from a fat-fingered form, disable
            // it server-side and let the admin see it's off when they reload.
            if ($lunchEnabled && strtotime($lunchStart) >= strtotime($lunchEnd)) {
                $lunchEnabled = false;
            }

            $weekly[(string)$day] = [
                'enabled'       => !empty($_POST['weekly'][$day]['enabled']),
                'start'         => preg_match('/^\d{2}:\d{2}$/', $_POST['weekly'][$day]['start'] ?? '') ? $_POST['weekly'][$day]['start'] : '09:00',
                'end'           => preg_match('/^\d{2}:\d{2}$/', $_POST['weekly'][$day]['end'] ?? '') ? $_POST['weekly'][$day]['end'] : '18:00',
                'lunch_enabled' => $lunchEnabled,
                'lunch_start'   => $lunchStart,
                'lunch_end'     => $lunchEnd,
            ];
        }

        // Exception rows: each row covers a date range (single day = start
        // === end). Every array here is populated once per row in the same
        // DOM order, including exception_closed_flag[] — a hidden field the
        // template always submits alongside the visible checkbox — so row
        // $i in every array refers to the same row even after rows have
        // been added or removed client-side. The previous version indexed
        // the "closed" checkbox by a fixed position number baked in at
        // render time (exception_closed[$i]), which desynced from the date
        // fields as soon as a row was deleted: the remaining rows kept
        // their original position numbers, no longer matching the shifted
        // sequential date arrays.
        $exceptions  = [];
        $rangeStarts = $_POST['exception_start_date']  ?? [];
        $rangeEnds   = $_POST['exception_end_date']    ?? [];
        $closedFlags = $_POST['exception_closed_flag'] ?? [];
        $rangeTimesS = $_POST['exception_start_time']  ?? [];
        $rangeTimesE = $_POST['exception_end_time']    ?? [];

        $rowCount = count($rangeStarts);

        for ($i = 0; $i < $rowCount; $i++) {
            $startDate = trim($rangeStarts[$i] ?? '');
            $endDate   = trim($rangeEnds[$i] ?? $startDate);

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   continue;

            $startTs = strtotime($startDate);
            $endTs   = strtotime($endDate);
            if ($startTs === false || $endTs === false) continue;
            if ($endTs < $startTs) { [$startTs, $endTs] = [$endTs, $startTs]; }

            // Cap any single range at one year — a fat-fingered end date
            // (wrong century, swapped day/month) shouldn't be able to write
            // tens of thousands of exception entries to disk.
            if (($endTs - $startTs) > 366 * 86400) {
                $endTs = $startTs + (366 * 86400);
            }

            $isClosed = ($closedFlags[$i] ?? '0') === '1';
            $entry = ['closed' => $isClosed];
            if (!$isClosed) {
                $s = $rangeTimesS[$i] ?? '';
                $e = $rangeTimesE[$i] ?? '';
                if (preg_match('/^\d{2}:\d{2}$/', $s) && preg_match('/^\d{2}:\d{2}$/', $e)) {
                    $entry['start'] = $s;
                    $entry['end']   = $e;
                }
            }

            for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
                $exceptions[date('Y-m-d', $ts)] = $entry;
            }
        }

        // Block the save entirely if it would strand an existing
        // pending/confirmed appointment outside the new hours — see
        // bk_find_appointments_conflicting_with_availability(). The admin
        // must refuse/cancel the conflicting appointment(s) from the
        // Requests tab first; there is no bypass, since silently saving
        // over an active booking is exactly the failure mode this guards
        // against.
        $conflicts = bk_find_appointments_conflicting_with_availability($weekly, $exceptions);
        if (!empty($conflicts)) {
            // Stashed in the session rather than passed through the URL —
            // there can be many conflicts, each with a full appointment
            // record, which wouldn't fit in a query string. Read once and
            // cleared by templates/availability.php. The submitted (but not
            // yet saved) weekly/exceptions are stashed alongside so the
            // admin doesn't have to re-enter everything — only remove the
            // conflicting row(s) and resubmit.
            $_SESSION['bk_availability_conflicts']       = $conflicts;
            $_SESSION['bk_availability_pending_weekly']    = $weekly;
            $_SESSION['bk_availability_pending_exceptions'] = $exceptions;
            bk_safe_redirect('availability', ['msg' => 'conflict']);
        }

        bk_save_availability(['weekly' => $weekly, 'exceptions' => $exceptions]);
        bk_safe_redirect('availability', ['msg' => 'saved']);

    case 'save_settings':
        $settings = bk_load_settings();

        $adminEmail = trim($_POST['admin_email'] ?? '');
        if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $settings['admin_email'] = $adminEmail;
        }

        // Sender address/name — both optional, blank means "use the
        // noreply@{host} / site title fallback" (see bk_from_address()).
        $fromEmail = trim($_POST['from_email'] ?? '');
        $settings['from_email'] = ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) ? $fromEmail : '';
        $settings['from_name']  = trim(strip_tags($_POST['from_name'] ?? ''));

        $settings['slot_interval']    = max(5, (int)($_POST['slot_interval'] ?? 15));
        $settings['min_notice_hours'] = max(0, (int)($_POST['min_notice_hours'] ?? 24));
        $settings['max_advance_days'] = max(1, (int)($_POST['max_advance_days'] ?? 60));
        $settings['restrict_callback_to_availability'] = !empty($_POST['restrict_callback_to_availability']);
        $settings['enable_callback'] = !empty($_POST['enable_callback']);
        $settings['send_pending_email'] = !empty($_POST['send_pending_email']);
        $settings['show_dashboard_widget'] = !empty($_POST['show_dashboard_widget']);
        $settings['show_cancel_link_in_email'] = !empty($_POST['show_cancel_link_in_email']);

        $settings['client_cancel_min_notice_hours'] = max(0, (int)($_POST['client_cancel_min_notice_hours'] ?? 48));

        $slotDisplayMode = trim($_POST['slot_display_mode'] ?? 'hide');
        $settings['slot_display_mode'] = $slotDisplayMode === 'show_disabled' ? 'show_disabled' : 'hide';

        // .ics calendar invite templates — plain strings with {placeholder}
        // tokens substituted at send time (bk_render_template()). Free-text
        // fields the admin controls entirely, same trust level as the email
        // templates below.
        $settings['ics_summary_template']     = trim(strip_tags($_POST['ics_summary_template'] ?? ''));
        $settings['ics_description_template'] = trim(strip_tags($_POST['ics_description_template'] ?? ''));
        $settings['ics_location']              = trim(strip_tags($_POST['ics_location'] ?? ''));
        $icsUrl = trim($_POST['ics_url'] ?? '');
        // Accept either a real URL or a string containing at least one
        // {placeholder} token (resolved to a URL only at send time) —
        // FILTER_VALIDATE_URL would otherwise reject the raw "{type}"-style
        // tokens before substitution.
        $settings['ics_url'] = ($icsUrl !== '' && (filter_var($icsUrl, FILTER_VALIDATE_URL) || preg_match('/\{[a-z_]+\}/', $icsUrl))) ? $icsUrl : '';

        $types = [];
        $typeKeys      = $_POST['type_key']      ?? [];
        $typeLabels    = $_POST['type_label']    ?? [];
        $typeDurations = $_POST['type_duration'] ?? [];

        foreach ($typeKeys as $i => $key) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($key)));
            $label = trim(strip_tags($typeLabels[$i] ?? ''));
            $duration = max(5, (int)($typeDurations[$i] ?? 30));

            if ($key === '' || $label === '') continue;

            $types[$key] = ['label' => $label, 'duration' => $duration];
        }

        if (!empty($types)) {
            $settings['appointment_types'] = $types;
        }

        $submittedFields = [];
        foreach (bk_form_field_keys() as $fieldKey) {
            $fieldEnabled = !empty($_POST['ff_' . $fieldKey . '_enabled']);
            $submittedFields[$fieldKey] = [
                'enabled'     => $fieldEnabled,
                'required'    => $fieldEnabled && !empty($_POST['ff_' . $fieldKey . '_required']),
                'label'       => mb_substr(trim(strip_tags($_POST['ff_' . $fieldKey . '_label'] ?? '')), 0, 200),
                'placeholder' => mb_substr(trim(strip_tags($_POST['ff_' . $fieldKey . '_placeholder'] ?? '')), 0, 200),
            ];
        }
        $settings['form_fields'] = $submittedFields;

        bk_save_settings($settings);
        bk_safe_redirect('settings', ['msg' => 'saved']);

    case 'save_smtp_settings':
        $settings = bk_load_settings();

        $smtp = $settings['smtp'];
        $smtp['enabled']  = !empty($_POST['smtp_enabled']);
        $smtp['host']     = trim(strip_tags($_POST['smtp_host'] ?? ''));
        $smtp['port']     = max(1, min(65535, (int)($_POST['smtp_port'] ?? 587)));
        $smtp['auth']     = !empty($_POST['smtp_auth']);
        $smtp['username'] = trim(strip_tags($_POST['smtp_username'] ?? ''));

        $encryption = trim($_POST['smtp_encryption'] ?? 'tls');
        $smtp['encryption'] = in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls';

        $settings['smtp'] = $smtp;
        bk_save_settings($settings);

        // Password field is submitted empty to mean "keep the existing
        // one" (see templates/settings-smtp.php) — only overwrite the
        // stored password when the admin actually typed something.
        $submittedPassword = $_POST['smtp_password'] ?? '';
        if ($submittedPassword !== '') {
            bk_save_smtp_password($submittedPassword);
        }

        bk_safe_redirect('smtp', ['msg' => 'saved']);

    case 'smtp_preset_brevo':
        $settings = bk_load_settings();
        $settings['smtp'] = array_merge($settings['smtp'], [
            'enabled'    => true,
            'host'       => 'smtp-relay.brevo.com',
            'port'       => 587,
            'encryption' => 'tls',
            'auth'       => true,
        ]);
        bk_save_settings($settings);
        bk_safe_redirect('smtp', ['msg' => 'brevo_applied']);

    case 'send_smtp_test':
        $testEmail = trim($_POST['test_email'] ?? '');
        if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            bk_safe_redirect('smtp', ['msg' => 'test_failed']);
        }

        // Goes through the exact same bk_send_plain_mail() every real
        // notification uses — SMTP if enabled and configured, native
        // mail() otherwise — so a successful test is a genuine signal the
        // currently-saved configuration works, not a separate code path
        // that could pass while the real one is still broken.
        $testSubject = bk_t('bk_smtp_test_subject', 'Synaptik CMS Booking — test email', 'admin');
        $testBody    = bk_t('bk_smtp_test_body', 'This is a test email from the Booking plugin’s SMTP settings. If you received this, your configuration is working.', 'admin');

        $sent = bk_send_plain_mail($testEmail, 'Booking', $testSubject, $testBody);

        if (!$sent) {
            // bk_send_plain_mail() logs the underlying PHPMailer error via
            // bk_log_mail_error() when SMTP is in use, and keeps a
            // same-request copy retrievable via bk_last_mail_error() —
            // shown directly here so the admin doesn't need server log
            // access to see why the test failed. Native mail() failures
            // (SMTP disabled/unconfigured) don't go through PHPMailer at
            // all, so there's no ErrorInfo to show in that case — the
            // generic fallback covers it.
            $detail = bk_last_mail_error();
            $_SESSION['bk_smtp_test_error'] = $detail !== '' ? $detail : bk_t('bk_smtp_test_error_generic', "Check whether your host's mail function is configured, or enable SMTP above.", 'admin');
            bk_safe_redirect('smtp', ['msg' => 'test_failed']);
        }

        bk_safe_redirect('smtp', ['msg' => 'test_sent']);

    case 'save_email_template':
        $emailType = trim($_POST['email_type'] ?? '');
        $validTypes = ['pending', 'confirmed', 'refused', 'cancelled'];
        if (!in_array($emailType, $validTypes, true)) {
            bk_safe_redirect('emails');
        }

        $settings = bk_load_settings();
        // Same trust level as the Newsletter plugin's composer and the CMS
        // core's own content editor: the WYSIWYG's HTML output is stored
        // as-is, since only an authenticated admin can reach this endpoint.
        $settings['email_templates'][$emailType] = trim($_POST['content'] ?? '');

        bk_save_settings($settings);
        bk_safe_redirect('emails', ['email_type' => $emailType, 'msg' => 'saved']);

    default:
        bk_safe_redirect('calendar');
}
