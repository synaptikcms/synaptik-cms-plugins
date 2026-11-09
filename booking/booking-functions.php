<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Booking Plugin — Core Functions
 *
 * Settings, appointment type definitions, and the slot-availability
 * calculator shared by the public form, the submission handler, and the
 * admin calendar view.
 */

if (defined('BK_FUNCTIONS_LOADED')) return;
define('BK_FUNCTIONS_LOADED', true);

require_once __DIR__ . '/booking-data-layer.php';

/**
 * Ensures PHP's default timezone matches the site's configured timezone
 * (Admin → Settings → General) before any date/time comparison in this
 * plugin runs.
 *
 * The core's own loadConfig() (functions.php) applies
 * date_default_timezone_set() as a side effect, but that function is only
 * ever loaded on front-end requests — the admin panel uses its own
 * separate admin_load_config() (admin/includes/admin-functions.php),
 * and neither is guaranteed to have run yet by the time this plugin's
 * code executes admin-side. Without this, every time-based check here
 * (appointment past/upcoming, slot availability, cancellation cutoff)
 * would silently fall back to PHP's ini-configured timezone instead of
 * the site's, which on a fresh install or a local dev environment (MAMP,
 * XAMPP...) is very often UTC or the host machine's own zone — not
 * necessarily the site's.
 *
 * Reads config.json directly rather than depending on either core
 * loader, so it works identically in every context this plugin runs in.
 * Runs once per request (idempotent — cheap to call defensively at the
 * top of any function that does its own time math).
 */
function bk_ensure_site_timezone(): void
{
    if (!empty($GLOBALS['_bk_timezone_applied'])) return;
    $GLOBALS['_bk_timezone_applied'] = true;

    $settingsFile = dirname(__DIR__, 2) . '/config.json'; // plugins/booking/ -> plugins/ -> CMS root
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['timezone'])) {
            @date_default_timezone_set($decoded['timezone']);
        }
    }
}
bk_ensure_site_timezone();

function bk_settings_path(): string
{
    return __DIR__ . '/data/config.json';
}

/**
 * Whether an appointment's scheduled end time has already passed (in
 * server local time, which follows the site's configured timezone — see
 * bk_ensure_site_timezone()). Shared definition used to split
 * appointments between the Requests view (upcoming only) and the History
 * view (past only), so both stay in sync with a single source of truth.
 *
 * Compares against the appointment's end_time (falling back to
 * start_time, then to end-of-day, if either is missing/malformed) rather
 * than just the calendar date — a same-day appointment must migrate to
 * History as soon as its time slot ends, not only once the date itself
 * rolls over to tomorrow.
 */
function bk_appointment_is_past(array $appointment): bool
{
    bk_ensure_site_timezone();

    $date = $appointment['date'] ?? '';
    if ($date === '') return false;

    $timeForComparison = $appointment['end_time'] ?? $appointment['start_time'] ?? '23:59';
    $ts = strtotime($date . ' ' . $timeForComparison);

    // Malformed date/time: fall back to a date-only comparison rather than
    // treating the appointment as never-past.
    if ($ts === false) {
        return $date < date('Y-m-d');
    }

    return $ts < time();
}

/**
 * Whether the client's self-service cancellation link is still usable for
 * this appointment — i.e. its start time is still at least
 * 'client_cancel_min_notice_hours' away from now(). Used by
 * booking-cancel.php at both the GET (confirmation prompt) and POST (actual
 * cancellation) steps, so a link that expires between the two still shows
 * the polite cutoff message rather than cancelling anyway.
 *
 * A missing or malformed date/time is treated as "too late" rather than
 * throwing, since strtotime() failing here should never accidentally allow
 * a cancellation through.
 */
function bk_client_cancellation_allowed(array $appointment): bool
{
    $ts = strtotime(($appointment['date'] ?? '') . ' ' . ($appointment['start_time'] ?? ''));
    if ($ts === false) return false;

    $settings   = bk_load_settings();
    $minNotice  = max(0, (int)($settings['client_cancel_min_notice_hours'] ?? 48));

    return $ts > (time() + ($minNotice * 3600));
}

/**
 * Default plugin settings. Appointment types are keyed by a stable slug so
 * existing appointments keep referencing the correct type even if the label
 * is edited later.
 */
