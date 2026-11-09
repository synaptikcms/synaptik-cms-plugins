<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * WordPress Importer — Data Writer
 *
 * Takes the normalised data returned by wpi_parse_wxr() and writes it into
 * the Synaptik CMS data layer using the admin write functions (sl_admin_*).
 *
 * The caller is responsible for loading the core's data-layer.php and
 * admin-data-layer.php before calling these functions.
 */

if (defined('WPI_WRITER_LOADED')) return;
define('WPI_WRITER_LOADED', true);

/**
 * Imports all parsed data into Synaptik CMS.
 *
 * @param  array $parsed        Output of wpi_parse_wxr().
 * @param  array $options {
 *   import_categories: bool,
 *   import_tags:       bool,
 *   import_posts:      bool   (WP "post" → article)
 *   import_pages:      bool
 *   import_projects:   bool
 *   overwrite:         bool   (replace existing items with matching slug)
 *   post_status:       'all'|'published'|'draft'
 *   generate_redirects: bool  (write to Redirects plugin if active)
 * }
 * @return array {
 *   categories_imported: int,
 *   tags_imported:       int,
 *   articles_imported:   int,
 *   pages_imported:      int,
 *   projects_imported:   int,
 *   redirects_created:   int,
 *   skipped:             int,
 *   errors:              string[]
 * }
 */
function wpi_import_all(array $parsed, array $options): array
{
    $counts = [
        'categories_imported' => 0,
        'tags_imported'       => 0,
        'articles_imported'   => 0,
        'pages_imported'      => 0,
        'projects_imported'   => 0,
        'redirects_created'   => 0,
        'skipped'             => 0,
        'errors'              => [],
    ];

    // ── Categories ────────────────────────────────────────────────────────────
    if (!empty($options['import_categories']) && !empty($parsed['categories'])) {
        $c = wpi_import_categories($parsed['categories']);
        $counts['categories_imported'] = $c['imported'];
        $counts['errors'] = array_merge($counts['errors'], $c['errors']);
    }

    // ── Tags ──────────────────────────────────────────────────────────────────
    if (!empty($options['import_tags']) && !empty($parsed['tags'])) {
        $t = wpi_import_tags($parsed['tags']);
        $counts['tags_imported'] = $t['imported'];
        $counts['errors'] = array_merge($counts['errors'], $t['errors']);
    }

    // ── Posts / Pages / Projects ──────────────────────────────────────────────
    $typeMap = [
        'article' => ['import_posts',    'articles_imported'],
        'page'    => ['import_pages',    'pages_imported'],
        'project' => ['import_projects', 'projects_imported'],
    ];

    foreach ($parsed['posts'] as $post) {
        $snkType    = $post['_snk_type'];
        $optionKey  = $typeMap[$snkType][0] ?? null;
        $counterKey = $typeMap[$snkType][1] ?? null;

        if ($optionKey === null || empty($options[$optionKey])) {
            $counts['skipped']++;
            continue;
        }

        // Filter by status.
        if ($options['post_status'] !== 'all') {
            if ($options['post_status'] === 'published' && $post['status'] !== 'published') {
                $counts['skipped']++;
                continue;
            }
            if ($options['post_status'] === 'draft' && $post['status'] !== 'draft') {
                $counts['skipped']++;
                continue;
            }
        }

        $r = wpi_import_post($post, $snkType, $options);

        if ($r['imported']) {
            $counts[$counterKey]++;

            // Redirect: old WP URL → new Synaptik CMS URL.
            if (!empty($options['generate_redirects']) && $r['redirect_from'] !== '') {
                $ok = wpi_create_redirect($r['redirect_from'], $r['redirect_to']);
                if ($ok) $counts['redirects_created']++;
            }
        } else {
            $counts['skipped']++;
        }

        if ($r['error'] !== '') {
            $counts['errors'][] = $r['error'];
        }
    }

    return $counts;
}

/**
 * Merges WP categories into the Synaptik CMS categories store.
 * Existing categories (same slug) are left unchanged.
 */
function wpi_import_categories(array $wpCats): array
{
    $result    = ['imported' => 0, 'errors' => []];
    $existing  = sl_load_categories();

    foreach ($wpCats as $slug => $cat) {
        if (isset($existing[$slug])) continue; // already exists, skip

        $existing[$slug] = [
            'name'        => $cat['name'],
            'slug'        => $slug,
            'parent'      => $cat['parent'] ?? '',
            'description' => $cat['description'] ?? '',
        ];
        $result['imported']++;
    }

    if ($result['imported'] > 0) {
        if (!sl_admin_save_categories($existing)) {
            $result['errors'][] = 'Failed to write categories.json — check file permissions.';
            $result['imported'] = 0;
        }
    }

    return $result;
}

