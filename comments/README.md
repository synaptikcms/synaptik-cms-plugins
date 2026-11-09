# Comments

Native comments for Synaptik CMS — no external service, no database,
flat-file storage matching the CMS's own split-file architecture.

## Why

A blog or portfolio site often needs a way for visitors to react to
content without outsourcing that to Disqus or a similar third-party
widget, which pulls in tracking scripts and an external dependency for
something the CMS can do itself in a few flat JSON files.

## How it works

- **One JSON file per commented item** (`data/{type}-{slug}.json`), not a
  single growing global store — a page with zero comments never creates
  a file.
- **One level of replies**: a comment can be replied to once; a reply to
  a reply is rejected server-side. Keeps both the data shape and the
  rendered thread simple.
- **Moderation**: manual (default — every comment starts `pending`) or
  auto-publish, configurable in the plugin's settings. Either way,
  comments can be approved, rejected, or deleted from the admin
  dashboard at any time.
- **Enabled per content type, site-wide**: three checkboxes in
  Settings — Articles / Pages / Projects. The `[comments]` shortcode
  itself must still be added to the theme's template
  (`content-articles.php`, etc.) for anything to render; this plugin
  never modifies theme or core files to inject it automatically.
- **Spam protection**: honeypot field, minimum-time-on-page check,
  same-origin referrer check, stateless HMAC CSRF token, IP rate
  limiting (5 comments/hour), a configurable keyword blacklist, and
  optional hCaptcha — reusing the site's own hCaptcha keys from
  Settings → Contact rather than asking for a second configuration.

## Shortcode

```
[comments]
```

Self-detects the current article/page/project from the core's own
routing context. For themes calling it from a non-standard context,
explicit attributes override that detection:

```
[comments type="article" slug="my-post"]
```

## Admin

Admin → Extensions → Comments.

- **Dashboard**: moderation queue (pending first), filterable by status,
  with approve/reject/delete actions.
- **Settings**: moderation mode, enabled content types, keyword
  blacklist, hCaptcha toggle.

## Data

- `data/{type}-{slug}.json` — one file per commented item:
  `{ "c_xxxxxxxx": { author_name, author_email, content, status,
  created_at, parent_id, ip_hash } }`
- `data/config.json` — `{ moderation_mode, enabled_types, blacklist,
  use_hcaptcha }`
- `private/comments.secret` — HMAC secret for CSRF tokens (stateless,
  independent of the core session)
- `private/comments_rate.json` — IP rate-limit store (IPs hashed, never
  stored in plaintext)

Author email is collected and stored for the site owner's own reference
in the admin dashboard but is never rendered on the public-facing page.