function bk_default_settings(): array
{
    return [
        'admin_email'       => '',
        'from_email'        => '', // falls back to noreply@{host} when empty
        'from_name'         => '', // falls back to the site title when empty
        'timezone'          => 'Europe/Paris',
        'slot_interval'     => 15,   // minutes — granularity of displayed start times
        'min_notice_hours'  => 24,   // no bookings within this many hours of now
        'max_advance_days'  => 60,   // no bookings further than this many days out
        // When true, a requested callback time must fall within that day's
        // configured availability window (weekly rule or exception) — the
        // same window used for actual appointment slots. When false,
        // callback requests accept any time of day, since a phone callback
        // isn't necessarily bound by the same hours as in-person/booked
        // appointments (e.g. a solo practitioner who takes booking calls
        // outside opening hours).
        'restrict_callback_to_availability' => false,
        'enable_callback' => true,
        // Whether to send the client a receipt email on submission (status:
        // pending). Enabled by default; can be turned off when the workflow
        // doesn't need it (e.g. the admin confirms immediately, so the
        // confirmation email is the only one the client needs to receive).
        'send_pending_email' => true,
        // Minimum number of hours before the appointment's start time during
        // which the client's self-service cancellation link (see
        // booking-cancel.php) still works. Past this cutoff the link shows a
        // polite "too late to cancel" message instead of cancelling — the
        // admin must cancel it manually from the Requests tab if needed.
        // Does not affect admin-initiated cancellation, which has no cutoff.
        'client_cancel_min_notice_hours' => 48,
        // Whether the appointments widget appears on the main admin
        // dashboard (see booking-init.php, 'admin_dashboard' hook).
        'show_dashboard_widget' => true,
        // Whether the client's pending/confirmed notification emails carry
        // the self-service cancellation link footer (bk_append_cancel_link_html()
        // in booking-mail.php). Enabled by default, matching pre-existing
        // behaviour. Turning it off does not disable booking-cancel.php
        // itself — the client just won't get a direct link to it by email.
        'show_cancel_link_in_email' => true,
        // 'hide' (default) omits fully-booked/past-cutoff slots from the
        // public list entirely, exactly as before. 'show_disabled' still
        // lists every slot in the day's window, but greys out and disables
        // the ones that are not actually bookable.
        'slot_display_mode' => 'hide',
        // Optional custom HTML email bodies, one per appointment status. Each
        // is authored via the admin's WYSIWYG editor (Emails tab) and may
        // use the placeholders documented in bk_render_email_template():
        // {first_name}, {last_name}, {full_name}, {date}, {time}, {type}.
        // Empty string means "use the built-in i18n default text" for that
        // status — see bk_notify_client_*() in booking-mail.php.
        'email_templates' => [
            'pending'   => '',
            'confirmed' => '',
            'refused'   => '',
            'cancelled' => '',
        ],
        // Controls how the calendar event attached to the client's .ics
        // file is built. Empty summary/description falls back to the
        // previous hardcoded behaviour ("{type}" as the summary, no
        // description) — see bk_build_client_ics() in booking-mail.php.
        // Supports the same placeholders as bk_render_template():
        // {first_name}, {last_name}, {full_name}, {date}, {time}, {type},
        // {site_name}.
        'ics_summary_template'     => '',
        'ics_description_template' => '',
        'ics_location'              => '',
        'ics_url'                   => '',
        // SMTP transport configuration (Settings → SMTP tab). When disabled
        // (the default), every outgoing email keeps using PHP's native
        // mail() exactly as before — nothing here changes behaviour until
        // the admin explicitly opts in. See bk_get_mailer() in
        // booking-mail.php for how this is consumed.
        //
        // The SMTP password itself is never stored here: config.json is
        // the file an admin is most likely to open, screenshot, or paste
        // when asking for help, and it's already the shape most exposed
        // to accidental disclosure in this plugin. The password lives
        // instead in the plugin's private/ directory (bk_private_dir() —
        // same .htaccess-denied store as the CSRF secret) via
        // bk_save_smtp_password() / bk_load_smtp_password().
        'smtp' => [
            'enabled'    => false,
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls', // 'tls', 'ssl', or '' for none
            'username'   => '',
            'auth'       => true,
        ],
        'form_fields' => bk_default_form_fields(),
        'appointment_types' => [
            'first_visit' => ['label' => 'First appointment', 'duration' => 90],
            'follow_up'   => ['label' => 'Follow-up appointment', 'duration' => 45],
        ],
    ];
}

