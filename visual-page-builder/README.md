# Visual Page Builder

Visual page builder for Synaptik CMS. Create pages from scratch ("+ New page" with starter layouts), then click any section or sub-block in a live preview to edit its content and style: background, text and heading colours, spacing, corners, width, alignment. Texts are edited per language.

The plugin works with any theme. Without any setup, it offers 24 ready-made blocks and replaces the content of any page you build, whatever the theme's template looks like (the theme still shows its own title, header and footer around it).

The builder also works on articles and projects: the list has a Pages / Articles / Projects switch, and a layout built for an article or project replaces its text body (the theme still shows the title, date, image and categories). Layouts of articles and projects are stored under the key `article--slug` / `project--slug`, so they never collide with a page of the same slug. Changing an article's slug detaches its layout.

A theme can go further by opting in with a manifest and render files, to offer its own blocks, backgrounds and starter layouts (see "How a theme opts in" below).

## Requirements

Synaptik CMS 1.4.8 or later. The "any theme" mode relies on the core applying the `render_content_html` filter to content that is empty, which older cores do not do.

## Any theme, no setup

When the active theme has no `builder/manifest.php`, the plugin uses a built-in one: every page can be edited, "+ New page", "+ New article" and "+ New project" create an item from three starters, and the layout is injected through the `render_content_html` filter, so it replaces the page content wherever the theme prints it. This needs a core that applies that filter to empty content as well.

## How a theme opts in

1. `theme/<name>/builder/manifest.php` returns the block catalogue.
2. `theme/<name>/builder/<type>.php` renders one block type. It receives `$b` (resolved fields for the active language, plus `_id`, `_bg`, `_n`, `_class`, `_style`, `_path`) and `$lang`.
3. Sub-blocks of a `blocks` field are rendered by `theme/<name>/builder/blocks/<type>.php`, which receives `$blk`.
4. Outermost tag of each block calls `vpb_attrs($b, 'my-class')`, which outputs the class, inline style and the marker the editor uses to select the block.
5. A template calls `vpb_render_layout('<key>')` and keeps its normal markup when it returns `null`:

Pass the CMS item as second argument (`vpb_render_layout($slug, $item ?? null)`) when a block needs it, for example a block that outputs the page body.

```php
if (function_exists('vpb_render_layout') && ($html = vpb_render_layout('home')) !== null) {
    echo $html;
    return;
}
```

## New pages

Set `'page_template' => 'sections'` in the manifest and add `page-templates/sections.php` to the theme, which renders `vpb_render_layout($item['slug'])`. Every CMS page using that template appears in the builder, and "+ New page" creates one from a starter.

## Basic blocks

The plugin ships 24 theme-independent blocks. Basic blocks: `text`, `columns`, `image`, `video`, `quote`, `buttons`, `shortcode`, `divider`, `spacer`. Ready-made sections, each in its own group: `intro` (Hero); `media_text`, `accordion`, `notice`, `data_table` (Content); `features`, `stats`, `process`, `testimonial`, `team` (Cards); `pricing_table`, `cta_box` (Conversion); `gallery`, `logos`, `map` (Media). With a theme manifest, they all appear in the "Basic blocks" group. A theme overrides one by declaring the same key in its manifest, or by adding `builder/<type>.php`. Set `'core_blocks' => false` to hide them all, or a list of keys to keep only some.

## Editor features

- Undo / redo (Ctrl+Z, Ctrl+Shift+Z or Ctrl+Y), Ctrl+S to save, Ctrl+D to duplicate and Delete to remove the selected section.
- History: the last 15 saved versions of each page are kept in `private/versions/` and can be restored from the editor.
- Unsaved work from a previous session is offered back when the editor opens, and saving over a page changed elsewhere asks for confirmation.
- The `shortcode` block inserts any plugin shortcode (for example `[newsletter_signup]`).
- The stylesheet used by blocks is `assets/css/vpb-front.css`, loaded once and cached by the browser.

## Labels and descriptions

A type may carry `'label'` and `'description'` (a string, or a map by language). Other strings (field labels, option labels, group names) come from the plugin and can be extended or overridden by `theme/<name>/builder/lang/<locale>.json`, which is merged over the plugin strings. Keep theme-specific wording there, never in the plugin.