/**
 * Merges WP tags into the Synaptik CMS tags store.
 * Existing tags (same slug) are left unchanged.
 */
function wpi_import_tags(array $wpTags): array
{
    $result   = ['imported' => 0, 'errors' => []];
    $existing = sl_load_tags();

    foreach ($wpTags as $slug => $tag) {
        if (isset($existing[$slug])) continue;

        $existing[$slug] = [
            'name'        => $tag['name'],
            'slug'        => $slug,
            'description' => $tag['description'] ?? '',
        ];
        $result['imported']++;
    }

    if ($result['imported'] > 0) {
        if (!sl_admin_save_tags($existing)) {
            $result['errors'][] = 'Failed to write tags.json — check file permissions.';
            $result['imported'] = 0;
        }
    }

    return $result;
}

/**
 * Writes a single post to its Synaptik CMS type directory.
 *
 * @return array { imported: bool, redirect_from: string, redirect_to: string, error: string }
 */
function wpi_import_post(array $post, string $type, array $options): array
{
    $result = ['imported' => false, 'redirect_from' => '', 'redirect_to' => '', 'error' => ''];

    $slug = wpi_slugify($post['slug']);
    if ($slug === '') {
        $slug = wpi_slugify($post['title']);
    }
    if ($slug === '') {
        $result['error'] = 'Skipped item with empty title and slug.';
        return $result;
    }

    // Duplicate check.
    $existingEntry = sl_find_in_index($type, $slug);
    if ($existingEntry !== null && empty($options['overwrite'])) {
        $result['error'] = '';
        return $result; // silently skipped (not an error)
    }

    // Resolve a unique file slug.
    if ($existingEntry !== null) {
        // Overwrite: reuse the existing file slug.
        [$entry] = $existingEntry;
        $fileSlug = sl_file_slug($entry);
    } else {
        $fileSlug = wpi_resolve_unique_file_slug($type, $slug);
    }

    // Build the item array to write.
    $item = wpi_build_item($post, $type, $slug);

    // Write item file.
    if (!sl_admin_save_item($type, $fileSlug, $item)) {
        $result['error'] = sprintf('Failed to write %s/%s.json — check permissions.', $type, $fileSlug);
        return $result;
    }

    // Update index.
    $indexEntry = wpi_build_index_entry($item, $type, $fileSlug);
    if (!sl_admin_update_index($type, $indexEntry)) {
        $result['error'] = sprintf('Failed to update %s index — check permissions.', $type);
        return $result;
    }

    $result['imported'] = true;

    // Build redirect paths if the original WP URL is known.
    if (!empty($post['_wp_link'])) {
        $result['redirect_from'] = wpi_extract_wp_path($post['_wp_link']);
        $result['redirect_to']   = wpi_build_snk_path($type, $slug);
    }

    return $result;
}

/**
 * Builds the full item array that will be persisted to disk.
 */
function wpi_purify_html(string $html): string
{
    if (!function_exists('admin_purify_html') && function_exists('resolve_admin_dir')) {
        $adminDir = resolve_admin_dir();
        if ($adminDir !== '') {
            $purifier = dirname(__DIR__, 2) . '/' . $adminDir . '/includes/content-purify.php';
            if (!defined('INCLUDED')) define('INCLUDED', true);
            if (file_exists($purifier)) require_once $purifier;
        }
    }

    return function_exists('admin_purify_html')
        ? admin_purify_html($html)
        : htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}

