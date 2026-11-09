# Newsletter Plugin — Changelog

## [1.0.7] — 2026-11-09

- **Signup form restyled** — the subscribe button now sits to the right of the fields (stacked on mobile), the name field matches the email field, and the form follows the theme's text color.
- **Subscribe button color** — a new Settings option sets the button color, overriding the theme's button style, with the text color adjusted automatically for contrast.
- **Shortcode shown in Settings** — the `[newsletter_signup]` shortcode is displayed in Settings, and the Overview, Compose and Digest sections now explain the difference between a one-off newsletter and the automatic digest.
- **Optional name field on the signup form** — a new Settings option shows a "Full name" field next to the email (off by default; optional or required), and the name is stored with each subscriber and listed in the Subscribers tab.
- **`{name}` variable for newsletters** — insert the subscriber's name in the composer and in the digest template, replaced per recipient at send time (`{nom}` works too); `{email}` and `{site_name}` now work in the composer as well.
- **Brevo preset in the SMTP tab** — one click fills in the Brevo server, port and encryption (Brevo has a free plan), so only the SMTP login and key remain to enter.
- **Fallback text for missing names** — a new setting provides the text inserted when a subscriber has no name, so "Hello {name}," never reads "Hello ,".

## [1.0.6] — 2026-09-28

- **Fixed llms.txt/llms-full.txt asset leak** — the front-end `[newsletter_signup]` resolution hook ran on every non-admin, non-plugin request, including the plain-text `llms.txt`/`llms-full.txt` export. A page whose raw content contained the literal `[newsletter_signup]` marker could have the signup form's `<link>`/`<script>` assets spliced into that text/plain response. The hook now skips `llms.txt`/`llms-full.txt` requests outright and never injects assets into a buffer that has no `</head>`.

## [1.0.5] — 2026-08-23

- **Admin panel security policy compliance** — inline scripts and click handlers across the digest/compose screens, delete confirmations, and the digest token bar replaced with external JS, so the plugin keeps working under the admin panel's stricter script-loading policy.
- **Front-end security policy compliance** — the public-facing signup form's inline configuration script replaced with an inert JSON data island.
- **Editor toolbar icons** — the composer and digest template editors now show the same updated toolbar icons as the main content editor, instead of blank buttons.
- **Tabbed admin interface** — the plugin's admin page is now split across clear tabs (Overview, Compose, Digest email, Subscribers, History, Settings, SMTP) instead of one long scrolling page, matching the Booking and Analytics plugins. The Subscribers tab carries a badge showing how many sign-ups are still awaiting confirmation.
- **Sender settings now have their own tab**, separate from the send actions and the subscriber list.
- **Unconfirmed sign-ups are now listed**, with the date they signed up and a way to remove them. Previously they were only ever shown as a number.
- **The "Save settings" button did nothing** — it sat outside its own form, so sender name and address could never be saved from the dashboard. Fixed.
- Spanish and English translations added for the SMTP tab.
- **Rate limiting** could lose increments under concurrent requests (read without a lock, only the final write was locked) and never pruned expired entries. Fixed.
- **Confirmation links never expired** — by design, matching the unsubscribe link, but an unbounded window makes an intercepted confirmation email usable indefinitely. Confirmation links now expire after 7 days; unsubscribe links are unaffected.

## [1.0.4] — 2026-08-09

- Admin folder name is now read from `config.json` instead of being hardcoded. The front-end output buffer no longer fires on admin pages when the admin folder has been renamed.
