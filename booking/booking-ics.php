<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Booking Plugin — ICS Generator
 *
 * Minimal RFC 5545 (iCalendar) generator for appointment and callback
 * reminder invites. No external library — a booking event is simple enough
 * (single VEVENT, no recurrence) that a small hand-rolled generator keeps
 * the plugin dependency-free, in line with the CMS's lightweight footprint.
 */

if (defined('BK_ICS_LOADED')) return;
define('BK_ICS_LOADED', true);

/**
 * Escapes text per RFC 5545 §3.3.11 (comma, semicolon, backslash, newline).
 */
function _bk_ics_escape(string $text): string
{
    $text = str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', ''], $text);
    return $text;
}

/**
 * Folds a content line at 75 octets as required by RFC 5545 §3.1, so long
 * SUMMARY/DESCRIPTION values don't break strict calendar clients.
 */
function _bk_ics_fold(string $line): string
{
    if (strlen($line) <= 75) return $line;

    $folded = '';
    while (strlen($line) > 75) {
        $folded .= substr($line, 0, 75) . "\r\n ";
        $line = substr($line, 75);
    }
    return $folded . $line;
}

/**
 * Builds a single VEVENT block as a raw .ics string.
 *
 * @param string $uid          Globally unique identifier for this event
 * @param string $summary      Event title
 * @param string $description  Event body text
 * @param string $startDateTime 'Y-m-d H:i' in the site's local timezone
 * @param int    $durationMinutes
 * @param string $timezone     PHP timezone identifier (e.g. 'Europe/Paris')
 * @param string $organizerEmail
 * @param string $attendeeEmail
 * @param string $location
 * @param string $url          Optional URL attached to the event (RFC 5545 §3.8.4.6)
 */
function bk_build_ics(
    string $uid,
    string $summary,
    string $description,
    string $startDateTime,
    int $durationMinutes,
    string $timezone,
    string $organizerEmail,
    string $attendeeEmail = '',
    string $location = '',
    string $url = ''
): string {
    $tz    = new DateTimeZone($timezone);
    $start = new DateTime($startDateTime, $tz);
    $end   = (clone $start)->modify("+{$durationMinutes} minutes");

    // DTSTAMP/UID are generated in UTC — required for interoperability across
    // calendar clients regardless of the event's own local timezone.
    $dtstamp = (new DateTime('now', new DateTimeZone('UTC')))->format('Ymd\THis\Z');

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//SynaptikCMS//Booking Plugin//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . _bk_ics_escape($uid),
        'DTSTAMP:' . $dtstamp,
        'DTSTART;TZID=' . $timezone . ':' . $start->format('Ymd\THis'),
        'DTEND;TZID=' . $timezone . ':' . $end->format('Ymd\THis'),
        'SUMMARY:' . _bk_ics_escape($summary),
    ];

    if ($description !== '') {
        $lines[] = 'DESCRIPTION:' . _bk_ics_escape($description);
    }
    if ($location !== '') {
        $lines[] = 'LOCATION:' . _bk_ics_escape($location);
    }
    if ($url !== '') {
        $lines[] = 'URL:' . _bk_ics_escape($url);
    }
    if ($organizerEmail !== '') {
        $lines[] = 'ORGANIZER:mailto:' . $organizerEmail;
    }
    if ($attendeeEmail !== '') {
        $lines[] = 'ATTENDEE;RSVP=FALSE:mailto:' . $attendeeEmail;
    }

    // A 15-minute reminder alarm, useful for the callback reminder use case
    // in particular — but harmless to include on regular appointments too.
    $lines[] = 'BEGIN:VALARM';
    $lines[] = 'ACTION:DISPLAY';
    $lines[] = 'DESCRIPTION:' . _bk_ics_escape($summary);
    $lines[] = 'TRIGGER:-PT15M';
    $lines[] = 'END:VALARM';

    $lines[] = 'STATUS:CONFIRMED';
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    $folded = array_map('_bk_ics_fold', $lines);

    return implode("\r\n", $folded) . "\r\n";
}

/**
 * Writes an .ics string to a temp file and returns the path, for use as a
 * mail attachment. Caller is responsible for cleanup after sending.
 */
function bk_write_ics_tmp(string $icsContent, string $filenameHint): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '-', $filenameHint);
    $path = sys_get_temp_dir() . '/bk-' . $safe . '-' . bin2hex(random_bytes(4)) . '.ics';
    file_put_contents($path, $icsContent);
    return $path;
}
