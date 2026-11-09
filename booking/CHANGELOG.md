# Booking Plugin — Changelog

## [1.1.9] — 2026-11-09

- **Configurable form fields** — the Settings tab now lets you show or hide each client field (first name, last name, email, phone, notes), mark it required or optional, and customise its label and placeholder.
- **Brevo preset in the SMTP tab** — one click fills in the Brevo server, port and encryption (Brevo has a free plan), so only the SMTP login and key remain to enter.
- **Callback date and time side by side** — the two callback fields now share one row on the public form and stack on narrow screens.

## [1.1.8] — 2026-09-28

- **Fixed llms.txt/llms-full.txt asset leak** — the front-end `[booking_form]` resolution hook ran on every non-admin, non-plugin request, including the plain-text `llms.txt`/`llms-full.txt` export. Since `strip_tags()` doesn't touch the literal `[booking_form]` marker, it survived into that text/plain buffer and the hook spliced the widget's `<link>`/`<script>` tags in front of it (with no `</head>` to anchor on, they landed at the very top of the file). The hook now skips `llms.txt`/`llms-full.txt` requests outright and never injects assets into a buffer that has no `</head>`.

## [1.1.7] — 2026-08-23

- **Dashboard widget** — when active, the main admin dashboard now shows pending/upcoming appointment counts and the next 5 appointments, registered through the new core `admin_dashboard` hook.

- **Admin panel security policy compliance** — inline scripts and click/change handlers across the email template editor, availability exceptions, and settings page (type rows, ICS tokens, delete confirmations) replaced with external JS, so the plugin keeps working under the admin panel's stricter script-loading policy.

- **Front-end security policy compliance** — the public-facing booking form's inline configuration script replaced with an inert JSON data island.

- **Double-booking under concurrent requests** — two people booking the same slot at nearly the same moment could both pass the availability check before either saved, double-booking the slot. The check and the save now happen under a single lock.

- **Rate limiting** could lose increments under concurrent requests (read without a lock, only the final write was locked) and never pruned expired entries. Fixed.

- Removed a stale `.htaccess` comment claiming mod_php session directives worked under PHP-FPM — they don't, and the real session settings are already applied in code.

- **Missing file protection added** for the plugin's admin page.

- **Receipt email toggle** — new checkbox in Settings > Sender & notifications: "Send a receipt email to the client on submission". When enabled (default), behaviour is unchanged. When disabled, the acknowledgement email is skipped on submission and the Emails tab hides the corresponding template tab. Useful when appointments are confirmed immediately and the confirmation email alone is sufficient.

- The receipt email tab in the Emails view is now labelled "Receipt acknowledgement" instead of "Request received".

## [1.1.6] — 2026-08-12

### Added

- **Enable callback toggle** — new setting in Settings tab: "Enable callback request option on the booking form". When disabled, the callback checkbox and date/time fields are hidden from the public form entirely. The "Restrict callback to availability hours" sub-option is automatically hidden in the admin UI when callback is disabled. Server-side submission also ignores any `callback_requested` POST value when the setting is off, preventing bypass via crafted requests. Existing bookings with a callback request are unaffected. Default: enabled (preserves existing behaviour).

### Fixed

- Cancellation link in the "request received" email (plain-text path, no custom template) was appearing on the same line as the label text. URL is now on its own line.
- Default pending and confirmed client emails now send as HTML instead of plain-text, so the cancellation link renders as a proper hyperlink.
- Dates in client emails now use the site's active language translated month names instead of always rendering in English.
- `bk_format_date_i18n()`: translated month name was incorrectly passed back through `date()`, causing its characters to be re-interpreted as format tokens (e.g. "Août" producing `AM2026û31`). The format string is now parsed token by token.

### Added

- `[button url="..." label="..."]` shortcodes in custom email templates are converted to inline-styled `<a>` tags compatible with all major email clients.
- `[button]` shortcode now accepts an optional `color="#hex"` attribute to override the default button background color.

## [1.1.5] — 2026-08-09

### Fixed

- Admin folder name is now read from `config.json` instead of being hardcoded. The front-end output buffer no longer fires on admin pages when the admin folder has been renamed.
