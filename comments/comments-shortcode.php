<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Comments Plugin — Shortcode Rendering
 *
 * Renders the [comments] shortcode: approved comment list (one level of
 * nested replies) plus the submission form. Self-detects the current
 * item's type/slug from $GLOBALS['type']/$GLOBALS['slug'] (set by the
 * core router on single-item pages — see routing.md/templates.md),
 * with explicit [comments type="article" slug="my-post"] attributes as
 * an override for themes that call the shortcode from a non-standard
 * context.
 *
 * Assets are only injected on pages that actually contain the shortcode
 * — see comments-init.php.
 */

if (defined('CM_SHORTCODE_LOADED')) return;
define('CM_SHORTCODE_LOADED', true);

require_once __DIR__ . '/comments-functions.php';
require_once __DIR__ . '/comments-data-layer.php';
require_once __DIR__ . '/comments-i18n.php';
require_once __DIR__ . '/comments-security.php';

/**
 * Resolves which content item [comments] refers to: explicit shortcode
 * attributes take priority, falling back to the core's own routing
 * globals on genuine single-item pages. Returns null if neither source
 * yields a valid type+slug pair — the shortcode then renders nothing.
 */
function cm_resolve_target(array $attrs): ?array
{
    $type = $attrs['type'] ?? ($GLOBALS['type'] ?? '');
    $slug = $attrs['slug'] ?? ($GLOBALS['slug'] ?? '');

    if (!cm_is_valid_type($type) || $slug === '') {
        return null;
    }

    return [$type, sanitizeSlug($slug)];
}

/**
 * Renders a single comment (and, for a root comment, its nested replies)
 * as a <li>. Email is intentionally never output — collected only for
 * the site owner's own reference in the admin dashboard.
 */
function cm_render_comment_html(array $comment, string $type, string $slug, string $csrf, bool $isReply = false): string
{
    $name    = hsc($comment['author_name'] ?? '');
    $content = nl2br(hsc($comment['content'] ?? ''));
    $date    = hsc(cm_format_display_date($comment['created_at'] ?? ''));
    $id      = hsc($comment['_id'] ?? '');

    $replyButton = '';
    $replyForm   = '';
    if (!$isReply) {
        $tReply = hsc(cm_t('cm_reply', 'Reply'));
        $replyButton = '<button type="button" class="cm-reply-toggle" data-target="cm-reply-form-' . $id . '">' . $tReply . '</button>';
        $replyForm   = cm_render_form_html($type, $slug, $csrf, $id, true);
    }

    $repliesHtml = '';
    if (!empty($comment['_replies'])) {
        $items = '';
        foreach ($comment['_replies'] as $reply) {
            $items .= cm_render_comment_html($reply, $type, $slug, $csrf, true);
        }
        $repliesHtml = '<ul class="cm-replies">' . $items . '</ul>';
    }

    $replyClass = $isReply ? ' cm-comment--reply' : '';

    return <<<HTML
    <li class="cm-comment{$replyClass}" id="comment-{$id}">
        <div class="cm-comment-header">
            <span class="cm-comment-author">{$name}</span>
            <span class="cm-comment-date">{$date}</span>
        </div>
        <div class="cm-comment-body">{$content}</div>
        <div class="cm-comment-actions">{$replyButton}</div>
        {$replyForm}
        {$repliesHtml}
    </li>
    HTML;
}

/**
 * Renders the submission form. When $parentId is set, this is a reply
 * form nested under a root comment — hidden by default, toggled by
 * comments-form.js — rather than the main top-level form.
 */
function cm_render_form_html(string $type, string $slug, string $csrf, string $parentId = '', bool $isReplyForm = false): string
{
    $typeEsc   = hsc($type);
    $slugEsc   = hsc($slug);
    $csrfEsc   = hsc($csrf);
    $parentEsc = hsc($parentId);
    $now       = time();
    $formClass = $isReplyForm ? 'cm-form cm-reply-form' : 'cm-form';
    $formId    = $isReplyForm ? ' id="cm-reply-form-' . hsc($parentId) . '"' : '';
    $hidden    = $isReplyForm ? ' hidden' : '';

    $tName    = hsc(cm_t('cm_field_name', 'Name'));
    $tEmail   = hsc(cm_t('cm_field_email', 'Email'));
    $tContent = hsc(cm_t('cm_field_content', 'Comment'));
    $tSubmit  = hsc(cm_t('cm_submit', 'Post comment'));
    $tSending = hsc(cm_t('cm_sending', 'Sending…'));
    $tCancel  = hsc(cm_t('cm_cancel_reply', 'Cancel'));

    $cancelButton = $isReplyForm
        ? '<button type="button" class="cm-cancel-reply">' . $tCancel . '</button>'
        : '';

    $hcaptchaWidget = '';
    if (cm_load_settings()['use_hcaptcha'] && cm_core_hcaptcha_site_key() !== '') {
        $siteKey = hsc(cm_core_hcaptcha_site_key());
        $hcaptchaWidget = '<div class="h-captcha" data-sitekey="' . $siteKey . '"></div>';
    }

    return <<<HTML
    <form class="{$formClass}"{$formId}{$hidden} method="post" novalidate data-cm-form>
        <input type="hidden" name="type" value="{$typeEsc}">
        <input type="hidden" name="slug" value="{$slugEsc}">
        <input type="hidden" name="parent_id" value="{$parentEsc}">
        <input type="hidden" name="_csrf" value="{$csrfEsc}">
        <input type="hidden" name="_ft" value="{$now}">
        <div class="cm-honeypot" aria-hidden="true">
            <label for="cm-hp-{$parentEsc}">Website</label>
            <input type="text" id="cm-hp-{$parentEsc}" name="_hp" tabindex="-1" autocomplete="off">
        </div>

        <div class="cm-field-row">
            <div class="cm-field">
                <label>{$tName}</label>
                <input type="text" name="author_name" maxlength="100" required>
            </div>
            <div class="cm-field">
                <label>{$tEmail}</label>
                <input type="email" name="author_email" maxlength="254" required>
            </div>
        </div>
        <div class="cm-field">
            <label>{$tContent}</label>
            <textarea name="content" rows="4" maxlength="3000" required></textarea>
        </div>

        {$hcaptchaWidget}

        <div class="cm-form-actions">
            <button type="submit" class="cm-submit" data-label-default="{$tSubmit}" data-label-sending="{$tSending}">{$tSubmit}</button>
            {$cancelButton}
        </div>

        <div class="cm-message" role="status" hidden></div>
    </form>
    HTML;
}

