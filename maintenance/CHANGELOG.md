# Maintenance Mode Plugin — Changelog

## [1.0.4] — 2026-08-23

- **Logo picker rewritten onto the core's shared image picker** (`window.SynaptikImagePicker`, added to `admin/assets/js/common.js`) instead of its own standalone implementation — `assets/js/logo-picker.js` went from 109 to 39 lines. Same functionality (browse `files/`, upload, or remove the site logo), same look (`mt-sip-*` classes preserved), no behavior change.
- `admin/admin-page.php`: the logo-picker script tag now loads with `defer`, since it depends on `window.SynaptikImagePicker` from the core's `common.js`, which loads later in the page — without it, the picker would try to initialize before that dependency exists.
