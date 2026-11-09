# Changelog — WordPress Importer

## 1.0.1 — 2026-08-23

### Security

- **Imported article content wasn't sanitized** — `<script>`, `<iframe>` and inline event handlers from a WordPress export would land in published content unmodified. Now stripped on import.
- **Missing file protection added** for the plugin's admin folder on Apache servers.

## 1.0.0 — 2026-08-09

Initial release.

- Import WordPress posts, pages, and portfolio/project custom post types
- Import categories (with parent/child hierarchy) and tags
- SEO metadata extraction: Yoast SEO, Rank Math, All in One SEO Pack
- URL slug preservation from WordPress permalinks
- Filter by publication status (published, draft, or all)
- Overwrite mode for re-importing updated content
- Automatic 301 redirect generation (requires Redirects plugin)
- Gutenberg block comments stripped from imported content
- Translations: English, French, Spanish
