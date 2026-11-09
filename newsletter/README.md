# Newsletter Plugin

Email newsletter with double opt-in, a free-form composer (reuses the CMS's
own rich-text editor), a quick article-digest sender, and send history.

## Features

- `[newsletter_signup]` shortcode — email signup form, with an optional name field (Settings → Name field)
- `{name}` (alias `{nom}`), `{email}` and `{site_name}` variables in the composer and the digest template, resolved per recipient
- Double opt-in: a confirmation email must be clicked before a subscriber is counted
- One-click unsubscribe link in every email (no login required)
- **Composer**: write a free-form HTML newsletter using the exact same
  WYSIWYG editor as CMS articles (`editor-common.js` + `editor.js`, loaded
  from the core admin assets — not reimplemented)
- **Quick digest**: one-click send of every article published since the
  last digest, as a simple linked list
- Both send modes go out in small batches (20 recipients per request) to
  stay well under shared-hosting execution-time and mail-rate limits — no
  cron required
- **History**: every sent newsletter (custom or digest) is recorded with
  subject, recipient count, and send date; deletable individually

## Storage

- `data/subscribers.json` — single flat file, keyed by subscriber ID.
- `data/newsletters.json` — send history, keyed by newsletter ID. Written
  once per send, when the last batch completes — there is no separate
  draft-saving step; composing happens live in the browser and only
  persists once "Send" is clicked.
- `data/config.json` — sender identity + `last_digest_sent_at`

All three are `.htaccess`-protected on first write, same as the CMS core.

## Security

- CSRF: stateless HMAC token (`{timestamp}.{hmac}`, 2 h TTL)
- Confirm/unsubscribe links: stateless HMAC token bound to `{subscriber_id}|{action}`,
  no expiry (an unsubscribe link must keep working indefinitely)
- Signup form: honeypot + timing check + referrer check + CSRF + IP rate limit
  (5 attempts/hour), mirroring the core contact form and the Booking plugin
- IPs are stored as SHA-256 hashes, never in plaintext

## Admin

Two views, both routed through `admin/index.php?action=plugin_page&slug=newsletter`:
- `view=dashboard` (default) — stats, quick digest, history, subscriber list, settings
- `view=compose` — the free-form editor

`admin/admin-page.php` manually injects the core's editor assets
(`editor-common.js`, `editor.js`, `editor-layout.css`) on the compose view
only, since the plugin-page route isn't recognized by the core's own
content-editor asset detection (`admin/includes/layout.php`). Autosave is
explicitly disabled in that context (`window.AUTOSAVE_ENABLED_BY_SETTINGS
= false`) — the core's autosave targets `admin/autosave.php`, which drafts
CMS content items, not newsletters.

The composer's "Send" button reads the editor's live-synced
`#content-hidden` value directly via JS; there is no real form submission
to a server endpoint — sending is entirely AJAX-driven
(`send_custom_batch`), same mechanism as the quick digest.

## Mail

HTML emails via PHP's native `mail()` (multipart/alternative with an
auto-generated plain-text fallback), consistent with the rest of the CMS
using no external mail library. If deliverability becomes a real concern
at higher subscriber counts, the failure point would be
`nl_send_html_mail()` in `newsletter-mail.php` — swapping in SMTP later
only touches that one function.
