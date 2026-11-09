<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Booking Plugin — Data Layer
 *
 * Split-file storage for appointments, mirroring the CMS core's data-layer
 * pattern (lightweight index + individual item files, atomic writes).
 * Completely independent from /data/ — the plugin owns its own store under
 * /booking/data/ so core content is never touched.
 *
 * Storage layout:
 *   booking/data/appointments/_index.json   — lightweight index (list/calendar views)
 *   booking/data/appointments/{id}.json     — full appointment record
 *   booking/data/availability.json          — weekly recurring rules + exceptions
 *
 * All public functions are prefixed bk_ (Booking).
 */

if (defined('BK_DATA_LAYER_LOADED')) return;
define('BK_DATA_LAYER_LOADED', true);

function bk_data_dir(): string
{
    return __DIR__ . '/data';
}

function bk_appointments_dir(): string
{
    return bk_data_dir() . '/appointments';
}

function bk_index_path(): string
{
    return bk_appointments_dir() . '/_index.json';
}

function bk_item_path(string $id): string
{
    return bk_appointments_dir() . '/' . $id . '.json';
}

function bk_availability_path(): string
{
    return bk_data_dir() . '/availability.json';
}

function bk_lock_path(): string
{
    return bk_data_dir() . '/.booking.lock';
}

/**
 * Acquires an exclusive lock spanning both the slot-availability check and
 * the appointment save, so two near-simultaneous requests for the same slot
 * can't both pass the check before either has written — the check alone,
 * re-run right before saving, still leaves that gap open without a lock
 * held across the whole sequence. $callback receives no arguments; return
 * its result directly.
 *
 * Fails open (runs $callback without a lock) if the lock file can't be
 * opened, rather than turning a filesystem hiccup into "booking is down".
 */