## Minimal manifest

```php
<?php
return [
    'default_lang' => 'en',
    'page_template' => 'sections',
    'bgs'     => ['tint' => 'Tint'],
    'targets' => ['home' => ['label' => 'Home', 'path' => '']],
    'starters' => [
        'blank' => ['label' => 'Blank', 'description' => 'Empty page', 'blocks' => [
            ['type' => 'text', 'v' => ['title' => 'Title']],
        ]],
    ],
    'types'   => [
        'text' => ['group' => 'content', 'bg' => true, 'fields' => [
            ['k' => 'title', 't' => 'text'],
            ['k' => 'body', 't' => 'textarea'],
        ]],
    ],
];
```

Matching `builder/text.php`:

```php
<section <?php echo vpb_attrs($b, 'section'); ?>>
    <h2><?php echo hsc($b['title']); ?></h2>
    <?php echo render_content_html($b['body'], ['content_format' => 'markdown']); ?>
</section>
```

## Manifest keys

- `default_lang`: language used when a translation is empty (defaults to `en`).
- `page_template`: template name that makes a CMS page builder-managed.
- `bgs`: background choices (`key => label`). A type with `'bg' => true` gets a background selector, available as `$b['_bg']`.
- `visuals`, `icons`: option sets that fields reference by name with `'o' => 'visuals'`.
- `links`: link presets offered in link fields. Values starting with `@` are resolved by your render code.
- `targets`: fixed pages that can be edited, `key => ['label' => ..., 'path' => 'url/path/for/preview']`. Pages that already have a layout are added automatically.
- `palette`, `style_presets`: colour swatches and one-click style presets of the Style tab (sensible defaults when omitted).
- Link presets may carry a fragment (`@pricing#plans`).
- `starters`: layouts offered by the "+ New" button. A plain string value is copied to every language.
- `types`: block types, each with `fields`, optional `bg` and `group` (hero, content, cards, media, layout, conversion) used to organise the palette.

## Field types

| `t` | Stored as | Notes |
|---|---|---|
| `text`, `textarea` | one value per language | |
| `list` | list of lines per language | one per line in the editor |
| `items` | repeatable rows | sub-fields in `'f'` |
| `blocks` | free 12-column grid of typed blocks | `'types'` = block definitions, optional `'spans'` (default 12, 9, 8, 6, 4, 3) |
| `select` | key | options in `'o'`, an array or a manifest set name |
| `check` | `'1'` or `''` | |
| `link` | URL, `#anchor`, `/path`, `@preset`, `mailto:`, `tel:` | validated |
| `image` | URL or relative path to an image file | validated |
| `slug` | `[a-z0-9-]` | for anchors |

Languages come from `lang/front/*.json`. Every value is validated against the manifest on save, so a stored layout can never contain a field or option the theme does not declare.

## Style

Each section stores a `st` object (background colour, text colour, heading colour, padding, corner radius) in addition to its fields. Colours and sizes are validated; heading colour is applied through a CSS variable so it never needs `!important`. Sub-blocks accept the same style plus width, alignment and vertical position.

Other style options: content alignment (left, centre, right) on every section, and for images a shape (aspect ratio), a fit (fill or fit inside), the visible part of a cropped image (top, centre, bottom), size, alignment, corners and shadow.

## Editor shortcut

On the main page editor, a link to the builder appears next to the template selector for pages the builder manages. It is injected through an output buffer, so the core is untouched.

## Live preview

Edits are saved as a draft in `private/drafts/` and the page is rendered in the editor with a signed, two-hour, key-bound preview token. Visitors never see drafts. "Save" publishes the layout and deletes the draft.

## Storage and security

Layouts are stored in `data/layouts/<key>.json` (atomic writes). `data/` and `private/` get a deny-all `.htaccess` when first created; on nginx, block those two folders in the server configuration. The signing secret lives in `private/vpb.secret`. Saving goes through `admin/actions.php`: admin session, CSRF token, size limit and manifest validation. Removing every section, or using "Back to theme template", deletes the layout so the page uses the theme template again.