function bk_form_field_keys(): array
{
    return ['first_name', 'last_name', 'email', 'phone', 'notes'];
}

function bk_default_form_fields(): array
{
    $fields = [];
    foreach (bk_form_field_keys() as $key) {
        $fields[$key] = ['enabled' => true, 'required' => true, 'label' => '', 'placeholder' => ''];
    }
    return $fields;
}

function bk_get_form_fields(): array
{
    $stored = bk_load_settings()['form_fields'] ?? [];
    $fields = [];
    foreach (bk_default_form_fields() as $key => $default) {
        $row      = is_array($stored[$key] ?? null) ? $stored[$key] : [];
        $enabled  = array_key_exists('enabled', $row) ? !empty($row['enabled']) : $default['enabled'];
        $required = $enabled && (array_key_exists('required', $row) ? !empty($row['required']) : $default['required']);
        $fields[$key] = [
            'enabled'     => $enabled,
            'required'    => $required,
            'label'       => (string)($row['label'] ?? ''),
            'placeholder' => (string)($row['placeholder'] ?? ''),
        ];
    }
    if (!$fields['email']['enabled'] && !$fields['phone']['enabled']) {
        $fields['email']['enabled'] = true;
    }
    return $fields;
}

function bk_load_settings(): array
{
    if (isset($GLOBALS['_bk_config_cache'])) {
        return $GLOBALS['_bk_config_cache'];
    }

    $settings = bk_default_settings();
    $path     = bk_settings_path();

    if (file_exists($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded)) {
            $settings = array_merge($settings, $decoded);
            if (!empty($decoded['appointment_types'])) {
                $settings['appointment_types'] = $decoded['appointment_types'];
            }
            // 'smtp' is a nested array — the top-level array_merge() above
            // replaces it wholesale rather than merging its own keys, so a
            // config.json written before a new sub-key existed (or one
            // written by an older version of save_smtp_settings()) would
            // silently lose any default not explicitly present on disk.
            // Re-merged one level deeper here so defaults always backfill
            // missing sub-keys the same way the rest of $settings does.
            if (!empty($decoded['smtp']) && is_array($decoded['smtp'])) {
                $settings['smtp'] = array_merge(bk_default_settings()['smtp'], $decoded['smtp']);
            }
        }
    }

    $GLOBALS['_bk_config_cache'] = $settings;
    return $settings;
}

function bk_save_settings(array $settings): bool
{
    bk_ensure_dirs();
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $path = bk_settings_path();
    $tmp  = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;

    $success = rename($tmp, $path);
    if ($success) {
        unset($GLOBALS['_bk_config_cache']);
    }
    return $success;
}

/**
 * Path to the SMTP password file, inside the plugin's .htaccess-denied
 * private/ store (bk_private_dir(), booking-security.php) rather than
 * data/config.json. Kept separate so the password never ends up in a
 * file an admin might reasonably copy/paste/screenshot when asking for
 * help with an unrelated setting.
 *
 * Requires booking-security.php to be loaded for bk_private_dir() — every
 * call site here already depends on it indirectly (bk_load_settings() /
 * bk_save_settings() are only reachable from admin contexts that load the
 * full plugin, and booking-mail.php explicitly requires
 * booking-security.php itself), so no separate require_once is added here
 * to avoid a circular require between the two files.
 */
function bk_smtp_password_path(): string
{
    if (!function_exists('bk_private_dir')) {
        require_once __DIR__ . '/booking-security.php';
    }
    return bk_private_dir() . '/smtp_password.php';
}

/**
 * Saves the SMTP password, wrapped in a PHP file that die()s immediately
 * if requested directly — defense in depth alongside the private/
 * directory's own .htaccess (which already denies all direct access, but
 * a misconfigured or overridden server config is exactly the scenario
 * this second layer is for). Pass an empty string to clear the stored
 * password (e.g. when SMTP auth is turned off).
 */
