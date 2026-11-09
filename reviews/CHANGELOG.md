# Reviews Plugin — Changelog

## [1.0.5] — 2026-11-10

- **Avatar upload folder hardened** — files in the uploads folder can no longer be executed or served as PHP, HTML or SVG, closing a stored XSS and code-execution route if a malicious file ever landed there.

## [1.0.4] — 2026-09-28

- **Fixed llms.txt/llms-full.txt asset leak** — the front-end `[reviews_carousel]`/`[reviews_grid]` resolution hook ran on every non-admin, non-plugin request, including the plain-text `llms.txt`/`llms-full.txt` export. A page whose raw content contained one of the literal shortcode markers could have the widget's `<link>`/`<script>` assets spliced into that text/plain response. The hook now skips `llms.txt`/`llms-full.txt` requests outright and never injects assets into a buffer that has no `</head>`.

## [1.0.3] — 2026-08-23

- **Admin panel security policy compliance** — the delete-confirmation inline handler replaced with external JS, so the plugin keeps working under the admin panel's stricter script-loading policy.

- Removed a stale `.htaccess` comment claiming mod_php session directives worked under PHP-FPM — they don't, and the real session settings are already applied in code.
- **Missing file protection added** for the plugin's admin page.

## [1.0.2] — 2026-08-09

# 

- Admin folder name is now read from `config.json` instead of being hardcoded. The front-end output buffer no longer fires on admin pages when the admin folder has been renamed.
