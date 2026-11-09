<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Booking Plugin — Shortcode Rendering
 *
 * Renders the [booking_form] shortcode into a self-contained HTML block:
 * appointment type selector, month-calendar day picker (populated via AJAX
 * from booking.php), a time-slot list for the selected day, and the client
 * details form. Assets are only injected on pages that actually contain the
 * shortcode — see booking-init.php.
 */

if (defined('BK_SHORTCODE_LOADED')) return;
define('BK_SHORTCODE_LOADED', true);

require_once __DIR__ . '/booking-functions.php';
require_once __DIR__ . '/booking-i18n.php';
require_once __DIR__ . '/booking-security.php';

/**
 * Returns the full HTML for the [booking_form] shortcode: markup only, no
 * inline <script>/<style> — those are injected once per page by
 * bk_render_assets() to avoid duplication if the shortcode appears twice.
 *
 * Built via string concatenation rather than ob_start()/ob_get_clean():
 * this function runs from inside booking-init.php's own output-buffer
 * callback, and PHP does not allow nesting a new output buffer inside an
 * active buffer's display handler ("Cannot use output buffering in output
 * buffering display handlers").
 */
function bk_render_form_html(): string
{
    $settings  = bk_load_settings();
    $types     = $settings['appointment_types'];
    $csrf      = bk_generate_csrf();
    $pluginUrl = bk_plugin_base_url();
    $showCallback = !empty($settings['enable_callback']);

    $typeOptions = '';
    foreach ($types as $key => $type) {
        $typeOptions .= '<option value="' . hsc($key) . '">'
            . hsc($type['label']) . ' (' . (int)$type['duration'] . ' min)'
            . '</option>';
    }

    $tFormTitle       = hsc(bk_t('booking_form_title', 'Book an appointment'));
    $tTypeLabel       = hsc(bk_t('booking_type_label', 'Appointment type'));
    $tMonthPrev       = hsc(bk_t('booking_month_prev', 'Previous month'));
    $tMonthNext       = hsc(bk_t('booking_month_next', 'Next month'));
    $tSelectDay       = hsc(bk_t('booking_select_day', 'Select a day on the calendar to see available times.'));
    $tNoSlotsDay      = hsc(bk_t('booking_no_slots_day', 'No available slots this day.'));
    $tCallbackLabel   = hsc(bk_t('booking_callback_label', 'I would also like to be called back'));
    $tCallbackDate    = hsc(bk_t('booking_callback_date', 'Preferred callback date'));
    $tCallbackTime    = hsc(bk_t('booking_callback_time', 'Preferred callback time'));
    $tSubmit          = hsc(bk_t('booking_submit', 'Request appointment'));
    $tSuccessTitle    = hsc(bk_t('booking_success_title', 'Request received'));
    $tSuccessMessage  = hsc(bk_t('booking_success_message', 'Your appointment request has been sent.'));

    $fieldSpecs = [
        'first_name' => ['id' => 'bk-first-name', 'type' => 'text',  'max' => 100,  'auto' => 'given-name',  'label' => bk_t('booking_first_name', 'First name')],
        'last_name'  => ['id' => 'bk-last-name',  'type' => 'text',  'max' => 100,  'auto' => 'family-name', 'label' => bk_t('booking_last_name', 'Last name')],
        'email'      => ['id' => 'bk-email',      'type' => 'email', 'max' => 254,  'auto' => 'email',       'label' => bk_t('booking_email', 'Email')],
        'phone'      => ['id' => 'bk-phone',      'type' => 'tel',   'max' => 30,   'auto' => 'tel',         'label' => bk_t('booking_phone', 'Phone number')],
        'notes'      => ['id' => 'bk-notes',      'type' => 'textarea', 'max' => 2000, 'auto' => '',         'label' => bk_t('booking_notes', 'Notes')],
    ];
    $fieldHtml = [];
    foreach (bk_get_form_fields() as $key => $cfg) {
        if (!$cfg['enabled']) {
            $fieldHtml[$key] = '';
            continue;
        }
        $spec        = $fieldSpecs[$key];
        $labelText   = $cfg['label'] !== '' ? $cfg['label'] : $spec['label'];
        $requiredTag = $cfg['required'] ? ' *' : '';
        $reqAttr     = $cfg['required'] ? ' required' : '';
        $phAttr      = $cfg['placeholder'] !== '' ? ' placeholder="' . hsc($cfg['placeholder']) . '"' : '';
        $autoAttr    = $spec['auto'] !== '' ? ' autocomplete="' . $spec['auto'] . '"' : '';
        $control     = $spec['type'] === 'textarea'
            ? '<textarea id="' . $spec['id'] . '" name="' . $key . '" maxlength="' . $spec['max'] . '" rows="3"' . $phAttr . $reqAttr . '></textarea>'
            : '<input type="' . $spec['type'] . '" id="' . $spec['id'] . '" name="' . $key . '" maxlength="' . $spec['max'] . '"' . $autoAttr . $phAttr . $reqAttr . '>';
        $fieldHtml[$key] = '<div class="bk-field"><label for="' . $spec['id'] . '">' . hsc($labelText) . $requiredTag . '</label>' . $control . '</div>';
    }
    $fieldsTop = $fieldHtml['first_name'] . $fieldHtml['last_name'] . $fieldHtml['email'] . $fieldHtml['phone'];
    $fieldsNotes = $fieldHtml['notes'];

    $callbackHtml = '';
    if ($showCallback) {
        $callbackHtml = <<<CBHTML
            <div class="bk-field bk-field--checkbox">
                <label>
                    <input type="checkbox" id="bk-callback" name="callback_requested">
                    {$tCallbackLabel}
                </label>
            </div>
            <div class="bk-callback-fields" hidden>
                <div class="bk-field">
                    <label for="bk-callback-date">{$tCallbackDate}</label>
                    <input type="date" id="bk-callback-date" name="callback_date">
                </div>
                <div class="bk-field">
                    <label for="bk-callback-time">{$tCallbackTime}</label>
                    <input type="time" id="bk-callback-time" name="callback_time">
                    <p class="bk-callback-hint" hidden></p>
                </div>
            </div>
CBHTML;
    }

    $endpoint = hsc($pluginUrl);
    $csrfEsc  = hsc($csrf);
    $now      = time();

    return <<<HTML
    <div class="bk-booking" data-bk-endpoint="{$endpoint}">
        <h2 class="bk-title">{$tFormTitle}</h2>

        <div class="bk-field bk-field--type">
            <label for="bk-type">{$tTypeLabel}</label>
            <select id="bk-type" name="type">
                {$typeOptions}
            </select>
        </div>

        <div class="bk-picker">
            <div class="bk-month-calendar">
                <div class="bk-calendar-nav">
                    <button type="button" class="bk-nav-prev" aria-label="{$tMonthPrev}">&larr;</button>
                    <span class="bk-month-label"></span>
                    <button type="button" class="bk-nav-next" aria-label="{$tMonthNext}">&rarr;</button>
                </div>
                <div class="bk-weekday-row"></div>
                <div class="bk-month-grid" aria-live="polite"></div>
            </div>

            <div class="bk-day-slots">
                <div class="bk-day-slots-title"></div>
                <p class="bk-select-day-hint">{$tSelectDay}</p>
                <p class="bk-no-slots" hidden>{$tNoSlotsDay}</p>
                <div class="bk-slots-list" aria-live="polite"></div>
            </div>
        </div>

        <form class="bk-form" novalidate hidden>
            <input type="hidden" name="type" class="bk-selected-type">
            <input type="hidden" name="date" class="bk-selected-date">
            <input type="hidden" name="start_time" class="bk-selected-time">
            <input type="hidden" name="_csrf" value="{$csrfEsc}">
            <input type="hidden" name="_ft" value="{$now}">
            <div class="bk-honeypot" aria-hidden="true">
                <label for="bk-hp">Website</label>
                <input type="text" id="bk-hp" name="_hp" tabindex="-1" autocomplete="off">
            </div>

            <div class="bk-selected-summary"></div>

            {$fieldsTop}

            {$callbackHtml}

            {$fieldsNotes}

            <button type="submit" class="bk-submit">{$tSubmit}</button>

            <div class="bk-message" role="status" hidden></div>
        </form>

        <div class="bk-success" hidden>
            <h3>{$tSuccessTitle}</h3>
            <p>{$tSuccessMessage}</p>
        </div>
    </div>
    HTML;
}

