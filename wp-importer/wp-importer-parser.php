<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * WordPress Importer — WXR Parser
 *
 * Parses a WordPress XML export file (WXR format) and returns a normalised
 * data structure ready to be written to the Synaptik CMS data layer.
 *
 * Supported WXR versions: 1.0, 1.1, 1.2 (all use the same core structure).
 *
 * SEO metadata extracted when present:
 *   - Yoast SEO  (_yoast_wpseo_title, _yoast_wpseo_metadesc, _yoast_wpseo_opengraph-*)
 *   - Rank Math  (rank_math_title, rank_math_description, rank_math_og_*)
 *   - All in One SEO (_aioseop_title, _aioseop_description)
 */

if (defined('WPI_PARSER_LOADED')) return;
define('WPI_PARSER_LOADED', true);

/**
 * Parses a WXR file and returns extracted data.
 *
 * @param  string $filePath  Absolute path to the uploaded .xml file.
 * @return array {
 *   categories: array,   slug → ['name', 'slug', 'parent', 'description']
 *   tags:       array,   slug → ['name', 'slug', 'description']
 *   posts:      array,   normalised article/page/project items
 *   errors:     array,   non-fatal parse warnings
 *   site_url:   string
 * }
 * @throws RuntimeException on unrecoverable parse errors.
 */
function wpi_parse_wxr(string $filePath): array
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        throw new RuntimeException('WXR file not found or not readable.');
    }

    $previousUseErrors = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $xml = simplexml_load_file($filePath, 'SimpleXMLElement', LIBXML_NOCDATA);

    $libxmlErrors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseErrors);

    if ($xml === false) {
        $msg = 'Invalid XML file.';
        if (!empty($libxmlErrors)) {
            $msg .= ' ' . trim($libxmlErrors[0]->message);
        }
        throw new RuntimeException($msg);
    }

    // Validate that this is actually a WXR file — must have a <channel> child
    // and at least one known WordPress namespace.
    $channel = $xml->channel ?? null;
    if ($channel === null || !($channel instanceof SimpleXMLElement)) {
        throw new RuntimeException(
            'This file does not appear to be a WordPress export (WXR). ' .
            'Expected a <channel> element inside the root — got a <' . $xml->getName() . '> document instead. ' .
            'In WordPress, go to Tools → Export and download the XML file.'
        );
    }

    $ns = $xml->channel->getNamespaces(true);

    // A real WXR file always declares the wordpress.org/export/ namespace.
    $hasWpNs = false;
    foreach ($ns as $prefix => $uri) {
        if (strpos($uri, 'wordpress.org/export/') !== false) {
            $hasWpNs = true;
            break;
        }
    }
    if (!$hasWpNs) {
        throw new RuntimeException(
            'This XML file does not contain the WordPress export namespace. ' .
            'Please upload a file exported from WordPress via Tools → Export.'
        );
    }

    $result = [
        'categories' => [],
        'tags'       => [],
        'posts'      => [],
        'errors'     => [],
        'site_url'   => '',
    ];

    $result['site_url'] = rtrim((string)($channel->link ?? ''), '/');

    // ── Categories & tags (declared at channel level via wp: namespace) ───────
    if (isset($ns['wp'])) {
        foreach ($channel->children($ns['wp']) as $tag => $node) {
            if ($tag === 'category') {
                $slug   = wpi_str($node->cat_nicename);
                $name   = wpi_str($node->cat_name);
                $parent = wpi_str($node->category_parent);
                $desc   = wpi_str($node->category_description);

                if ($slug !== '') {
                    $result['categories'][$slug] = [
                        'name'        => $name ?: $slug,
                        'slug'        => $slug,
                        'parent'      => ($parent !== '' && $parent !== '0') ? $parent : '',
                        'description' => $desc,
                    ];
                }
            }

            if ($tag === 'tag') {
                $slug = wpi_str($node->tag_slug);
                $name = wpi_str($node->tag_name);
                $desc = wpi_str($node->tag_description);

                if ($slug !== '') {
                    $result['tags'][$slug] = [
                        'name'        => $name ?: $slug,
                        'slug'        => $slug,
                        'description' => $desc,
                    ];
                }
            }
        }
    }

    // ── Items ─────────────────────────────────────────────────────────────────
    if ($channel->item instanceof SimpleXMLElement) {
        foreach ($channel->item as $item) {
            $parsed = wpi_parse_item($item, $ns, $result['site_url']);
            if ($parsed !== null) {
                $result['posts'][] = $parsed;
            }
        }
    }

    return $result;
}

