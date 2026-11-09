# Redirects

Manual 301/302 URL redirects for Synaptik CMS, plus an optional fallback
that sends unresolved 404s to the homepage.

## Why

Whenever a URL changes — an article is renamed, a category is
reorganized, a page is deleted — the old URL starts 404ing. Search
engines have it indexed, visitors may have bookmarked it, and without a
redirect that traffic and SEO ranking is simply lost.

## How it works

- **Manual redirects**: define a source path and a destination (a site
  path or a full external URL) with either a 301 (permanent — transfers
  SEO ranking) or 302 (temporary — no SEO transfer) status code.
- **404 fallback**: an optional toggle that sends any unresolved 404 to
  the homepage instead of rendering the theme's 404 page. Always 302.

The redirect check runs once per front-end request, right after the core
router resolves the requested URL and before any output — see the single
guarded call in the CMS's `index.php` (`rd_maybe_redirect()`). No hit
counter is kept: the check is read-only against `data/redirects.json`, so
it adds no disk writes to the page-load path.

## Admin

Admin → Extensions → Redirects. One dashboard: an add/edit form, the list
of existing redirects, and the 404-fallback checkbox.

## Data

- `data/redirects.json` — one entry per redirect: `{ from, to,
  status_code, created_at }`
- `data/config.json` — `{ fallback_404_home: bool }`
- `private/redirects.secret` — HMAC secret for the admin form's CSRF
  tokens (stateless, independent of the core session)