function bk_save_smtp_password(string $password): bool
{
    bk_ensure_dirs();
    bk_private_dir(); // ensures the directory (and its .htaccess) exist

    $php = "<?php // Do not access directly.\nif (!defined('BK_FUNCTIONS_LOADED')) { http_response_code(403); exit; }\nreturn " . var_export($password, true) . ";\n";

    $path = bk_smtp_password_path();
    $tmp  = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $php, LOCK_EX) === false) return false;

    return rename($tmp, $path);
}

/**
 * Loads the stored SMTP password, or '' if none has been set.
 */
function bk_load_smtp_password(): string
{
    $path = bk_smtp_password_path();
    if (!file_exists($path)) return '';

    $password = include $path;
    return is_string($password) ? $password : '';
}

/**
 * Returns a single appointment type definition, or null if the key is unknown.
 */
function bk_get_appointment_type(string $typeKey): ?array
{
    $types = bk_load_settings()['appointment_types'];
    return $types[$typeKey] ?? null;
}

/**
 * Returns the site's base URL with a trailing slash, correctly accounting
 * for the CMS living in a sub-directory (e.g. https://host/synaptikcms/) rather
 * than the domain root.
 *
 * Cannot simply delegate to the core's getBaseUrl(): that function derives
 * the path from $_SERVER['SCRIPT_NAME'], which is only correct when the
 * currently-executing script is index.php. When booking.php or
 * booking-submit.php run directly (the normal case for this plugin's own
 * AJAX endpoints), SCRIPT_NAME points at .../plugins/booking/booking.php,
 * which would incorrectly yield the CMS root as .../plugins/booking/.
 *
 * Instead, the CMS root is derived from the plugin's own filesystem
 * position (this file lives at {cms_root}/plugins/booking/booking-functions.php,
 * two levels below the CMS root) relative to DOCUMENT_ROOT — reliable
 * regardless of which script runs.
 */
function bk_site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    $host = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $cmsRootFilesystemPath = dirname(__DIR__, 2); // plugins/booking/ -> plugins/ -> CMS root
    $documentRoot          = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $cmsRootFilesystemPath = str_replace('\\', '/', $cmsRootFilesystemPath);

    if ($documentRoot !== '' && strpos($cmsRootFilesystemPath, $documentRoot) === 0) {
        $urlPath = substr($cmsRootFilesystemPath, strlen($documentRoot));
    } else {
        // DOCUMENT_ROOT unavailable or mismatched (rare, some proxy setups) —
        // fall back to the core's getBaseUrl() logic as a best effort.
        $urlPath = function_exists('getBaseUrl') ? parse_url(getBaseUrl(), PHP_URL_PATH) : '/';
    }

    $urlPath = '/' . trim($urlPath, '/');
    if ($urlPath === '/') {
        return $host . '/';
    }
    return $host . $urlPath . '/';
}

/**
 * Returns the base URL of the plugin's own folder (CMS base + 'plugins/booking/'),
 * with a trailing slash. Use this for all links to booking.php,
 * booking-submit.php, and the assets/ folder.
 */
function bk_plugin_base_url(): string
{
    return bk_site_base_url() . 'plugins/booking/';
}

/**
 * Builds the signed one-click cancellation link sent in the client's
 * pending/confirmed notification emails. Requires booking-security.php to
 * be loaded (bk_generate_action_token()).
 */
function bk_build_client_cancel_link(string $appointmentId): string
{
    return bk_plugin_base_url() . 'booking-cancel.php?token=' . urlencode(bk_generate_action_token($appointmentId, 'cancel'));
}

/**
 * Resolves the "From" address/name for outgoing notification emails,
 * falling back to a noreply@{host} address and the site title when not
 * explicitly configured. Mirrors the Newsletter plugin's nl_from_address()
 * so both plugins share the same override behaviour instead of hardcoding
 * 'noreply@' . $host at every call site.
 */