function bk_with_booking_lock(callable $callback)
{
    bk_ensure_dirs();
    $fh = @fopen(bk_lock_path(), 'c');
    if ($fh === false) {
        return $callback();
    }

    flock($fh, LOCK_EX);
    try {
        return $callback();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Ensures the required directory structure exists, with .htaccess protection
 * on first creation (defense in depth alongside the plugin-level .htaccess).
 */
function bk_ensure_dirs(): void
{
    $dir = bk_appointments_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $content = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
             . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    @file_put_contents(bk_data_dir() . '/.htaccess', $content);
}

/**
 * Atomic JSON write (write to .tmp, then rename) — prevents partial writes
 * from corrupting a file if the PHP process is interrupted mid-write.
 */
function _bk_write_json(string $path, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;

    return rename($tmp, $path);
}

/**
 * Per-request in-memory cache for the index, avoiding repeated disk reads
 * within the same PHP request (mirrors the core's GLOBALS-based L0 cache).
 */
function bk_load_index(): array
{
    if (isset($GLOBALS['_bk_index_cache'])) {
        return $GLOBALS['_bk_index_cache'];
    }

    $path = bk_index_path();
    if (!file_exists($path)) {
        $GLOBALS['_bk_index_cache'] = [];
        return [];
    }

    $raw     = file_get_contents($path);
    $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    $result  = is_array($decoded) ? $decoded : [];

    $GLOBALS['_bk_index_cache'] = $result;
    return $result;
}

function bk_invalidate_index_cache(): void
{
    unset($GLOBALS['_bk_index_cache']);
}

/**
 * Writes the full index array to disk and invalidates the request cache.
 */
function bk_write_index(array $index): bool
{
    bk_ensure_dirs();
    $success = _bk_write_json(bk_index_path(), $index);
    if ($success) {
        bk_invalidate_index_cache();
    }
    return $success;
}

/**
 * Loads a single full appointment record by ID.
 */
function bk_load_appointment(string $id): ?array
{
    if ($id === '') return null;
    $path = bk_item_path($id);
    if (!file_exists($path)) return null;

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') return null;

    $item = json_decode($raw, true);
    return is_array($item) ? $item : null;
}

/**
 * Loads every full appointment record. Only used by admin views — the public
 * booking form and slot-availability checks work off the lightweight index.
 */
function bk_load_all_appointments(): array
{
    $index = bk_load_index();
    $items = [];
    foreach ($index as $entry) {
        $item = bk_load_appointment($entry['id']);
        if ($item !== null) {
            $items[] = $item;
        }
    }
    return $items;
}

/**
 * Generates a unique appointment ID: {Y-m-d}-{6 random hex chars}.
 * Date-prefixed for readability in the filesystem and admin lists.
 */
function bk_generate_id(): string
{
    return date('Y-m-d') . '-' . bin2hex(random_bytes(3));
}

/**
 * Fields kept in the lightweight index — enough to render the calendar and
 * list views without opening every individual appointment file.
 */
function bk_extract_index_entry(array $appointment): array
{
    return [
        'id'         => $appointment['id'],
        'date'       => $appointment['date'],       // Y-m-d
        'start_time' => $appointment['start_time'],  // H:i
        'end_time'   => $appointment['end_time'],    // H:i
        'type'       => $appointment['type'],        // appointment type key
        'first_name' => $appointment['first_name'],
        'last_name'  => $appointment['last_name'],
        'status'     => $appointment['status'],       // pending | confirmed | cancelled | refused
        'has_callback_request' => !empty($appointment['callback_requested']),
    ];
}

/**
 * Saves a new appointment: writes the full record, adds it to the index.
 * Returns the generated ID on success, or null on failure.
 */
function bk_save_appointment(array $appointment): ?string
{
    bk_ensure_dirs();

    $id = $appointment['id'] ?? bk_generate_id();
    $appointment['id'] = $id;

    if (!_bk_write_json(bk_item_path($id), $appointment)) {
        return null;
    }

    $index   = bk_load_index();
    $index[] = bk_extract_index_entry($appointment);

    if (!bk_write_index($index)) {
        return null;
    }

    return $id;
}

/**
 * Updates an existing appointment (e.g. status change on admin approval).
 * Merges $fields into the stored record and refreshes its index entry.
 */
function bk_update_appointment(string $id, array $fields): bool
{
    $item = bk_load_appointment($id);
    if ($item === null) return false;

    $item = array_merge($item, $fields);

    if (!_bk_write_json(bk_item_path($id), $item)) {
        return false;
    }

    $index = bk_load_index();
    foreach ($index as $i => $entry) {
        if ($entry['id'] === $id) {
            $index[$i] = bk_extract_index_entry($item);
            break;
        }
    }

    return bk_write_index($index);
}

/**
 * Permanently deletes an appointment (record + index entry).
 * Used for cleanup of old cancelled/refused appointments, not for normal
 * cancellation flow (which sets status to 'cancelled' and keeps history).
 */
function bk_delete_appointment(string $id): bool
{
    $path = bk_item_path($id);
    if (file_exists($path)) {
        unlink($path);
    }

    $index = bk_load_index();
    $new   = array_values(array_filter($index, fn($e) => $e['id'] !== $id));

    return bk_write_index($new);
}

/**
 * Returns all appointments occupying a given date that count as "booked"
 * (pending or confirmed — cancelled/refused slots are free again).
 * Used by the slot-availability calculator.
 */
function bk_load_booked_slots_for_date(string $date): array
{
    $index = bk_load_index();
    return array_values(array_filter($index, function ($e) use ($date) {
        return $e['date'] === $date && in_array($e['status'], ['pending', 'confirmed'], true);
    }));
}

/**
 * Counts appointments awaiting review (status 'pending') across the whole
 * site — used for the admin sidebar badge. Reads only the lightweight
 * index, never opens individual appointment files.
 */
function bk_count_pending(): int
{
    $count = 0;
    foreach (bk_load_index() as $entry) {
        if (($entry['status'] ?? '') === 'pending') $count++;
    }
    return $count;
}

// ─── Availability rules ────────────────────────────────────────────────────

/**
 * Default availability: closed every day until configured in the admin.
 * Weekly keys are ISO day numbers as strings: 1 = Monday ... 7 = Sunday.
 */
function bk_default_availability(): array
{
    return [
        'weekly' => [
            '1' => ['enabled' => true,  'start' => '09:00', 'end' => '18:00', 'lunch_enabled' => false, 'lunch_start' => '12:00', 'lunch_end' => '13:00'],
            '2' => ['enabled' => true,  'start' => '09:00', 'end' => '18:00', 'lunch_enabled' => false, 'lunch_start' => '12:00', 'lunch_end' => '13:00'],
            '3' => ['enabled' => true,  'start' => '09:00', 'end' => '18:00', 'lunch_enabled' => false, 'lunch_start' => '12:00', 'lunch_end' => '13:00'],
            '4' => ['enabled' => true,  'start' => '09:00', 'end' => '18:00', 'lunch_enabled' => false, 'lunch_start' => '12:00', 'lunch_end' => '13:00'],
            '5' => ['enabled' => true,  'start' => '09:00', 'end' => '18:00', 'lunch_enabled' => false, 'lunch_start' => '12:00', 'lunch_end' => '13:00'],
            '6' => ['enabled' => false, 'start' => '09:00', 'end' => '13:00', 'lunch_enabled' => false, 'lunch_start' => '12:00', 'lunch_end' => '13:00'],
            '7' => ['enabled' => false, 'start' => '09:00', 'end' => '13:00', 'lunch_enabled' => false, 'lunch_start' => '12:00', 'lunch_end' => '13:00'],
        ],
        // Exceptions: { "2026-08-15": { "closed": true } }
        //          or { "2026-08-20": { "closed": false, "start": "10:00", "end": "12:00" } }
        // A closed exception blocks the whole day regardless of the weekly rule.
        // A non-closed exception with start/end overrides the weekly hours for that day only.
        // Exceptions do not carry their own lunch break — the weekly day's lunch
        // setting (if any) still applies on an exception day, since a one-off
        // hours change (e.g. a shorter Saturday) doesn't usually mean lunch moves.
        'exceptions' => [],
    ];
}

function bk_load_availability(): array
{
    if (isset($GLOBALS['_bk_availability_cache'])) {
        return $GLOBALS['_bk_availability_cache'];
    }

    $path = bk_availability_path();
    if (!file_exists($path)) {
        $result = bk_default_availability();
        $GLOBALS['_bk_availability_cache'] = $result;
        return $result;
    }

    $raw     = file_get_contents($path);
    $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    $result  = is_array($decoded) ? array_merge(bk_default_availability(), $decoded) : bk_default_availability();

    $GLOBALS['_bk_availability_cache'] = $result;
    return $result;
}

function bk_save_availability(array $availability): bool
{
    bk_ensure_dirs();
    $success = _bk_write_json(bk_availability_path(), $availability);
    if ($success) {
        unset($GLOBALS['_bk_availability_cache']);
    }
    return $success;
}