/**
 * Formats a stored "Y-m-d H:i:s" timestamp using the site's own date
 * format setting, falling back to the raw value if unparseable.
 */
function cm_format_display_date(string $stored): string
{
    if ($stored === '') return '';
    return function_exists('format_date') ? format_date($stored) : $stored;
}

/**
 * Full [comments] output: heading with count, approved comment list, and
 * the top-level submission form. Returns empty string when the target
 * item cannot be resolved or comments are disabled for its type.
 */
function cm_render_comments_html(array $attrs): string
{
    $target = cm_resolve_target($attrs);
    if ($target === null) return '';

    [$type, $slug] = $target;
    if (!cm_type_enabled($type)) return '';

    $tree  = cm_load_approved_tree($type, $slug);
    $count = 0;
    foreach ($tree as $root) {
        $count += 1 + count($root['_replies']);
    }

    $csrf = cm_generate_csrf();

    $listHtml = '';
    if (!empty($tree)) {
        $items = '';
        foreach ($tree as $root) {
            $items .= cm_render_comment_html($root, $type, $slug, $csrf, false);
        }
        $listHtml = '<ul class="cm-list">' . $items . '</ul>';
    } else {
        $listHtml = '<p class="cm-empty">' . hsc(cm_t('cm_no_comments', 'No comments yet. Be the first to share your thoughts.')) . '</p>';
    }

    $titleTemplate = $count === 1
        ? cm_t('cm_title_one', '1 Comment')
        : cm_t('cm_title_many', '{count} Comments');
    $title = hsc(str_replace('{count}', (string)$count, $titleTemplate));

    $formHtml = cm_render_form_html($type, $slug, $csrf);

    $endpoint = hsc(cm_plugin_base_url() . 'comments-submit.php');

    return <<<HTML
    <section class="cm-comments" data-cm-type="{$type}" data-cm-slug="{$slug}" data-cm-endpoint="{$endpoint}">
        <h3 class="cm-title">{$title}</h3>
        {$listHtml}
        {$formHtml}
    </section>
    HTML;
}

/**
 * Returns the <link>/<script> tags for the comments widget, plus the
 * hCaptcha script when the site key is configured and the toggle is on.
 * Injected once per page by the output-buffer hook in comments-init.php.
 */
function cm_render_assets(): string
{
    $pluginUrl = cm_plugin_base_url();
    $cssPath = __DIR__ . '/assets/css/comments-front.css';
    $jsPath  = __DIR__ . '/assets/js/comments-form.js';
    $cssV    = file_exists($cssPath) ? filemtime($cssPath) : time();
    $jsV     = file_exists($jsPath) ? filemtime($jsPath) : time();

    $html  = '<link rel="stylesheet" href="' . hsc($pluginUrl . 'assets/css/comments-front.css?v=' . $cssV) . '">' . "\n";
    $html .= '<script src="' . hsc($pluginUrl . 'assets/js/comments-form.js?v=' . $jsV) . '" defer></script>' . "\n";

    if (cm_load_settings()['use_hcaptcha'] && cm_core_hcaptcha_site_key() !== '') {
        $html .= '<script src="https://js.hcaptcha.com/1/api.js" async defer></script>' . "\n";
    }

    $jsStrings = [
        'error_generic'    => cm_t('cm_error_generic', 'An error occurred. Please try again.'),
        'error_validation' => cm_t('cm_error_validation', 'Please fill in all fields.'),
        'error_rate'       => cm_t('cm_error_rate', 'Too many comments submitted. Please try again later.'),
        'pending_notice'   => cm_t('cm_pending_notice', 'Thank you — your comment is awaiting moderation.'),
    ];
    $html .= '<script type="application/json" data-window-var="CM_LANG">' . json_encode($jsStrings, JSON_UNESCAPED_UNICODE) . '</script>' . "\n";

    return $html;
}