function bk_from_address(): array
{
    $settings = bk_load_settings();
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $email = $settings['from_email'] !== '' ? $settings['from_email'] : ('noreply@' . $host);

    $name = $settings['from_name'];
    if ($name === '' && function_exists('loadConfig')) {
        $coreSettings = loadConfig();
        $name = $coreSettings['site_title'] ?? 'Booking';
    }
    if ($name === '') {
        $name = 'Booking';
    }

    return ['email' => $email, 'name' => $name];
}

/**
 * Substitutes the supported placeholders into a custom template string —
 * shared by the client email templates (Emails tab) and the .ics summary/
 * description templates (Settings tab). Supported tokens: {first_name},
 * {last_name}, {full_name}, {date}, {time}, {type}, {site_name}. $date is
 * formatted per the site's date_format setting via the core's
 * format_date() when available.
 *
 * format_date() (data-layer.php) is NOT guaranteed to be loaded here: this
 * function runs both from the page-render path (admin/index.php's
 * plugin_page router, which loads the full core including data-layer.php)
 * and from the POST-only admin/actions.php path (via bootstrap.php, which
 * only loads this plugin's own files — never the core). The local
 * fallback below reads date_format directly from config.json so both
 * paths format dates consistently either way.
 */
function bk_render_template(string $template, array $appointment, ?array $type): string
{
    $dateFormatted = bk_format_date_i18n($appointment['date'] ?? '');

    $firstName = $appointment['first_name'] ?? '';
    $lastName  = $appointment['last_name'] ?? '';

    $siteName = '';
    if (function_exists('loadConfig')) {
        $coreSettings = loadConfig();
        $siteName = $coreSettings['site_title'] ?? '';
    }

    $replacements = [
        '{first_name}' => $firstName,
        '{last_name}'  => $lastName,
        '{full_name}'  => trim($firstName . ' ' . $lastName),
        '{date}'       => $dateFormatted,
        '{time}'       => $appointment['start_time'] ?? '',
        '{type}'       => $type['label'] ?? ($appointment['type'] ?? ''),
        '{site_name}'  => $siteName,
    ];

    $rendered = str_replace(array_keys($replacements), array_values($replacements), $template);
    return bk_render_email_button_shortcodes($rendered);
}

/**
 * Standalone equivalent of the core's format_date(), for the code paths in
 * this plugin (admin/actions.php via bootstrap.php) where the core's own
 * data-layer.php is never loaded. Reads date_format directly from
 * config.json rather than depending on either of the core's two
 * separate settings loaders (loadConfig() / admin_load_config()).
 */
function bk_format_date_fallback(string $date): string
{
    $ts = strtotime($date);
    if ($ts === false) return $date;

    $format = 'Y-m-d';
    $settingsFile = dirname(__DIR__, 2) . '/config.json';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['date_format'])) {
            $format = $decoded['date_format'];
        }
    }

    return date($format, $ts);
}

/**
 * Legacy alias for bk_render_template() — kept so existing call sites in
 * bk_notify_client_*() (booking-mail.php) don't need to change.
 */
function bk_render_email_template(string $template, array $appointment, ?array $type): string
{
    return bk_render_template($template, $appointment, $type);
}

/**
 * Formats a Y-m-d date string using the plugin's translated month names,
 * so client emails render dates in the site's active language rather than
 * PHP's default English. Falls back to the CMS core's format_date() when
 * the date_format setting doesn't include a month name (e.g. pure numeric
 * formats like d/m/Y), since those don't need translation.
 */
function bk_format_date_i18n(string $date): string
{
    $ts = strtotime($date);
    if ($ts === false) return $date;

    $settingsFile = dirname(__DIR__, 2) . '/config.json';
    $format = 'd/m/Y';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['date_format'])) {
            $format = $decoded['date_format'];
        }
    }

    // If no textual month token, date() handles it natively without any i18n needed.
    if (strpos($format, 'F') === false && strpos($format, 'M') === false) {
        return date($format, $ts);
    }

    $monthNum       = (int)date('n', $ts);
    $monthFull      = bk_t('booking_month_' . $monthNum, date('F', $ts));
    // Short month: first 3 characters of the translated full name.
    $monthShort     = mb_substr($monthFull, 0, 3, 'UTF-8');

    // Build the output token by token so the translated month name is
    // never passed back through date() where its characters would be
    // re-interpreted as format tokens (e.g. 't', 'o', 'Y' in "Août").
    $out    = '';
    $len    = strlen($format);
    $escape = false;
    for ($i = 0; $i < $len; $i++) {
        $ch = $format[$i];
        if ($escape) {
            $out .= $ch;
            $escape = false;
            continue;
        }
        if ($ch === '\\') {
            $escape = true;
            continue;
        }
        if ($ch === 'F') {
            $out .= $monthFull;
        } elseif ($ch === 'M') {
            $out .= $monthShort;
        } else {
            $out .= date($ch, $ts);
        }
    }
    return $out;
}

