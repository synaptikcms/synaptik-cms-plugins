# Comments Plugin — Changelog

## [1.0.2] — 2026-08-23

- **Admin panel security policy compliance** — inline scripts and click handlers (shortcode copy, bulk actions, per-row approve/reject/delete) replaced with external JS, so the plugin keeps working under the admin panel's stricter script-loading policy.
- **Front-end security policy compliance** — the public-facing comment form's inline configuration script replaced with an inert JSON data island.
- **`.htaccess` was over-broad** — it granted access to every `.php` file in the admin folder instead of just the real entry point, exposing an internal file to direct access. Narrowed to match every other plugin's pattern.
- **Settings moved into the tab bar** — the status filters (Pending, Approved, Rejected, All) and Settings now sit in one strip at the top of the page, on both screens, instead of Settings being a button on the side and the settings page carrying its own "back" link.
- **Delete confirmations now use the admin's own dialog** instead of the browser's grey pop-up, matching every other delete flow in the panel. The bulk one says how many comments are about to go.
- **Pagination dropped the current filter and search** — moving to page 2 while viewing Approved, Rejected or a search result silently threw you back to the pending list. Fixed.
- An unrecognised status in the address bar now falls back to Pending instead of showing an empty list.

## [1.0.1] — 2026-08-09

- Admin folder name is now read from `config.json` instead of being hardcoded. The front-end output buffer no longer fires on admin pages when the admin folder has been renamed.
