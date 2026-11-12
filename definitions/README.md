# Definitions

Hover-to-reveal glossary definitions for underlined terms, managed from the admin panel.

## What it does

Add a term and its definition from **Definitions** in the admin sidebar. On the front end, the
term is underlined wherever it (or one of its variants — plurals, related phrasing) appears;
hovering it (or tapping it on mobile, or tabbing to it with a keyboard) opens a small styled popup
with the definition. By default only the first occurrence per page is underlined — configurable
globally (Definitions → Settings) or per definition.

## How it works

Terms are stored in `data/definitions.json`, edited exclusively through the admin page —
`admin/actions.php` handles create/update/delete/settings, each gated by the core admin session
and a CSRF token.

On every front-end request, the plugin buffers the rendered page and scans the `<body>` for
enabled terms and their variants (longest form first, Unicode-aware whole-word match,
case-insensitive), wrapping matches in a `<span class="df-term">`. Content inside `<script>`,
`<style>`, `<textarea>`, `<select>`, existing `<a>` links, and `<title>` is left untouched. This
tokenizing pass is the expensive part of the plugin and runs on every request regardless of the
core's page cache (see `df_render_page()`'s doc comment for why) — its result is cached on a hash
of the raw page HTML plus the current glossary, so a repeat hit is a file read, not a re-scan. A
small CSS/JS pair (`assets/css/definitions-front.css`, `assets/js/definitions-front.js`) is
injected fresh on every request, only on pages where at least one term was actually wrapped.
