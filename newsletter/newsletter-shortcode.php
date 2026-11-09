<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Newsletter Plugin — Shortcode Rendering
 *
 * Renders the [newsletter_signup] shortcode into a self-contained HTML
 * block: a single email field, submitted via AJAX. Assets are only
 * injected on pages that actually contain the shortcode — see
 * newsletter-init.php.
 */

if (defined('NL_SHORTCODE_LOADED')) return;
define('NL_SHORTCODE_LOADED', true);

require_once __DIR__ . '/newsletter-functions.php';
require_once __DIR__ . '/newsletter-i18n.php';
require_once __DIR__ . '/newsletter-security.php';

/**
 * Returns the full HTML for the [newsletter_signup] shortcode.
 *
 * Built via string concatenation rather than ob_start()/ob_get_clean():
 * this function runs from inside newsletter-init.php's own output-buffer
 * callback, and PHP does not allow nesting a new output buffer inside an
 * active buffer's display handler.
 */
function nl_render_form_html(): string
{
    $csrf     = nl_generate_csrf();
    $endpoint = hsc(nl_plugin_base_url());
    $csrfEsc  = hsc($csrf);
    $now      = time();

    $tTitle       = hsc(nl_t('newsletter_form_title', 'Subscribe to our newsletter'));
    $tEmailLabel  = hsc(nl_t('newsletter_email_label', 'Email address'));
    $tEmailPh     = hsc(nl_t('newsletter_email_placeholder', 'you@example.com'));
    $tSubmit      = hsc(nl_t('newsletter_submit', 'Subscribe'));
    $tSubmitting  = hsc(nl_t('newsletter_submitting', 'Sending…'));
    $btnColor     = nl_button_color();
    $btnStyle     = '';
    $btnAttr      = '';
    if ($btnColor !== '') {
        $btnText  = nl_button_text_color($btnColor);
        $btnStyle = ' style="' . hsc('--nl-primary:' . $btnColor) . '"';
        $btnAttr  = ' style="' . hsc('background:' . $btnColor . ';color:' . $btnText) . '"';
    }
    $nameMode     = nl_name_field_mode();
    $nameField    = '';
    if ($nameMode !== 'off') {
        $tNameLabel = hsc(nl_t('newsletter_name_label', 'Name'));
        $tNamePh    = hsc(nl_t('newsletter_name_placeholder', 'Your name'));
        $required   = $nameMode === 'required' ? ' required' : '';
        $nameField  = '<label for="nl-name" class="nl-visually-hidden">' . $tNameLabel . '</label>'
                    . '<input type="text" id="nl-name" name="name" placeholder="' . $tNamePh . '" maxlength="100" autocomplete="name"' . $required . '>';
    }

    return <<<HTML
    <div class="nl-signup"{$btnStyle} data-nl-endpoint="{$endpoint}" data-nl-submitting-label="{$tSubmitting}">
        <form class="nl-form" novalidate>
            <h3 class="nl-title">{$tTitle}</h3>

            <input type="hidden" name="_csrf" value="{$csrfEsc}">
            <input type="hidden" name="_ft" value="{$now}">
            <div class="nl-honeypot" aria-hidden="true">
                <label for="nl-hp">Website</label>
                <input type="text" id="nl-hp" name="_hp" tabindex="-1" autocomplete="off">
            </div>

            <div class="nl-field">
                {$nameField}
                <label for="nl-email" class="nl-visually-hidden">{$tEmailLabel}</label>
                <input type="email" id="nl-email" name="email" placeholder="{$tEmailPh}" maxlength="254" required>
                <button type="submit" class="nl-submit"{$btnAttr}>{$tSubmit}</button>
            </div>

            <div class="nl-message" role="status" hidden></div>
        </form>
    </div>
    HTML;
}

/**
 * Returns the <link>/<script> tags for the signup widget. Called once by
 * the output-buffer hook in newsletter-init.php, only when
 * [newsletter_signup] was actually found on the page.
 */
function nl_render_assets(): string
{
    $pluginUrl = nl_plugin_base_url();
    $cssPath = __DIR__ . '/assets/css/newsletter-form.css';
    $jsPath  = __DIR__ . '/assets/js/newsletter-form.js';
    $cssV    = file_exists($cssPath) ? filemtime($cssPath) : time();
    $jsV     = file_exists($jsPath) ? filemtime($jsPath) : time();

    $html  = '<link rel="stylesheet" href="' . hsc($pluginUrl . 'assets/css/newsletter-form.css?v=' . $cssV) . '">' . "\n";
    $html .= '<script src="' . hsc($pluginUrl . 'assets/js/newsletter-form.js?v=' . $jsV) . '" defer></script>' . "\n";

    $jsStrings = [
        'error_generic' => nl_t('newsletter_error_generic', 'An error occurred. Please try again.'),
    ];
    $html .= '<script type="application/json" data-window-var="NL_LANG">' . json_encode($jsStrings, JSON_UNESCAPED_UNICODE) . '</script>' . "\n";

    return $html;
}
