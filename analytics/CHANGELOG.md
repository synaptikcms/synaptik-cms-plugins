# Analytics Plugin — Changelog

## [1.0.5] — 2026-11-10

- **Data folder protected on fresh installs** — the plugin's data folder, including AI crawler statistics, is now locked against direct web access from first use instead of relying on a file missing from the zip.

## [1.0.4] — 2026-10-03

- **Chart.js bundled** — the charts no longer load Chart.js from a CDN, so the plugin works under an admin policy that allows no external scripts and the admin no longer contacts a third party.
- **Referrers traffic-source bars** — the bars had no width unit and never displayed.
- **Dead donut chart removed** — the Referrers tab loaded Chart.js for a chart that no longer exists on the page.

## [1.0.3] — 2026-08-23

- **AI assistants traffic source** — visits from ChatGPT, Perplexity, Gemini, Copilot, Claude and other assistants now form their own source instead of being counted as referral or search, including past hits and links tagged with `utm_source`.
- **AI crawler counter** — hits from AI crawlers (GPTBot, ClaudeBot, PerplexityBot…) are counted per day instead of being silently discarded.
- **AI traffic export** — optional token-protected JSON export of aggregated AI traffic for SEO Inspector, enabled from the Settings tab.
- **Dashboard widget** — when active, the main admin dashboard now shows a 7-day traffic overview (views KPI + top 3 pages), registered through the new core `admin_dashboard` hook.
- **Admin panel security policy compliance** — inline scripts and click/change handlers across the overview charts, referrers view (donut + row-expand), and settings page (save/purge/geoip) replaced with external JS reading data from inert JSON, so the plugin keeps working under the admin panel's stricter script-loading policy.
- **Referrer host parsing** — a `www.` prefix strip used `ltrim()`, which strips a *character set*, not a *prefix*; hosts like `wordpress.org` were being mangled into `ordpress.org`. Fixed with a proper prefix check.
- **Visitor IP hashing** now uses a random secret generated once per installation instead of a fixed value shared across every install — the old approach made the hashes reversible for anyone who knew the salt.
- **Missing file protection added** for the plugin's own root-level PHP files on Apache servers.

## [1.0.2] — 2026-08-10

- **Chart metric toggle**: switch the overview line chart between Page Views and Unique Visitors without leaving the page.
- **Unique visitors per day** data is now computed and stored in the aggregate cache, powering the new chart toggle.
- **Persistent range selection**: the selected time range (7d / 30d / 90d / 365d) is now remembered across all tabs. Switching tabs or navigating away and coming back keeps the same range.

## [1.0.1] — 2026-08-09

- 404 hits now store the actual requested URI in the `slug` field instead of the literal string `"404"`, making broken URLs identifiable directly in the Top Pages view.
- **Countries tab**: replaced the flex row layout with a proper table. Row separators added, bar height increased to 10px, bar width now fills the available column instead of being fixed at 100px.
- **Top Pages tab**: replaced the flex row layout with a proper table matching the Countries layout. Title and type badge are now inline on the same row; slug appears as a smaller grey line below. Reduced row padding.
- Added `.an-type-404` (red) and `.an-type-search` (yellow) badge styles.
