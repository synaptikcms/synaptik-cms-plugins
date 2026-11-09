# Cookie Consent Plugin — Changelog

## [1.0.2] — 2026-08-23

### Changed

- **Front-end security policy compliance** — the consent banner's inline configuration script and settings-link click handlers replaced with an inert JSON data island, so the plugin keeps working under the site's stricter script-loading policy.

## [1.0.1] — 2026-08-09

### Fixed

- Admin folder name is now read from `config.json` instead of being hardcoded. The front-end output buffer no longer fires on admin pages when the admin folder has been renamed.
