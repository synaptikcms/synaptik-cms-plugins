<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Form Builder Plugin — Shortcode Rendering
 *
 * Renders the [form id="x"] shortcode into a self-contained HTML <form>
 * built from the form's field schema. Assets are only injected on pages
 * that actually contain the shortcode — see formbuilder-init.php.
 */

if (defined('FB_SHORTCODE_LOADED')) return;
define('FB_SHORTCODE_LOADED', true);

require_once __DIR__ . '/formbuilder-functions.php';
require_once __DIR__ . '/formbuilder-data-layer.php';
require_once __DIR__ . '/formbuilder-i18n.php';
require_once __DIR__ . '/formbuilder-security.php';

/**
 * Renders a single field's markup based on its type.
 */
function fb_render_field_html(array $field): string
{
    if ($field['type'] === 'separator') {
        return ($field['style'] ?? 'space') === 'line'
            ? '<hr class="fb-separator fb-separator--line">'
            : '<div class="fb-separator fb-separator--space" aria-hidden="true"></div>';
    }

    $key       = hsc($field['key']);
    $label     = hsc($field['label'] !== '' ? $field['label'] : $field['key']);
    $required  = !empty($field['required']);
    $reqAttr   = $required ? ' required' : '';
    $reqMark   = $required ? ' *' : '';
    $id        = 'fb-' . $key;
    $widthCls  = ($field['width'] ?? 'full') === 'half' ? ' fb-field--half' : '';

    switch ($field['type']) {
        case 'textarea':
            return <<<HTML
            <div class="fb-field{$widthCls}">
                <label for="{$id}">{$label}{$reqMark}</label>
                <textarea id="{$id}" name="{$key}" rows="4"{$reqAttr}></textarea>
            </div>
            HTML;

        case 'dropdown':
            $options = '<option value="">' . hsc(fb_t('fb_select_placeholder', 'Select…')) . '</option>';
            foreach ($field['options'] as $option) {
                $optEsc = hsc($option);
                $options .= "<option value=\"{$optEsc}\">{$optEsc}</option>";
            }
            return <<<HTML
            <div class="fb-field{$widthCls}">
                <label for="{$id}">{$label}{$reqMark}</label>
                <span class="fb-select-wrap">
                    <select id="{$id}" name="{$key}"{$reqAttr}>
                        {$options}
                    </select>
                </span>
            </div>
            HTML;

        case 'checkbox':
            return <<<HTML
            <div class="fb-field fb-field--checkbox{$widthCls}">
                <label>
                    <input type="checkbox" id="{$id}" name="{$key}" value="1">
                    {$label}
                </label>
            </div>
            HTML;

        case 'email':
        case 'tel':
        case 'number':
            $type = $field['type'];
            return <<<HTML
            <div class="fb-field{$widthCls}">
                <label for="{$id}">{$label}{$reqMark}</label>
                <input type="{$type}" id="{$id}" name="{$key}"{$reqAttr}>
            </div>
            HTML;

        case 'text':
        default:
            return <<<HTML
            <div class="fb-field{$widthCls}">
                <label for="{$id}">{$label}{$reqMark}</label>
                <input type="text" id="{$id}" name="{$key}" maxlength="500"{$reqAttr}>
            </div>
            HTML;
    }
}

/**
 * Groups consecutive half-width fields two at a time into an
 * .fb-fields-inline wrapper so they render side by side; full-width fields
 * (including separators) pass through unwrapped, exactly as before.
 */
function fb_group_fields_html(array $fields): string
{
    $html   = '';
    $pending = '';

    foreach ($fields as $field) {
        $fieldHtml = fb_render_field_html($field);
        $isHalf    = $field['type'] !== 'separator' && ($field['width'] ?? 'full') === 'half';

        if ($isHalf) {
            if ($pending !== '') {
                $html .= '<div class="fb-fields-inline">' . $pending . $fieldHtml . '</div>';
                $pending = '';
            } else {
                $pending = $fieldHtml;
            }
            continue;
        }

        if ($pending !== '') {
            $html .= '<div class="fb-fields-inline">' . $pending . '</div>';
            $pending = '';
        }
        $html .= $fieldHtml;
    }

    if ($pending !== '') {
        $html .= '<div class="fb-fields-inline">' . $pending . '</div>';
    }

    return $html;
}

/**
 * Returns the full HTML for a [form id="x"] shortcode instance. Returns an
 * empty string if the form does not exist — fails silently rather than
 * showing a broken block on a live page.
 */
function fb_render_form_html(string $formId): string
{
    $form = fb_load_form($formId);
    if ($form === null) return '';

    $csrf   = fb_generate_csrf();
    $now    = time();
    $action = hsc(fb_plugin_base_url() . 'formbuilder-submit.php');
    $idEsc  = hsc($formId);

    $fieldsHtml = fb_group_fields_html($form['fields']);

    $tSubmit  = hsc(fb_t('fb_submit', 'Send'));
    $tSending = hsc(fb_t('fb_sending', 'Sending…'));
    $successMessage = $form['success_message'] !== ''
        ? hsc($form['success_message'])
        : hsc(fb_t('fb_default_success', 'Thank you, your submission has been received.'));

    return <<<HTML
    <form class="fb-form" method="post" action="{$action}" novalidate data-fb-form>
        <input type="hidden" name="form_id" value="{$idEsc}">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <input type="hidden" name="_ft" value="{$now}">
        <div class="fb-honeypot" aria-hidden="true">
            <label for="fb-hp-{$idEsc}">Website</label>
            <input type="text" id="fb-hp-{$idEsc}" name="_hp" tabindex="-1" autocomplete="off">
        </div>

        {$fieldsHtml}

        <button type="submit" class="fb-submit" data-label-default="{$tSubmit}" data-label-sending="{$tSending}">{$tSubmit}</button>

        <div class="fb-message" role="status" hidden></div>
        <div class="fb-success" hidden>{$successMessage}</div>
    </form>
    HTML;
}

/**
 * Returns the <link>/<script> tags for the form widget. Injected once per
 * page by the output-buffer hook in formbuilder-init.php, only when at
 * least one [form ...] shortcode was found — keeps every other page
 * untouched and fast.
 */
function fb_render_assets(): string
{
    $pluginUrl = fb_plugin_base_url();
    $cssPath = __DIR__ . '/assets/css/formbuilder-form.css';
    $jsPath  = __DIR__ . '/assets/js/formbuilder-form.js';
    $cssV    = file_exists($cssPath) ? filemtime($cssPath) : time();
    $jsV     = file_exists($jsPath) ? filemtime($jsPath) : time();

    $html  = '<link rel="stylesheet" href="' . hsc($pluginUrl . 'assets/css/formbuilder-form.css?v=' . $cssV) . '">' . "\n";
    $html .= '<script src="' . hsc($pluginUrl . 'assets/js/formbuilder-form.js?v=' . $jsV) . '" defer></script>' . "\n";

    $jsStrings = [
        'error_generic'    => fb_t('fb_error_generic', 'An error occurred. Please try again.'),
        'error_validation' => fb_t('fb_error_validation', 'Please fill in all required fields.'),
        'error_rate'       => fb_t('fb_error_rate', 'Too many submissions. Please try again later.'),
    ];
    $html .= '<script type="application/json" data-window-var="FB_LANG">' . json_encode($jsStrings, JSON_UNESCAPED_UNICODE) . '</script>' . "\n";

    return $html;
}
