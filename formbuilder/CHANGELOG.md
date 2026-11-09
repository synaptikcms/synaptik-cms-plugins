# Form Builder Plugin — Changelog

## [1.0.5] — 2026-09-28

- **Fixed llms.txt/llms-full.txt asset leak** — the front-end `[form id="..."]` resolution hook ran on every non-admin, non-plugin request, including the plain-text `llms.txt`/`llms-full.txt` export. A page whose raw content contained the literal shortcode marker could have the form's `<link>`/`<script>` assets spliced into that text/plain response. The hook now skips `llms.txt`/`llms-full.txt` requests outright and never injects assets into a buffer that has no `</head>`.

## [1.0.4] — 2026-09-08

- **Side-by-side fields** — any field can now be set to half width in the editor, so two fit on one line instead of each taking a full row.

- **Separator field** — a new field type that inserts a blank space or a horizontal line between fields, with no input of its own.

---

## [1.0.3] — 2026-08-23

- **Admin panel security policy compliance** — inline scripts and click handlers across the form editor, forms list (copy/delete), and submissions view (filter/delete) replaced with external JS, so the plugin keeps working under the admin panel's stricter script-loading policy.

- **Front-end security policy compliance** — the public-facing form's inline configuration script replaced with an inert JSON data island.

- **Rate limiting** could lose increments under concurrent requests (read without a lock, only the final write was locked) and never pruned expired entries. Fixed. 

---

## [1.0.2] — 2026-08-12

### Fixed

- PDF recap: long field values now wrap correctly. The character-width estimate used to compute `maxChars` was too optimistic (factor 0.52); raised to 0.60 to match real Helvetica metrics. Words longer than the line width are now hard-broken character by character instead of overflowing the margin.
- PDF recap: title and submission date were overlapping. The cursor was only dropping 6pt after the title regardless of its font size (16pt); it now drops `fontSize + gap` so each text block clears the one above it.
- HTTP 500 on form submission: the submit handler was requiring `functions.php` at the CMS root, which no longer exists since the core v1.3.3 restructure (moved to `/core/`). Removed the core dependency entirely; `config.json` is now read directly via `fb_core_config()`, matching the pattern used by all other plugins.

---

## [1.0.1] — 2026-07-01

### Fixed

- CSS fixes.

## [1.0.0] — 2026-07-01

### Initial release

- Drag-and-drop form builder with field types: short text, long text, email, phone, number, dropdown, checkbox.
- Required field validation, dropdown option enforcement, field length cap (2000 chars).
- Stateless HMAC-SHA256 CSRF tokens, honeypot, timing check, referrer validation, IP rate limiting (5/hour).
- Submissions saved to disk as JSON (`data/submissions/{form_id}/`).
- PDF recap generated server-side (no dependencies) and emailed to the form recipient on each submission.
- Configurable recipient email per form; falls back to the site's `contact_email` from `config.json`.
- Admin panel: form list, field editor, submissions viewer with per-form and global views.
- i18n: EN, FR, ES for both front-end and admin strings.
