# WordPress Importer — Synaptik CMS Plugin

Import content from a WordPress site into Synaptik CMS using a standard WordPress XML export file (WXR format). No database access or WordPress plugin required.

---

## What it imports

- **Posts** → Articles
- **Pages** → Pages
- **Custom post types** `project` / `portfolio` → Projects
- **Categories** (with parent/child hierarchy)
- **Tags**
- **SEO metadata** — Yoast SEO, Rank Math, and All in One SEO Pack fields (`meta_title`, `meta_description`, OG title/description/image)
- **URL slugs** from WordPress permalinks
- **Publication status** — published items become `published`, everything else becomes `draft`

## What it does not import

- **Images** — image URLs in content are preserved as-is (pointing to the original WordPress host). Re-upload them via the File Manager and update the links manually.
- **Comments** — not supported in v1.
- **Custom post types** other than `project` / `portfolio`.
- **WooCommerce** products or orders.

---

## How to use

1. In WordPress, go to **Tools → Export**, select **All content**, and download the `.xml` file.
2. In Synaptik CMS, go to **Extensions** and activate the WordPress Importer plugin.
3. Open **WP Importer** in the sidebar.
4. Upload the `.xml` file, choose what to import, and click **Start Import**.

### Options

| Option | Description |
|---|---|
| Post status | Import published items only, drafts only, or everything. |
| Content types | Choose which types to import (categories, tags, posts, pages, projects). |
| Overwrite | Replace existing items that share the same URL slug. Off by default. |
| URL Redirects | Automatically create 301 redirects from old WordPress URLs to new Synaptik CMS URLs. Requires the [Redirects plugin](https://synaptikcms.com/plugins/) to be active. |

---

## Requirements

- Synaptik CMS v1.3.3 or later
- PHP `SimpleXML` extension (enabled by default on most hosts)
- For automatic redirects: the **Redirects** plugin must be installed and active
