# Maintenance Mode Plugin for Synaptik CMS

Puts the site in maintenance mode for visitors while logged-in admins can keep browsing and working normally.

## What it does

When enabled, every front-end request from a non-admin visitor is intercepted early — before routing, before the data layer runs — and replaced with a self-contained 503 maintenance page. Admins logged into the back-office pass through untouched and see the real site.

## How it works

The block logic hooks into the core's `early_request` event, fired as early as possible in `index.php`, right after `functions.php` loads. This means a blocked visitor never pays the cost of template rendering or database reads.

The maintenance page is fully self-contained HTML — no theme header or footer, no external stylesheet. This is intentional: if maintenance is triggered by a broken theme file, the page must still render correctly.

### Exempt paths

Two categories of requests always bypass the block regardless of maintenance mode:

- **Admin panel** — the admin directory (read from `config.json`, supports custom names) is never blocked, so the admin can log in and turn maintenance off.
- **Plugin endpoints** — anything under `/plugins/` passes through, same as the Redirects plugin's own exemption pattern.
- **Static assets** — files with an extension (`.css`, `.js`, `.png`, etc.) are passed through so the maintenance page can load the fonts or images it references.

### Admin session detection

The plugin uses the same `$_SESSION['admin']` flag that the CMS core sets on login. An admin browsing the front-end while logged in always sees the real site.

---

## Configuration

Settings are stored in `data/config.json` and editable from the admin panel.

| Field | Default | Description |
|-------|---------|-------------|
| `enabled` | `false` | Turns maintenance mode on or off |
| `title` | *(empty)* | Page `<h1>` and `<title>`. Falls back to "Under maintenance" |
| `message` | *(built-in)* | Body text shown to visitors. Supports line breaks |
| `bg_color` | `#0f172a` | Page background color |
| `text_color` | `#e2e8f0` | Text color |
| `accent_color` | `#4fa75c` | Icon/accent color |
| `logo_image` | *(empty)* | Relative path to a logo (`files/logo.png`). Replaces the default wrench icon |

### Logo

The logo field accepts a path relative to the CMS root (e.g. `files/logo.png`). It can be set by uploading a new image directly from the admin form, or by picking an existing file from the media library. Allowed formats: JPEG, PNG, GIF, WebP, SVG. The image is displayed at a maximum of 240×120px, fitted with `object-fit: contain` — no cropping needed regardless of aspect ratio.

When no logo is set, a wrench SVG icon is shown in the accent color.

### HTTP response

The maintenance page always returns **HTTP 503** with a `Retry-After: 3600` header so search engine crawlers know the downtime is temporary and don't deindex the site.

---

## CSRF

The admin form uses a stateless HMAC token (SHA-256, 2-hour expiry) stored in `private/maintenance.secret`. The secret is generated once on first use with `random_bytes(32)`. No dependency on PHP session state for token validation.

---

## Data storage

```
plugins/maintenance/
  data/
    config.json       ← settings (enabled, message, colors, logo path)
  private/
    maintenance.secret  ← HMAC signing secret, generated on first use
```

Both directories are protected by `.htaccess` deny-all rules written automatically.