function wpi_build_item(array $post, string $type, string $slug): array
{
    return [
        'slug'               => $slug,
        'custom_slug'        => $post['custom_slug']      ?? '',
        'title'              => $post['title']             ?? '',
        'date'               => $post['date']              ?? date('Y-m-d'),
        'status'             => $post['status']            ?? 'published',
        'category'           => $post['category']          ?? '',
        'tags'               => $post['tags']              ?? [],
        'image'              => $post['image']             ?? '',
        'show_in_menu'       => (bool)($post['show_in_menu']      ?? false),
        'menu_order'         => (int)($post['menu_order']         ?? 0),
        'show_date'          => (bool)($post['show_date']         ?? ($type === 'article')),
        'show_title'         => (bool)($post['show_title']        ?? true),
        'show_featured_image'=> (bool)($post['show_featured_image']?? false),
        'show_on_homepage'   => (bool)($post['show_on_homepage']  ?? ($type === 'article')),
        'show_related_items' => false,
        'related_items'      => [],
        'summary'            => ($type === 'article') ? wpi_purify_html($post['summary'] ?? '') : '',
        'description'        => ($type === 'project') ? wpi_purify_html($post['description'] ?? '') : '',
        'content'            => wpi_purify_html($post['content'] ?? ''),
        'content_format'     => 'html',
        'meta_title'         => $post['meta_title']        ?? '',
        'meta_description'   => $post['meta_description']  ?? '',
        'meta_keywords'      => '',
        'og_title'           => $post['og_title']          ?? '',
        'og_description'     => $post['og_description']    ?? '',
        'og_image'           => $post['og_image']          ?? '',
        'canonical_url'      => '',
        'galleries'          => [],
        'custom_fields'      => [],
        'last_modified'      => date('Y-m-d H:i'),
    ];
}

/**
 * Builds the lightweight index entry from a full item array.
 */
function wpi_build_index_entry(array $item, string $type, string $fileSlug): array
{
    $entry = [
        '_file'        => $fileSlug,
        'slug'         => $item['slug'],
        'custom_slug'  => $item['custom_slug'],
        'title'        => $item['title'],
        'date'         => $item['date'],
        'status'       => $item['status'],
        'category'     => $item['category'],
        'tags'         => $item['tags'],
        'image'        => $item['image'],
        'show_in_menu' => $item['show_in_menu'],
        'menu_order'   => $item['menu_order'],
        'show_date'    => $item['show_date'],
    ];

    if ($type === 'article') {
        $entry['summary']         = $item['summary'];
        $entry['show_on_homepage']= $item['show_on_homepage'];
    }
    if ($type === 'project') {
        $entry['description']     = $item['description'];
        $entry['show_on_homepage']= $item['show_on_homepage'];
    }
    if ($type === 'page') {
        $entry['page_template']   = '';
    }

    return $entry;
}

/**
 * Resolves a unique file slug, appending -2, -3… on collisions.
 */
function wpi_resolve_unique_file_slug(string $type, string $desiredSlug): string
{
    $dir    = defined('CMS_ROOT') ? CMS_ROOT . '/data/' . $type . 's/' : '';
    $slug   = $desiredSlug;
    $n      = 2;

    while (
        sl_find_in_index($type, $slug) !== null ||
        ($dir !== '' && file_exists($dir . $slug . '.json'))
    ) {
        $slug = $desiredSlug . '-' . $n++;
    }

    return $slug;
}

/**
 * Extracts the URL path from a WP permalink (strips scheme + host).
 * Returns '' if the URL is unparseable.
 */
function wpi_extract_wp_path(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === null || $path === false) return '';
    return rtrim($path, '/') . '/';
}

/**
 * Builds the expected Synaptik CMS front-end path for a given type + slug.
 * Uses the active locale's url_slug_* keys if available.
 */
function wpi_build_snk_path(string $type, string $slug): string
{
    $prefix = function_exists('url_slug') ? url_slug($type) : $type;
    return '/' . $prefix . '/' . $slug . '/';
}

/**
 * Creates a redirect entry via the Redirects plugin's data layer.
 * Silently does nothing if the plugin is not active or its data layer is
 * not available.
 *
 * @param  string $from  URL path (e.g. /old-post/).
 * @param  string $to    URL path or full URL.
 * @return bool          True if the redirect was created.
 */
function wpi_create_redirect(string $from, string $to): bool
{
    if ($from === '' || $to === '' || $from === $to) return false;

    // The Redirects plugin exposes rd_create_redirect() when active.
    if (!function_exists('rd_create_redirect')) {
        // Try loading the data layer directly.
        $rdDataLayer = dirname(__DIR__) . '/redirects/redirects-data-layer.php';
        if (file_exists($rdDataLayer)) {
            require_once $rdDataLayer;
        }
    }

    if (!function_exists('rd_create_redirect')) return false;
    if (!function_exists('rd_from_exists'))     return false;

    // Skip if a redirect for this path already exists.
    if (rd_from_exists($from)) return false;

    rd_create_redirect($from, $to, 301);
    return true;
}
