# Form Builder Plugin — Synaptik CMS

Custom form builder: create forms with text, long text, email, phone,
number, dropdown, and checkbox fields through a drag-and-drop admin editor.
Every form gets its own shortcode. Submissions are always saved on the
server and can optionally be emailed as a PDF recap.

Built on Synaptik CMS's plugin system — installs under
`/plugins/formbuilder/`, integrates into the real admin panel (sidebar
entry, full page inside the standard layout), and is managed entirely from
**Admin → Tools → Extensions**. No manual core file edits required.

The PDF generator is fully self-contained — no third-party library
(Dompdf, TCPDF, etc.) is bundled or required. It writes a valid PDF 1.4
document directly using the standard Helvetica base font, which every PDF
viewer already has built in.

---

## Features

- **Field types:** short text, long text (textarea), email, phone, number,
  dropdown (with custom options), and checkbox.
- **Per-field settings:** label, internal key (used in the PDF and as the
  form field name), required/optional toggle. Checkbox fields are always
  optional — an unchecked box already carries meaning.
- **Drag-and-drop field reordering** in the editor — grab the handle on the
  left of a field row and drop it above or below another row. No need to
  delete and recreate fields to insert one in the middle.
- **One shortcode per form:** `[form id="your-form-id"]`, insertable in any
  article, page, or project, and reusable across as many pages as needed.
- **Every submission is saved on the server**, regardless of email
  settings — browsable and searchable from the admin's Submissions tab,
  filterable by form.
- **Optional email notification per form** — a checkbox on the form editor
  ("Receive submissions by email") controls whether a notification is sent
  at all. When enabled, each submission is emailed as a PDF recap to either
  a per-form recipient address or the site's default contact email.
- **PDF recap** — title (form name), submission date, and every field as a
  "Label: value" block, with automatic word-wrap and pagination for long
  forms. Re-downloadable at any time from the Submissions tab, even for
  submissions to forms where email notification is off.
- **Custom success message per form**, shown inline once the visitor
  submits (no page reload — the form is replaced with the message and its
  fields are cleared).
- **Security:** honeypot field, minimum-delay check, same-origin referrer
  check, CSRF token (2-hour TTL), and per-IP rate limiting (5 submissions/
  hour) — all independent from the core contact form's own state.

---

## Installing

1. **Upload the plugin.** From Admin → Tools → Extensions, use the
   **Install a plugin** upload form and select the plugin's `.zip` file.
   The ZIP must contain a `plugin.json` manifest at its root with
   `"synaptik_plugin": true`.
   - The plugin is extracted to `/plugins/formbuilder/` but **not activated
     automatically** — this is intentional, mirroring how theme uploads work.
   - If a folder named `plugins/formbuilder/` already exists, the upload is
     refused. Delete the existing plugin first (see *Deleting*) if you're
     reinstalling or replacing it.
2. **Activate it.** Still on the Extensions page, click **Activate** on the
   Form Builder card. This makes it start handling requests immediately and
   adds a **Form Builder** entry to the main admin sidebar.
3. *(Optional)* **Set a default sender identity.** Form Builder → Settings
   tab — sender email/name used on outgoing notification emails. Leave
   blank to fall back to `noreply@` your domain and the site title.

---

## Using it day to day

### Creating a form

1. Form Builder → **Forms** tab → **New form**.
2. Give it a **name** (shown in the admin list and used as the PDF title
   and email subject).
3. **Receive submissions by email** — checked by default. Uncheck it if you
   only want submissions archived on the server without any email going
   out (e.g. a form you check manually from time to time).
4. **Recipient email** *(only relevant while the checkbox above is on)* —
   leave empty to send to the site's default contact email
   (Settings → Contact in the core admin), or set a specific address for
   this form.
5. **Success message** — shown to the visitor once they submit. Leave empty
   to use the default "Thank you, your submission has been received."