/**
 * Returns the <link>/<script> tags for the booking widget. Called once by
 * the output-buffer hook in booking-init.php, only when [booking_form] was
 * actually found on the page — keeps every other page untouched and fast.
 */
function bk_render_assets(): string
{
    $pluginUrl = bk_plugin_base_url();
    $cssPath = __DIR__ . '/assets/css/booking-form.css';
    $jsPath  = __DIR__ . '/assets/js/booking-form.js';
    $cssV    = file_exists($cssPath) ? filemtime($cssPath) : time();
    $jsV     = file_exists($jsPath) ? filemtime($jsPath) : time();

    $html  = '<link rel="stylesheet" href="' . hsc($pluginUrl . 'assets/css/booking-form.css?v=' . $cssV) . '">' . "\n";
    $html .= '<script src="' . hsc($pluginUrl . 'assets/js/booking-form.js?v=' . $jsV) . '" defer></script>' . "\n";

    // i18n strings needed client-side (JS fetches slots and renders errors).
    $jsStrings = [
        'select_slot'   => bk_t('booking_select_slot', 'Please select a time slot.'),
        'no_slots_day'  => bk_t('booking_no_slots_day', 'No available slots this day.'),
        'submitting'    => bk_t('booking_submitting', 'Sending…'),
        'error_generic' => bk_t('booking_error_generic', 'An error occurred. Please try again.'),
        'callback_hours'  => bk_t('booking_callback_hours', 'Available between {start} and {end}.'),
        'callback_closed' => bk_t('booking_callback_closed', 'We are closed on this date.'),
        'day_labels'    => [
            bk_t('booking_day_mon', 'Mon'),
            bk_t('booking_day_tue', 'Tue'),
            bk_t('booking_day_wed', 'Wed'),
            bk_t('booking_day_thu', 'Thu'),
            bk_t('booking_day_fri', 'Fri'),
            bk_t('booking_day_sat', 'Sat'),
            bk_t('booking_day_sun', 'Sun'),
        ],
        'month_labels'  => [
            bk_t('booking_month_1', 'January'), bk_t('booking_month_2', 'February'), bk_t('booking_month_3', 'March'),
            bk_t('booking_month_4', 'April'), bk_t('booking_month_5', 'May'), bk_t('booking_month_6', 'June'),
            bk_t('booking_month_7', 'July'), bk_t('booking_month_8', 'August'), bk_t('booking_month_9', 'September'),
            bk_t('booking_month_10', 'October'), bk_t('booking_month_11', 'November'), bk_t('booking_month_12', 'December'),
        ],
    ];
    $html .= '<script type="application/json" data-window-var="BK_LANG">' . json_encode($jsStrings, JSON_UNESCAPED_UNICODE) . '</script>' . "\n";

    return $html;
}