/**
 * Parses a single <item> element into a normalised array.
 * Returns null for attachments and unsupported post types.
 */
function wpi_parse_item(SimpleXMLElement $item, array $ns, string $siteUrl): ?array
{
    $wp      = isset($ns['wp'])      ? $item->children($ns['wp'])      : null;
    $content = isset($ns['content']) ? $item->children($ns['content']) : null;
    $excerpt = isset($ns['excerpt']) ? $item->children($ns['excerpt']) : null;

    $postType  = ($wp && isset($wp->post_type))  ? wpi_str($wp->post_type)  : 'post';
    $status    = ($wp && isset($wp->status))      ? wpi_str($wp->status)     : 'publish';
    $slug      = ($wp && isset($wp->post_name))   ? wpi_str($wp->post_name)  : '';
    $dateRaw   = ($wp && isset($wp->post_date))   ? wpi_str($wp->post_date)  : '';

    if ($postType === 'attachment') return null;

    $type = wpi_map_post_type($postType);
    if ($type === null) return null;

    $snkStatus  = ($status === 'publish') ? 'published' : 'draft';
    $title      = html_entity_decode(wpi_str($item->title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $date       = wpi_normalise_date($dateRaw);
    $body       = ($content && isset($content->encoded)) ? wpi_str($content->encoded) : '';
    $summaryRaw = ($excerpt && isset($excerpt->encoded)) ? wpi_str($excerpt->encoded) : '';
    $link       = wpi_str($item->link);

    if ($slug === '' && $link !== '') {
        $slug = wpi_slug_from_url($link, $siteUrl);
    }
    if ($slug === '') {
        $slug = wpi_slugify($title);
    }

    // ── Taxonomy ──────────────────────────────────────────────────────────────
    $category = '';
    $tags      = [];

    foreach ($item->category as $cat) {
        $domain   = (string)($cat->attributes()['domain']   ?? '');
        $nicename = (string)($cat->attributes()['nicename'] ?? '');

        if ($domain === 'category' && $nicename !== '' && $category === '') {
            $category = $nicename;
        } elseif (in_array($domain, ['post_tag', 'tag'], true) && $nicename !== '') {
            $tags[] = $nicename;
        }
    }

    // ── Post meta ─────────────────────────────────────────────────────────────
    $meta = [];
    if ($wp && isset($wp->postmeta)) {
        foreach ($wp->postmeta as $pm) {
            $key = wpi_str($pm->meta_key);
            $val = wpi_str($pm->meta_value);
            if ($key !== '') {
                $meta[$key] = $val;
            }
        }
    }

    [$metaTitle, $metaDesc, $ogTitle, $ogDesc, $ogImage] = wpi_extract_seo($meta);

    $image   = $meta['_wp_attached_file'] ?? '';
    $body    = wpi_clean_content($body);
    $summary = trim(strip_tags($summaryRaw));

    return [
        '_wp_type'            => $postType,
        '_snk_type'           => $type,
        '_wp_link'            => $link,
        'slug'                => $slug,
        'custom_slug'         => '',
        'title'               => $title,
        'date'                => $date,
        'status'              => $snkStatus,
        'category'            => $category,
        'tags'                => $tags,
        'image'               => $image,
        'summary'             => $summary,
        'description'         => $summary,
        'content'             => $body,
        'content_format'      => 'html',
        'meta_title'          => $metaTitle,
        'meta_description'    => $metaDesc,
        'og_title'            => $ogTitle,
        'og_description'      => $ogDesc,
        'og_image'            => $ogImage,
        'show_title'          => true,
        'show_date'           => ($type === 'article'),
        'show_featured_image' => ($image !== ''),
        'show_in_menu'        => false,
        'menu_order'          => 0,
        'show_on_homepage'    => ($type === 'article'),
        'show_related_items'  => false,
        'related_items'       => [],
        'galleries'           => [],
        'custom_fields'       => [],
        'last_modified'       => date('Y-m-d H:i'),
    ];
}

/**
 * Maps a WP post type string to a Synaptik CMS content type.
 * Returns null for types that should be skipped.
 */
function wpi_map_post_type(string $wpType): ?string
{
    return match ($wpType) {
        'post'              => 'article',
        'page'              => 'page',
        'project',
        'portfolio'         => 'project',
        default             => null,
    };
}

/**
 * Extracts SEO metadata from post meta.
 * Supports Yoast SEO, Rank Math, and All in One SEO Pack.
 *
 * @return array [metaTitle, metaDesc, ogTitle, ogDesc, ogImage]
 */
function wpi_extract_seo(array $meta): array
{
    $metaTitle = $meta['_yoast_wpseo_title']                 ?? $meta['rank_math_title']         ?? $meta['_aioseop_title']       ?? '';
    $metaDesc  = $meta['_yoast_wpseo_metadesc']              ?? $meta['rank_math_description']    ?? $meta['_aioseop_description'] ?? '';
    $ogTitle   = $meta['_yoast_wpseo_opengraph-title']       ?? $meta['rank_math_og_title']       ?? '';
    $ogDesc    = $meta['_yoast_wpseo_opengraph-description'] ?? $meta['rank_math_og_description'] ?? '';
    $ogImage   = $meta['_yoast_wpseo_opengraph-image']       ?? $meta['rank_math_facebook_image'] ?? '';

    return [$metaTitle, $metaDesc, $ogTitle, $ogDesc, $ogImage];
}

/**
 * Converts a WP post_date string ("Y-m-d H:i:s") to "Y-m-d H:i".
 */
function wpi_normalise_date(string $raw): string
{
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return date('Y-m-d');
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('Y-m-d H:i', $ts) : date('Y-m-d');
}

/**
 * Derives a URL slug from a full WP permalink.
 */
function wpi_slug_from_url(string $url, string $siteUrl): string
{
    if ($siteUrl !== '' && strpos($url, $siteUrl) === 0) {
        $path = substr($url, strlen($siteUrl));
    } else {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
    }
    $path     = trim($path, '/');
    $segments = array_filter(explode('/', $path));
    $last     = end($segments);
    return $last !== false ? $last : '';
}

/**
 * Converts an arbitrary string to a URL-safe slug.
 */
function wpi_slugify(string $text): string
{
    if (function_exists('sanitizeSlug')) {
        return sanitizeSlug($text);
    }
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\-_]/', '-', $text);
    $text = preg_replace('/-{2,}/', '-', $text);
    return trim($text, '-');
}

/**
 * Strips Gutenberg block comments and unwraps WP [caption] shortcodes.
 */
function wpi_clean_content(string $html): string
{
    if ($html === '') return '';

    $html = preg_replace('/<!--\s*\/?(wp:[^>]*)-->/i', '', $html) ?? $html;
    $html = preg_replace_callback(
        '/\[caption[^\]]*\](.*?)\[\/caption\]/is',
        fn($m) => trim($m[1]),
        $html
    ) ?? $html;
    $html = preg_replace('/(\r?\n){3,}/', "\n\n", $html) ?? $html;
    $html = wpi_strip_dangerous_html($html);

    return trim($html);
}

/**
 * Strips script-executing elements and attributes from imported HTML. A WXR
 * export comes from a foreign (or possibly compromised) WordPress install —
 * unlike hand-typed editor content, it's ingested in bulk and never
 * individually re-read, so anything dangerous embedded in it would ship
 * straight to the live site.
 */
function wpi_strip_dangerous_html(string $html): string
{
    // Whole elements that can execute script or embed arbitrary external
    // content, including their body — both paired and self-closing forms.
    $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
    $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*/?>#i', '', $html) ?? $html;

    // Inline event handler attributes (onclick, onerror, onload, …) on any
    // remaining element.
    $html = preg_replace('/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace("/\s+on[a-z]+\s*=\s*'[^']*'/i", '', $html) ?? $html;

    // javascript: URLs in href/src.
    $html = preg_replace('/\s+(href|src)\s*=\s*"javascript:[^"]*"/i', '', $html) ?? $html;
    $html = preg_replace("/\s+(href|src)\s*=\s*'javascript:[^']*'/i", '', $html) ?? $html;

    return $html;
}

/**
 * Casts a SimpleXMLElement to a plain trimmed string.
 */
function wpi_str($node): string
{
    if ($node === null) return '';
    return trim((string)$node);
}
