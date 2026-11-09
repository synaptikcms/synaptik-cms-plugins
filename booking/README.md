# Booking Plugin — Synaptik CMS

Standalone appointment-booking module: public weekly-availability calendar,
admin approval workflow, email notifications with `.ics` calendar
attachments, and optional phone-callback reminders.

Built on Synaptik CMS's plugin system — installs under `/plugins/booking/`,
integrates into the real admin panel (sidebar entry, full page inside the
standard layout), and is managed entirely from **Admin → Tools →
Extensions**. No manual core file edits required.

---

## Installing

1. **Upload the plugin.** From Admin → Tools → Extensions, use the
   **Install a plugin** upload form and select the plugin's `.zip` file.
   The ZIP must contain a `plugin.json` manifest at its root with
   `"synaptik_plugin": true`.
   - The plugin is extracted to `/plugins/booking/` but **not activated
     automatically** — this is intentional, mirroring how theme uploads work.
   - If a folder named `plugins/booking/` already exists, the upload is
     refused. Delete the existing plugin first (see *Deleting*) if you're
     reinstalling or replacing it.
2. **Activate it.** Still on the Extensions page, click **Activate** on the
   Booking card. This makes it start handling requests immediately and adds
   a **Booking** entry to the main admin sidebar.
3. **Configure it.** Open the **Booking** sidebar entry → **Settings** tab:
   - Set the notification email address — required to receive new booking
     requests.
   - Review/adjust appointment types (defaults: "First appointment" 90 min,
     "Follow-up appointment" 45 min). Add, rename, or remove types as needed.
4. **Set your availability.** Booking → **Availability** tab:
   - Weekly recurring hours per day (closed by default on weekends).
   - Date-specific exceptions — mark a date fully closed, or override its
     hours for that day only (holidays, special hours, etc.).
5. **Add the booking form to a page.** In the content editor, insert the
   shortcode `[booking_form]` anywhere in an article, page, or project. The
   calendar widget and form render automatically at that spot on the
   public-facing page.

---

## Using it day to day

**Client side** — visitor picks an appointment type, browses the calendar
week by week, clicks an open slot, fills in name/email/phone, optionally
checks "I would also like to be called back" with a preferred date/time.
On submit they receive a confirmation email (status: *pending*) with an
`.ics` file for their calendar.

**Admin side** — Booking → **Requests** tab:
- **Confirm** a pending request → the client gets a confirmation email with
  `.ics`; you (admin) already received a notification email when the
  request came in.
- **Refuse** a pending request → the slot is released, the client gets a
  polite refusal email (no `.ics`, nothing added to their calendar).
- **Cancel** a previously confirmed appointment → the client is notified.
- **Delete** a cancelled/refused appointment permanently removes its record.

Booking → **Calendar** tab gives a month-view overview with pending/
confirmed counts per day, linking into the filtered request list.

**Phone callback reminders** — if a client requests one, you get a
dedicated admin notification email with its own `.ics` (15-minute event,
built-in alarm) at the time the *request* comes in — independent of
whether you confirm or refuse the actual appointment.

---

## Deleting the plugin

From Extensions, a plugin can only be deleted while **inactive** —
deactivate it first. Deleting removes `/plugins/booking/` entirely,
including its data (appointments, availability, settings) and secrets.
This is permanent; there is no undo.

---

## Architecture

```
plugins/booking/
├── plugin.json                    Manifest (name, slug, version, entry point)
├── booking-init.php                Plugin entry point — see "How it hooks
│                                    into the CMS" below
├── booking-data-layer.php          Appointments & availability storage (JSON)
├── booking-functions.php           Settings, appointment types, slot calculator,
│                                    URL helpers (sub-directory-safe)
├── booking-security.php            CSRF + rate limiting (isolated from core)
├── booking-mail.php                Email sending + .ics attachments
├── booking-ics.php                 .ics generation (RFC 5545)
├── booking-i18n.php                Minimal i18n (follows core's active_language
│                                    / admin_language automatically)
├── booking-shortcode.php           [booking_form] public HTML rendering
├── booking.php                     Public AJAX endpoint (slot lookup, CSRF token)
├── booking-submit.php              Public form submission handler (POST)
├── data/                           Plugin's own data store — created
│                                    automatically, .htaccess-protected
├── private/                        CSRF secret + rate-limit store — created
│                                    automatically, .htaccess-protected
├── admin/
│   ├── admin-page.php               Exposes booking_render_admin_page(),
│   │                                 the contract read by core's
│   │                                 admin/plugin-page.php router
│   ├── actions.php                  POST-only endpoint: confirm/refuse/
│   │                                 cancel/delete, save settings/availability
│   ├── bootstrap.php                Auth check + shared includes for actions.php
│   ├── index.php                    Legacy redirect (old bookmarks →
│   │                                 admin/plugin-page.php)
│   └── templates/                   calendar.php, requests.php,
│                                     availability.php, settings.php
├── assets/
│   ├── css/booking-form.css         Public form styles
│   ├── css/booking-admin-embed.css  Admin view styles (loaded inside the
│   │                                 core admin layout)
│   └── js/booking-form.js           Public form widget (vanilla JS)
└── lang/                            Translations — en/fr/es, front + admin
```

### How it hooks into the CMS

**Front-end shortcode.** The core's `render_content_html()` shortcode
pipeline has no filter hook inside it, so there's no clean way to register
a new `[shortcode]` without editing core files. Instead, `booking-init.php`
buffers the entire page output and resolves `[booking_form]` right before
the response reaches the browser — the shortcode passes through the core
pipeline untouched (no parser recognizes it) and gets replaced here. This
only runs on front-end HTML responses, never on admin/AJAX/CLI requests.

**Admin integration.** The plugin registers its sidebar entry via the
`admin_menu` hook (`pl_on_admin_menu()`, see the CMS's `plugin-api.php`)
and exposes `booking_render_admin_page($view)`, which the core's
`admin/plugin-page.php` router calls to render the plugin's page *inside*
the standard admin layout — same sidebar, top bar, and footer as every
other admin screen. The plugin doesn't implement its own admin chrome.

**Session reuse.** No separate login system: `admin/actions.php` checks
`$_SESSION['admin'] === true`, the same session flag the core admin sets.
Logging out of one logs out of both.

**Sub-directory safety.** All plugin URLs are built from the plugin's own
filesystem position (`bk_site_base_url()`, `bk_plugin_base_url()`,
`bk_admin_page_url()` in `booking-functions.php` / `admin/admin-page.php`)
rather than assuming the CMS lives at the domain root — works whether the
CMS is installed at `https://example.com/` or `https://example.com/cms/`.

---

## Maintenance notes

- **Appointment type keys must stay stable.** The `key` (internal slug) of
  an appointment type is what's stored on each appointment record. Renaming
  the *label* is safe; changing the *key* breaks the link to existing
  appointments using that type — the label falls back to showing the raw key.
- **Rate limiting and CSRF are independent from the core contact form.**
  5 submissions per IP per hour, 2-hour CSRF token TTL, stored separately in
  `plugins/booking/private/` — never shared with the core's own contact-form
  state.
- **Adding a language:** copy `lang/en.json` → `lang/{locale}.json` and
  `lang/admin/en.json` → `lang/admin/{locale}.json`, translate the values.
  The plugin automatically follows the core's `active_language` (front) and
  `admin_language` (admin) settings — no extra configuration needed.
- **Distribution.** This plugin is excluded from the core CMS's public
  release export (`export-release.sh` excludes `/plugins/` and
  `plugins.json`) — it ships and updates independently of core releases.
