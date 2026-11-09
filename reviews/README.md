# Reviews Plugin — Synaptik CMS

Standalone customer-reviews module: admin-managed review list (author,
star rating, text, optional avatar), displayed on the front end as a
carousel or grid via shortcode. No external dependency, no API key, no
database — everything is a flat JSON file, same as CMS core content.

Built on Synaptik CMS's plugin system — installs under `/plugins/reviews/`,
integrates into the real admin panel (sidebar entry, full page inside the
standard layout), and is managed entirely from **Admin → Tools →
Extensions**. No manual core file edits required.

---

## Installing

1. **Upload the plugin.** From Admin → Tools → Extensions, use the
   **Install a plugin** upload form and select the plugin's `.zip` file.
   The ZIP must contain a `plugin.json` manifest at its root with
   `"synaptik_plugin": true`.
   - The plugin is extracted to `/plugins/reviews/` but **not activated
     automatically** — this is intentional, mirroring how theme uploads work.
   - If a folder named `plugins/reviews/` already exists, the upload is
     refused. Delete the existing plugin first if you're reinstalling.
2. **Activate it.** Still on the Extensions page, click **Activate** on the
   Reviews card. This adds a **Reviews** entry to the main admin sidebar.
3. **Add some reviews.** Open the **Reviews** sidebar entry and use the
   "Add a review" form: customer name, star rating, review text, an
   optional date and avatar image, and a status (Published / Draft).
4. **Display them.** In the content editor, insert one of the shortcodes
   below anywhere in an article, page, or project. The widget renders
   automatically at that spot on the public-facing page.

---

## Shortcodes

```
[reviews_carousel]
[reviews_grid]
[reviews_carousel limit="6" min_rating="4"]
[reviews_grid limit="3"]
```

| Attribute | Values | Default | Description |
|---|---|---|---|
| `limit` | integer | plugin setting (default 10) | Max number of reviews shown |
| `min_rating` | 1–5 | 1 | Only show reviews at or above this star rating |

Only **published** reviews are ever rendered — a review saved with status
"Draft" is visible in the admin list but never appears on the front end,
so a testimonial can be prepared and reviewed before going live.

If no published review matches the given `min_rating`, the shortcode
renders nothing (no empty widget shell) rather than an empty carousel.

---

## Using it day to day

**Admin side** — Reviews sidebar entry:
- **Add / edit** — a single form handles both; author, rating (1–5),
  review text, date, optional avatar upload, and status.
- **Reorder** — drag rows in the review list to change their display
  order; the new order is saved automatically and used by both shortcodes.
- **Delete** — removes the review and its avatar file, if any.
- **Settings** — set the default layout (carousel or grid) and default
  number of reviews shown, used whenever a shortcode omits `limit`/`style`.

**Avatar uploads** are optional. If skipped, the widget shows a colored
initial-letter placeholder instead. Accepted formats: JPEG, PNG, WebP,
GIF — max 2 MB, validated by actual file content (not just the file
extension) before being stored.

---

## Deleting the plugin

From Extensions, a plugin can only be deleted while **inactive** —
deactivate it first. Deleting removes `/plugins/reviews/` entirely,
including all review data and uploaded avatars. This is permanent; there
is no undo.

---

## Architecture

```
plugins/reviews/
├── plugin.json                    Manifest (name, slug, version, entry point)
├── reviews-init.php                Plugin entry point — see "How it hooks
│                                    into the CMS" below
├── reviews-data-layer.php          Review storage (JSON), CRUD, sorting,
│                                    average rating calculation
├── reviews-functions.php           Settings, URL helpers (sub-directory-safe),
│                                    avatar upload validation & storage
├── reviews-security.php            CSRF (isolated from core) — admin-only,
│                                    no public submission form to protect
├── reviews-i18n.php                Minimal i18n (follows core's active_language
│                                    / admin_language automatically)
├── reviews-shortcode.php           [reviews_carousel] / [reviews_grid]
│                                    public HTML rendering
├── data/                           Plugin's own data store — created
│                                    automatically, .htaccess-protected
├── private/                        CSRF secret — created automatically,
│                                    .htaccess-protected
├── admin/
│   ├── admin-page.php               Exposes reviews_render_admin_page(),
│   │                                 the contract read by core's
│   │                                 admin/plugin-page.php router
│   ├── actions.php                  POST-only endpoint: create/update/
│   │                                 delete/reorder review, save settings
│   ├── bootstrap.php                Auth check + shared includes for actions.php
│   └── templates/dashboard.php      Stats, add/edit form, review list,
│                                     shortcode reference, settings
├── assets/
│   ├── css/reviews-front.css        Public carousel/grid styles (namespaced
│   │                                 --rv-* to avoid theme collisions)
│   ├── css/reviews-admin-embed.css  Admin view styles (loaded inside the
│   │                                 core admin layout, aliased onto the
│   │                                 core's own design tokens)
│   ├── js/reviews-carousel.js       Public carousel widget — nav, dots,
│   │                                 touch swipe, no dependency
│   ├── js/reviews-admin.js          Admin drag-and-drop reordering, native
│   │                                 HTML5 drag API, no dependency
│   └── uploads/                     Avatar images — PHP execution disabled
│                                     via .htaccess regardless of upload
│                                     validation (defense in depth)
└── lang/                            Translations — en/fr/es, front + admin
```