6. **Fields** — click **Add field** for each field you need:
   - **Label** — shown to visitors.
   - **Key** — internal identifier (lowercase letters, numbers,
     underscores). Used as the PDF row label fallback and the submitted
     form field name. Two fields can't share the same key — a duplicate is
     silently dropped on save.
   - **Type** — Text, Long text, Email, Phone, Number, Dropdown, or
     Checkbox.
   - **Dropdown options** — only shown for the Dropdown type, one option
     per line.
   - **Required** — disabled automatically for Checkbox fields.
   - Drag the handle (⠿) on the left of a row to reorder it.
7. **Save form.** You'll land back on the Forms list, where the new
   shortcode is shown — click it to copy.

### Publishing the form

Paste `[form id="your-form-id"]` anywhere in an article, page, or project's
content. The form renders automatically at that spot on the public page —
CSS and JS are only loaded on pages that actually contain the shortcode.

### Reviewing submissions

Form Builder → **Submissions** tab:
- Filter by form using the dropdown, or view all forms at once.
- Each row shows the date, form name, and a short summary (first two text
  values).
- **Download PDF** regenerates and downloads the recap on demand — the PDF
  itself is never stored on disk, only the raw submitted values are, so
  this works even for a form with email notification turned off.
- **Delete** permanently removes a submission's stored record. This does
  not affect any email already sent.

### Editing or deleting a form

Form Builder → **Forms** tab → **Edit** to change name, recipient,
fields, or field order at any time — existing submissions already on disk
keep their original recorded values regardless of later field changes.
**Delete** removes the form definition itself; past submissions to that
form are kept and remain browsable from the Submissions tab (filterable by
selecting "All forms" once the form itself no longer appears in the
per-form filter).

---

## Deleting the plugin

From Extensions, a plugin can only be deleted while **inactive** —
deactivate it first. Deleting removes `/plugins/formbuilder/` entirely,
including every form definition, every stored submission, and secrets.
This is permanent; there is no undo.

---

## Architecture

```
plugins/formbuilder/
├── plugin.json                     Manifest (name, slug, version, entry point)
├── formbuilder-init.php             Plugin entry point — see "How it hooks
│                                     into the CMS" below
├── formbuilder-data-layer.php       Form schema & submission storage (JSON)
├── formbuilder-functions.php        URL helpers (sub-directory-safe),
│                                     sender address resolution
├── formbuilder-security.php         CSRF + rate limiting (isolated from core)
├── formbuilder-pdf.php              Dependency-free PDF 1.4 writer +
│                                     submission-to-PDF builder
├── formbuilder-mail.php             Email sending with the PDF attached
├── formbuilder-i18n.php             Minimal i18n (follows core's
│                                     active_language / admin_language
│                                     automatically)
├── formbuilder-shortcode.php        [form id="x"] public HTML rendering
├── formbuilder-submit.php           Public form submission handler (POST)
├── data/                            Plugin's own data store — created
│                                     automatically, .htaccess-protected
│   ├── forms/                        One JSON file per form + _index.json
│   └── submissions/{form_id}/        One JSON file per submission
├── private/                         CSRF secret + rate-limit store — created
│                                     automatically, .htaccess-protected
├── admin/
│   ├── admin-page.php                Exposes formbuilder_render_admin_page(),
│   │                                  the contract read by core's
│   │                                  admin/plugin-page.php router
│   ├── actions.php                   POST-only endpoint: save/delete form,
│   │                                  delete submission, save settings
│   ├── bootstrap.php                 Auth check + shared includes for actions.php
│   ├── download-pdf.php              On-demand PDF regeneration + download
│   └── templates/
│       ├── forms.php                  Forms list
│       ├── edit.php                   Form editor (name, recipient, fields)
│       ├── submissions.php            Submissions list, filterable by form
│       ├── settings.php               Default sender identity
│       └── partials/field-row.php     Single field row, shared between the
│                                       initial render and the JS row template
├── assets/
│   ├── css/formbuilder-form.css       Public form styles
│   ├── css/formbuilder-admin-embed.css Admin view styles (loaded inside the
│   │                                   core admin layout)
│   ├── js/formbuilder-form.js         Public form widget — AJAX submit,
│   │                                   inline success/error message
│   └── js/formbuilder-editor.js       Admin field editor — add/remove/
│                                       reorder rows, dropdown-options toggle
└── lang/                              Translations — en/fr/es, front + admin
```

