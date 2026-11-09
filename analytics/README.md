# Analytics Plugin for Synaptik CMS

Lightweight server-side analytics — no JavaScript, no cookies, no external scripts. Fully GDPR-compliant by design.

## What it tracks

- **Page views** and **unique visitors** (7d / 30d / 90d / 365d ranges)
- **Top pages** with view counts, content type, and slug
- **Traffic sources** — direct, search engines, social networks, referral sites
- **Device breakdown** — desktop, mobile, tablet
- **Country of origin** — optional, requires the GeoIP database (see below)
- **Today's live stats** — real-time view and unique visitor count, not cached

Bots and crawlers are filtered out automatically via User-Agent pattern matching. Logged-in admins are excluded. Static assets (CSS, JS, images) are never tracked.

## How it works

The tracker hooks into PHP's output buffering (`ob_start`). After the full HTML response is assembled, it extracts the page `<title>` and the routing context (type, slug) from CMS globals, then writes a single hit record to a daily JSON log file — all server-side, before the response is sent to the browser.

### Hit record format

Each page view is stored as a JSON object in `data/logs/YYYY-MM-DD.json`:

```json
{
  "ts":         1720000000,
  "type":       "article",
  "slug":       "my-post",
  "title":      "My Post Title",
  "ref_host":   "google.com",
  "ref_url":    "https://google.com/search",
  "ref_medium": "search",
  "device":     "desktop",
  "ip":         "<sha256 monthly hash>",
  "ip_d":       "<sha256 daily hash>",
  "country":    "FR"
}
```

Raw IP addresses are **never stored**. The `ip` field is a SHA-256 hash salted with the current year-month (`Y-m`) — the same visitor is identifiable within a calendar month for accurate unique visitor counts, but cross-month tracking is impossible by design. The `ip_d` field uses a daily salt and is only used for the "today" unique visitor count.

### Aggregation and caching

The admin panel reads the daily log files and computes aggregates on the fly (top pages, referrer breakdown, device split, etc.). Results are cached in `data/cache/` with a 5-minute TTL to keep the panel fast on large log sets.

### File locking

Log writes use `flock(LOCK_EX)` with a read-modify-write strategy to prevent concurrent write corruption under traffic bursts. The file is decoded, the new hit appended, and the whole array re-encoded atomically.

---

## GeoIP — Country detection

Country detection is **optional**. Without a GeoIP database, the `country` field is left empty and the Countries tab in the admin shows no data. Everything else works normally.

### How it works

The plugin uses the **DB-IP Country Lite** database — a free, CC-BY-licensed dataset. At lookup time everything is local: no external requests, no API calls, pure PHP binary search at ~1ms per hit.

The database is stored as two optimised binary files in `data/geoip/`:

| File | Content | Record size |
|------|---------|-------------|
| `ipv4.bin` | IPv4 ranges packed as `[start uint32][end uint32][CC 2 bytes]` | 10 bytes |
| `ipv6.bin` | IPv6 ranges packed as `[start 16 bytes][end 16 bytes][CC 2 bytes]` | 34 bytes |
| `meta.json` | Build date and record counts | — |

Lookup is a binary search over the sorted range file — O(log n), no memory mapping required.

Private and loopback IPs (RFC 1918, `127.x`, `169.254.x`, IPv6 `::1`, `fe80::`, etc.) are skipped immediately and return an empty country code.

### Installing the database

From the Analytics admin panel, go to **Settings → GeoIP** and click **Install / Update GeoIP Database**. The install process:

1. Attempts to download the current month's DB-IP Country Lite CSV from `download.db-ip.com`. If the current month's file isn't available yet, it falls back to the previous month.
2. Decompresses the `.csv.gz` archive in a PHP temp directory (requires the `zlib` extension).
3. Converts the CSV into the two binary files via a single-pass stream parser. The temporary CSV is deleted immediately after.
4. Writes `meta.json` with the build timestamp and record counts.
5. Protects `data/geoip/` with a `.htaccess` deny-all rule.

The endpoint requires an active admin session and a valid CSRF token. Server requirements: `curl`, `zlib`, write access to `plugins/analytics/data/`.

### Updating

Re-click **Install / Update** at any time. DB-IP publishes a new CSV at the start of each month. The binary files are replaced atomically (`.tmp` rename) so a failed update never corrupts the live database.

### Attribution

DB-IP Country Lite is released under [Creative Commons Attribution 4.0](https://creativecommons.org/licenses/by/4.0/). If you display country data publicly, you must credit DB-IP — for example: *"IP Geolocation by [DB-IP](https://db-ip.com)"*.

---

## Data storage

```
plugins/analytics/
  data/
    logs/
      2026-08-01.json   ← one file per day
      2026-08-02.json
      ...
    cache/
      aggregate-7d.json    ← 5-min TTL aggregates
      aggregate-30d.json
      aggregate-90d.json
      aggregate-365d.json
    geoip/
      ipv4.bin
      ipv6.bin
      meta.json
```

All directories under `data/` are protected by `.htaccess` deny-all rules written automatically on first use.

## Log retention

Old log files can be purged from the Analytics admin settings. The purge threshold is configurable (default: 365 days). The purge function deletes daily JSON files older than the threshold and leaves everything else untouched.