### How it hooks into the CMS

**Front-end shortcodes.** The core's `render_content_html()` shortcode
pipeline has no filter hook inside it, so there's no clean way to register
a new `[shortcode]` without editing core files. Instead, `reviews-init.php`
buffers the entire page output and resolves `[reviews_carousel]` /
`[reviews_grid]` right before the response reaches the browser — the
shortcodes pass through the core pipeline untouched (no parser recognizes
them) and get replaced here. This only runs on front-end HTML responses,
never on admin/AJAX/CLI requests.

**Admin integration.** The plugin registers its sidebar entry via the
`admin_menu` hook (`pl_on_admin_menu()`, see the CMS's `plugin-api.php`)
and exposes `reviews_render_admin_page($view)`, which the core's
`admin/plugin-page.php` router calls to render the plugin's page *inside*
the standard admin layout — same sidebar, top bar, and footer as every
other admin screen. The plugin doesn't implement its own admin chrome.

**Session reuse.** No separate login system: `admin/actions.php` checks
`$_SESSION['admin'] === true`, the same session flag the core admin sets.
Logging out of one logs out of both.

**Sub-directory safety.** All plugin URLs are built from the plugin's own
filesystem position (`rv_site_base_url()`, `rv_plugin_base_url()`,
`rv_admin_page_url()` in `reviews-functions.php` / `admin/admin-page.php`)
rather than assuming the CMS lives at the domain root — works whether the
CMS is installed at `https://example.com/` or `https://example.com/cms/`.

---

## Roadmap: importing reviews from Google

A "pull reviews from Google" mode is a deliberately separate, future
addition — not implemented here — because it is a materially different
piece of engineering, not just another data source:

- Requires the Google Places API (Place Details, `reviews` field), which
  needs a billed Google Cloud API key (a free monthly quota exists, but a
  card on file is required regardless of usage).
- Google returns **at most 5 reviews per place**, with no pagination — it
  is not possible to pull a full review history through the API.
- Google's Terms of Service **prohibit caching review content for more
  than 30 days** — a one-time import is not compliant; the integration
  would need a recurring refresh (cron or scheduled task) that re-fetches
  and expires stale entries, plus a way to reconcile Google-sourced
  reviews with manually-entered ones without duplicating them.

The data layer already reserves a `source` field (`'manual'` today) on
every review record specifically so a future `'google'` source can be
added without a schema migration — but the fetch/cache/refresh/quota
machinery itself is out of scope for this version.

---

## Maintenance notes

- **Only published reviews count toward the average rating** shown in the
  admin stats panel (`rv_get_average_rating()`) — draft reviews are
  excluded, same as they're excluded from the front-end display.
- **Avatar cleanup is automatic.** Deleting a review, or replacing its
  avatar with a new upload, removes the old image file from
  `assets/uploads/` — the directory does not accumulate orphaned files
  over normal use.
- **Reordering is a single JSON write.** Drag-and-drop in the admin list
  posts the full ordered ID list once per drag (`reorder_reviews` in
  `admin/actions.php`), not one request per row moved.
- **Adding a language:** copy `lang/en.json` → `lang/{locale}.json` and
  `lang/admin/en.json` → `lang/admin/{locale}.json`, translate the values.
  The plugin automatically follows the core's `active_language` (front) and
  `admin_language` (admin) settings — no extra configuration needed.
- **Distribution.** This plugin is excluded from the core CMS's public
  release export (`export-release.sh` excludes `/plugins/` and
  `plugins.json`) — it ships and updates independently of core releases.