### How it hooks into the CMS

**Front-end shortcode.** The core's `render_content_html()` shortcode
pipeline has no filter hook inside it, so there's no clean way to register
a new `[shortcode]` without editing core files. Instead, `formbuilder-init.php`
buffers the entire page output and resolves every `[form id="..."]`
occurrence right before the response reaches the browser — the shortcode
passes through the core pipeline untouched (no parser recognizes it) and
gets replaced here. This only runs on front-end HTML responses, never on
admin/AJAX/CLI requests.

**Admin integration.** The plugin registers its sidebar entry via the
`admin_menu` hook (`pl_on_admin_menu()`, see the CMS's `plugin-api.php`)
and exposes `formbuilder_render_admin_page($view)`, which the core's
`admin/plugin-page.php` router calls to render the plugin's page *inside*
the standard admin layout — same sidebar, top bar, and footer as every
other admin screen. The plugin doesn't implement its own admin chrome.

**Session reuse.** No separate login system: `admin/actions.php` and
`admin/download-pdf.php` check `$_SESSION['admin'] === true`, the same
session flag the core admin sets. Logging out of one logs out of both.

**Sub-directory safety.** All plugin URLs are built from the plugin's own
filesystem position (`fb_site_base_url()`, `fb_plugin_base_url()`,
`fb_admin_page_url()` in `formbuilder-functions.php` / `admin/admin-page.php`)
rather than assuming the CMS lives at the domain root — works whether the
CMS is installed at `https://example.com/` or `https://example.com/cms/`.

**Field order = submission order.** Reordering fields in the editor is a
pure drag-and-drop DOM operation — the save endpoint reads every
`field_label[]` / `field_key[]` / etc. array in the order they appear in
the submitted HTML, so no separate "position" value needs to be tracked.

### PDF generation without a third-party library

`formbuilder-pdf.php` writes the PDF byte structure by hand: catalog, page
tree, font resources (standard Helvetica, no font file needed), one content
stream per page, cross-reference table, and trailer. Scope is deliberately
narrow — a title, a submission date, and a list of label/value rows with
word-wrap and automatic pagination. This is enough for a form recap; it is
not a general-purpose PDF layout engine, and there is no support for
images, tables, or custom fonts.

---

## Maintenance notes

- **Field keys should stay stable once a form is live.** The `key` is what
  a submission's stored `values` object is keyed by, and what appears as
  the label fallback in the PDF. Renaming the *label* is always safe;
  changing the *key* only affects future submissions — past ones keep the
  old key in their stored record.
- **`notify_by_email` is opt-out, not opt-in, for backward compatibility.**
  A form saved before this setting existed has no such key in its stored
  JSON; `fb_notify_submission()` treats a missing key as "send" so no
  existing form silently stops notifying after an update.
- **Rate limiting and CSRF are independent from the core contact form.**
  5 submissions per IP per hour, 2-hour CSRF token TTL, stored separately in
  `plugins/formbuilder/private/` — never shared with the core's own
  contact-form state.
- **Adding a language:** copy `lang/en.json` → `lang/{locale}.json` and
  `lang/admin/en.json` → `lang/admin/{locale}.json`, translate the values.
  The plugin automatically follows the core's `active_language` (front) and
  `admin_language` (admin) settings — no extra configuration needed.
- **Distribution.** This plugin is excluded from the core CMS's public
  release export (`export-release.sh` excludes `/plugins/` and
  `plugins.json`) — it ships and updates independently of core releases.
