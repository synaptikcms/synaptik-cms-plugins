<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Reviews Plugin — Shortcode Rendering
 *
 * Renders [reviews_carousel] and [reviews_grid] into self-contained HTML.
 * Assets are only injected on pages that actually contain one of the
 * shortcodes — see reviews-init.php.
 *
 * Supported attributes on both shortcodes:
 *   limit="6"        — max reviews to display (default: plugin setting)
 *   min_rating="4"   — only show reviews at or above this star rating
 *   style="carousel"|"grid" — [reviews_carousel] forces carousel,
 *                             [reviews_grid] forces grid; a generic
 *                             [reviews] shortcode (if used) reads this
 *                             attribute, falling back to the plugin setting
 */

if (defined('RV_SHORTCODE_LOADED')) return;
define('RV_SHORTCODE_LOADED', true);

require_once __DIR__ . '/reviews-functions.php';
require_once __DIR__ . '/reviews-i18n.php';

/**
 * Parses a shortcode's raw attribute string ('limit="6" min_rating="4"')
 * into a key/value array. Mirrors the core's own
 * _shortcode_parse_attrs() behavior (key="value", key='value', key=value)
 * without depending on that core function being loaded in every context
 * this plugin's output buffer runs in.
 */
function rv_parse_shortcode_attrs(string $raw): array
{
    $attrs = [];
    if (preg_match_all('/([a-zA-Z_]+)\s*=\s*"([^"]*)"|([a-zA-Z_]+)\s*=\s*\'([^\']*)\'|([a-zA-Z_]+)\s*=\s*([^\s"\']+)/', $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            if ($m[1] !== '') { $attrs[$m[1]] = $m[2]; }
            elseif ($m[3] !== '') { $attrs[$m[3]] = $m[4]; }
            elseif ($m[5] !== '') { $attrs[$m[5]] = $m[6]; }
        }
    }
    return $attrs;
}

/**
 * Renders a single review's HTML fragment — shared markup between the
 * carousel and grid layouts, which only differ in their wrapping container.
 */
function rv_render_review_item(array $review): string
{
    $rating = max(1, min(5, (int)($review['rating'] ?? 5)));
    $name   = hsc($review['author_name'] ?? '');
    $text   = nl2br(hsc($review['text'] ?? ''));
    $date   = hsc(format_date($review['date'] ?? ''));
    $avatar = rv_avatar_url($review['avatar'] ?? '');

    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        $filled = $i <= $rating ? ' rv-star--filled' : '';
        $stars .= '<span class="rv-star' . $filled . '" aria-hidden="true">★</span>';
    }

    $avatarHtml = $avatar !== ''
        ? '<img class="rv-avatar" src="' . hsc($avatar) . '" alt="" loading="lazy" width="48" height="48">'
        : '<span class="rv-avatar rv-avatar--placeholder" aria-hidden="true">' . hsc(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';

    $ratingLabel = hsc(sprintf(rv_t('reviews_rating_label', '%d out of 5 stars'), $rating));

    return <<<HTML
    <div class="rv-item">
        <div class="rv-item-header">
            {$avatarHtml}
            <div class="rv-item-meta">
                <span class="rv-author">{$name}</span>
                <span class="rv-stars" role="img" aria-label="{$ratingLabel}">{$stars}</span>
            </div>
        </div>
        <p class="rv-text">{$text}</p>
        <span class="rv-date">{$date}</span>
    </div>
    HTML;
}

/**
 * Returns the full HTML for a reviews block, in either layout.
 *
 * Built via string concatenation rather than ob_start()/ob_get_clean():
 * this function runs from inside reviews-init.php's own output-buffer
 * callback, and PHP does not allow nesting a new output buffer inside an
 * active buffer's display handler.
 */
function rv_render_reviews_html(string $forcedStyle, array $attrs): string
{
    $settings  = rv_load_settings();
    $limit     = isset($attrs['limit']) ? max(1, (int)$attrs['limit']) : (int)$settings['default_limit'];
    $minRating = isset($attrs['min_rating']) ? max(1, min(5, (int)$attrs['min_rating'])) : 1;
    $style     = $forcedStyle !== '' ? $forcedStyle : ($attrs['style'] ?? $settings['display_style']);
    $style     = in_array($style, ['carousel', 'grid'], true) ? $style : 'carousel';

    $reviews = array_slice(rv_load_published_reviews($minRating), 0, $limit);

    if (empty($reviews)) {
        return ''; // Nothing published yet — render nothing rather than an empty shell.
    }

    $items = '';
    foreach ($reviews as $review) {
        $items .= rv_render_review_item($review);
    }

    if ($style === 'grid') {
        return '<div class="rv-wrapper rv-wrapper--grid"><div class="rv-grid">' . $items . '</div></div>';
    }

    // Carousel: track + prev/next controls. Dots are generated client-side
    // in reviews-carousel.js, once the actual slide count is known post-render.
    $prevLabel = hsc(rv_t('reviews_prev', 'Previous review'));
    $nextLabel = hsc(rv_t('reviews_next', 'Next review'));

    return <<<HTML
    <div class="rv-wrapper rv-wrapper--carousel">
        <div class="rv-carousel" data-rv-carousel>
            <div class="rv-track" data-rv-track>{$items}</div>
        </div>
        <button type="button" class="rv-nav rv-nav--prev" data-rv-prev aria-label="{$prevLabel}">&#8249;</button>
        <button type="button" class="rv-nav rv-nav--next" data-rv-next aria-label="{$nextLabel}">&#8250;</button>
        <div class="rv-dots" data-rv-dots></div>
    </div>
    HTML;
}

/**
 * Returns the <link>/<script> tags for the reviews widget. Called once by
 * the output-buffer hook in reviews-init.php, only when a reviews
 * shortcode was actually found on the page.
 */
function rv_render_assets(): string
{
    $pluginUrl = rv_plugin_base_url();
    $cssPath = __DIR__ . '/assets/css/reviews-front.css';
    $jsPath  = __DIR__ . '/assets/js/reviews-carousel.js';
    $cssV    = file_exists($cssPath) ? filemtime($cssPath) : time();
    $jsV     = file_exists($jsPath) ? filemtime($jsPath) : time();

    $html  = '<link rel="stylesheet" href="' . hsc($pluginUrl . 'assets/css/reviews-front.css?v=' . $cssV) . '">' . "\n";
    $html .= '<script src="' . hsc($pluginUrl . 'assets/js/reviews-carousel.js') . '?v=' . $jsV . '" defer></script>' . "\n";

    return $html;
}