/**
 * Converts [button url="..." label="..." color="#hex"] shortcodes inside
 * email HTML to inline-styled <a> tags. The optional color attribute
 * overrides the default background — use it to match the site's brand color.
 */
function bk_render_email_button_shortcodes(string $html): string
{
    return preg_replace_callback(
        '/\[button\s([^\]]+)\]/i',
        function (array $m): string {
            $attrs = [];
            preg_match_all('/([\w-]+)=["\']([^"\']*)["\']/', $m[1], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $attrs[$pair[1]] = $pair[2];
            }
            $url   = htmlspecialchars($attrs['url'] ?? '#', ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars($attrs['label'] ?? 'Click here', ENT_QUOTES, 'UTF-8');
            $bg    = preg_match('/^#[0-9a-fA-F]{3,6}$/', $attrs['color'] ?? '') ? $attrs['color'] : '#1e293b';
            return '<a href="' . $url . '" style="display:inline-block;padding:10px 22px;background:' . $bg . ';color:#ffffff;text-decoration:none;border-radius:4px;font-weight:600;font-size:14px;">' . $label . '</a>';
        },
        $html
    );
}

// ─── Slot calculation ───────────────────────────────────────────────────────

/**
 * Returns the availability window (start/end, or null if closed) for a given
 * date, resolving weekly rules against any date-specific exception. Also
 * returns the day's lunch break, if enabled, so callers can block it out.
 *
 * @return array{start: string, end: string, lunch: array{start: string, end: string}|null}|null
 */
function bk_day_window(string $date): ?array
{
    $availability = bk_load_availability();
    $exceptions   = $availability['exceptions'] ?? [];

    $isoDay = (int)date('N', strtotime($date)); // 1 (Mon) .. 7 (Sun)
    $rule   = $availability['weekly'][(string)$isoDay] ?? null;

    // The weekly rule's lunch break still applies on an exception day (see
    // the note in bk_default_availability()) — resolved once here regardless
    // of which branch below determines the actual open/close window.
    $lunch = null;
    if ($rule !== null && !empty($rule['lunch_enabled']) && !empty($rule['lunch_start']) && !empty($rule['lunch_end'])) {
        $lunch = ['start' => $rule['lunch_start'], 'end' => $rule['lunch_end']];
    }

    if (isset($exceptions[$date])) {
        $exception = $exceptions[$date];
        if (!empty($exception['closed'])) {
            return null;
        }
        if (!empty($exception['start']) && !empty($exception['end'])) {
            return ['start' => $exception['start'], 'end' => $exception['end'], 'lunch' => $lunch];
        }
        // Exception present but incomplete — fall through to the weekly rule.
    }

    if ($rule === null || empty($rule['enabled'])) {
        return null;
    }

    return ['start' => $rule['start'], 'end' => $rule['end'], 'lunch' => $lunch];
}

/**
 * Computes every slot in a given date's availability window for an
 * appointment type, each tagged with whether it's actually bookable right
 * now. This is the shared core used by both bk_available_slots() (bookable
 * only, for the 'hide' display mode and for server-side booking validation)
 * and the public endpoint's 'show_disabled' mode (every slot, greyed out
 * client-side when unavailable).
 *
 * A slot is bookable when:
 *  - it falls within the day's availability window,
 *  - it does not overlap the day's lunch break, if one is configured,
 *  - the appointment (start → start + duration) does not overlap any
 *    already-booked (pending or confirmed) appointment that day,
 *  - it respects the minimum notice period from now(),
 *  - the whole appointment fits before the window's closing time.
 *
 * @param string $date     Y-m-d
 * @param string $typeKey  Appointment type key (determines duration)
 * @return array<int, array{time: string, available: bool}>
 */
function bk_day_slots_detailed(string $date, string $typeKey): array
{
    $type = bk_get_appointment_type($typeKey);
    if ($type === null) return [];

    $window = bk_day_window($date);
    if ($window === null) return [];

    $settings = bk_load_settings();
    $interval = max(5, (int)$settings['slot_interval']);
    $duration = (int)$type['duration'];

    $dayStart = strtotime($date . ' ' . $window['start']);
    $dayEnd   = strtotime($date . ' ' . $window['end']);
    if ($dayStart === false || $dayEnd === false || $dayStart >= $dayEnd) return [];

    // Booked ranges for the day, as [start_ts, end_ts] pairs — the lunch
    // break is folded into this same list so it's excluded by the exact
    // same overlap check as real appointments, no separate branch needed.
    $booked = [];
    foreach (bk_load_booked_slots_for_date($date) as $entry) {
        $booked[] = [
            strtotime($date . ' ' . $entry['start_time']),
            strtotime($date . ' ' . $entry['end_time']),
        ];
    }
    if (!empty($window['lunch'])) {
        $lunchStart = strtotime($date . ' ' . $window['lunch']['start']);
        $lunchEnd   = strtotime($date . ' ' . $window['lunch']['end']);
        if ($lunchStart !== false && $lunchEnd !== false && $lunchStart < $lunchEnd) {
            $booked[] = [$lunchStart, $lunchEnd];
        }
    }

    $earliestAllowed = time() + ((int)$settings['min_notice_hours'] * 3600);
    $latestAllowed   = time() + ((int)$settings['max_advance_days'] * 86400);

    $slots = [];
    for ($ts = $dayStart; $ts + ($duration * 60) <= $dayEnd; $ts += $interval * 60) {
        $slotEnd = $ts + ($duration * 60);

        $available = true;
        if ($ts < $earliestAllowed) $available = false;
        if ($ts > $latestAllowed)   $available = false;

        if ($available) {
            foreach ($booked as [$bStart, $bEnd]) {
                if ($ts < $bEnd && $slotEnd > $bStart) {
                    $available = false;
                    break;
                }
            }
        }

        $slots[] = ['time' => date('H:i', $ts), 'available' => $available];
    }

    return $slots;
}

/**
 * Computes every bookable start time for a given date and appointment type.
 * Thin filter over bk_day_slots_detailed() — kept as a plain string[] since
 * this is also the function server-side validation
 * (bk_is_slot_available()) relies on.
 *
 * @param string $date     Y-m-d
 * @param string $typeKey  Appointment type key (determines duration)
 * @return string[]        List of bookable start times, 'H:i' format
 */
function bk_available_slots(string $date, string $typeKey): array
{
    $slots = array_filter(bk_day_slots_detailed($date, $typeKey), fn($slot) => $slot['available']);
    return array_values(array_map(fn($slot) => $slot['time'], $slots));
}

/**
 * Returns whether a specific time-of-day falls within the configured
 * availability window for a given date (weekly rule or date exception).
 * Used to validate requested callback times when
 * 'restrict_callback_to_availability' is enabled in settings.
 *
 * Deliberately ignores the lunch break: a callback is a phone call, not an
 * in-person appointment slot, so it isn't subject to the same overlap
 * rules as bk_available_slots() — only the day's open/close window applies.
 */
function bk_time_within_availability(string $date, string $time): bool
{
    $window = bk_day_window($date);
    if ($window === null) return false;

    $ts      = strtotime($date . ' ' . $time);
    $dayStart = strtotime($date . ' ' . $window['start']);
    $dayEnd   = strtotime($date . ' ' . $window['end']);

    if ($ts === false || $dayStart === false || $dayEnd === false) return false;

    return $ts >= $dayStart && $ts <= $dayEnd;
}

/**
 * Returns whether a specific start time is still bookable for a type on a
 * given date. Re-validated server-side on submission — the slot list shown
 * to the client is never trusted as-is (another visitor may have booked it
 * in the meantime).
 */
function bk_is_slot_available(string $date, string $typeKey, string $startTime): bool
{
    return in_array($startTime, bk_available_slots($date, $typeKey), true);
}

/**
 * Finds every pending/confirmed appointment that would fall outside a
 * proposed availability configuration — a closed day it's booked on, or a
 * day (weekly rule or date exception) whose new hours no longer cover its
 * start/end time. Takes the *proposed* $weekly/$exceptions directly rather
 * than reading bk_load_availability(), since this runs before the new
 * configuration is saved — admin/actions.php's save_availability calls this
 * to block a save that would silently strand an existing booking.
 *
 * Only appointments with a status the client still expects to be honoured
 * are checked — a refused/cancelled appointment can't conflict with
 * anything, since the slot it once held is already free.
 *
 * Deliberately ignores lunch-break overlap: flagging an existing booking
 * that only conflicts with a newly-enabled lunch break is a much softer
 * problem (the admin can just work through lunch that day) than one that
 * lands outside opening hours entirely, so it's left out of this check to
 * avoid over-warning on a low-stakes edge case.
 *
 * @param array $weekly     Same shape as bk_load_availability()['weekly'].
 * @param array $exceptions Same shape as bk_load_availability()['exceptions'].
 * @return array<int, array{appointment: array, reason: string}>
 *         $reason is 'closed' or 'outside_hours'.
 */
function bk_find_appointments_conflicting_with_availability(array $weekly, array $exceptions): array
{
    $conflicts = [];

    foreach (bk_load_all_appointments() as $appointment) {
        if (!in_array($appointment['status'], ['pending', 'confirmed'], true)) continue;

        $date = $appointment['date'] ?? '';
        if ($date === '') continue;

        // Only ever check appointments booked today or later — a proposed
        // schedule change can't "conflict" with something already in the past.
        if (bk_appointment_is_past($appointment)) continue;

        // Resolve the proposed window for this date: exception first (if
        // any), falling back to the proposed weekly rule — mirrors
        // bk_day_window()'s own precedence, but against the not-yet-saved
        // $weekly/$exceptions passed in rather than the ones on disk.
        $window = null;
        $reasonIfClosed = 'closed';

        if (isset($exceptions[$date])) {
            $exception = $exceptions[$date];
            if (!empty($exception['closed'])) {
                $conflicts[] = ['appointment' => $appointment, 'reason' => 'closed'];
                continue;
            }
            if (!empty($exception['start']) && !empty($exception['end'])) {
                $window = ['start' => $exception['start'], 'end' => $exception['end']];
            }
        }

        if ($window === null) {
            $isoDay = (int)date('N', strtotime($date));
            $rule   = $weekly[(string)$isoDay] ?? null;

            if ($rule === null || empty($rule['enabled'])) {
                $conflicts[] = ['appointment' => $appointment, 'reason' => $reasonIfClosed];
                continue;
            }
            $window = ['start' => $rule['start'], 'end' => $rule['end']];
        }

        $apptStart   = strtotime($date . ' ' . ($appointment['start_time'] ?? ''));
        $apptEnd     = strtotime($date . ' ' . ($appointment['end_time'] ?? ''));
        $windowStart = strtotime($date . ' ' . $window['start']);
        $windowEnd   = strtotime($date . ' ' . $window['end']);

        if ($apptStart === false || $apptEnd === false || $windowStart === false || $windowEnd === false) continue;

        if ($apptStart < $windowStart || $apptEnd > $windowEnd) {
            $conflicts[] = ['appointment' => $appointment, 'reason' => 'outside_hours'];
        }
    }

    // Soonest date first — the most urgent conflicts (an appointment coming
    // up sooner) belong at the top of the warning the admin sees.
    usort($conflicts, function ($a, $b) {
        return strcmp(
            $a['appointment']['date'] . $a['appointment']['start_time'],
            $b['appointment']['date'] . $b['appointment']['start_time']
        );
    });

    return $conflicts;
}

/**
 * Computes the end time (H:i) for a booking given its start time and type.
 */
function bk_compute_end_time(string $date, string $startTime, string $typeKey): ?string
{
    $type = bk_get_appointment_type($typeKey);
    if ($type === null) return null;

    $ts = strtotime($date . ' ' . $startTime);
    if ($ts === false) return null;

    return date('H:i', $ts + ((int)$type['duration'] * 60));
}
