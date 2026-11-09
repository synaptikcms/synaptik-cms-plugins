<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Cookie Consent dashboard.
 * Single form covering all settings (general, appearance, categories,
 * privacy policy link). Same panel-based layout as the Redirects plugin
 * so the styling/tokens hook into the core admin's design system.
 */

$settings = cc_load_settings();
$csrf     = cc_generate_csrf();
$msg      = $_GET['msg'] ?? '';
?>
<?php if ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(cc_t('cc_saved', 'Settings saved.', 'admin')); ?></div>
<?php endif; ?>

<p class="help-text"><?php echo hsc(cc_t('cc_admin_intro', 'Configure the cookie consent banner shown to visitors. All strings can be translated via the plugin\'s lang/ files. The visitor\'s choice is stored in localStorage, no server-side cookie is required.', 'admin')); ?></p>

<form method="post" action="<?php echo hsc(cc_admin_actions_url()); ?>" class="cc-form">
    <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
    <input type="hidden" name="action" value="save_settings">

    <!-- Top-level on/off toggle (same visual pattern as the Maintenance plugin) -->
    <div class="cc-panel cc-panel--toggle">
        <label class="cc-toggle">
            <input type="checkbox" name="enabled" value="1" <?php echo !empty($settings['enabled']) ? 'checked' : ''; ?>>
            <span class="cc-toggle-track"><span class="cc-toggle-thumb"></span></span>
            <span class="cc-toggle-label"><?php echo hsc(cc_t('cc_status_label', 'Cookie consent banner', 'admin')); ?></span>
        </label>
        <p class="cc-status-text <?php echo !empty($settings['enabled']) ? 'is-on' : 'is-off'; ?>">
            <?php echo hsc(!empty($settings['enabled'])
                ? cc_t('cc_status_on', 'Enabled — visitors are asked for consent', 'admin')
                : cc_t('cc_status_off', 'Disabled — no banner is shown, no consent is asked (may be non-compliant if you have EU visitors)', 'admin')); ?>
        </p>
    </div>

    <!-- ── General ──────────────────────────────────────────────────── -->
    <div class="cc-panel">
        <h2><?php echo hsc(cc_t('cc_general_title', 'General', 'admin')); ?></h2>

        <div class="cc-field cc-field--inline">
            <label for="cc-position"><?php echo hsc(cc_t('cc_position_label', 'Banner position', 'admin')); ?></label>
            <select id="cc-position" name="position">
                <option value="bottom" <?php echo ($settings['position'] ?? 'bottom') === 'bottom' ? 'selected' : ''; ?>><?php echo hsc(cc_t('cc_position_bottom', 'Bottom bar (full width)', 'admin')); ?></option>
                <option value="center" <?php echo ($settings['position'] ?? 'bottom') === 'center' ? 'selected' : ''; ?>><?php echo hsc(cc_t('cc_position_center', 'Centered dialog (modal)', 'admin')); ?></option>
            </select>
        </div>

        <div class="cc-field cc-field--inline">
            <label for="cc-exp"><?php echo hsc(cc_t('cc_expiration_label', 'Consent lifetime (days)', 'admin')); ?></label>
            <input type="number" id="cc-exp" name="expiration_days" min="1" max="3650" value="<?php echo (int)($settings['expiration_days'] ?? 180); ?>">
            <p class="cc-field-hint"><?php echo hsc(cc_t('cc_expiration_hint', 'After this delay the banner reappears so the visitor is re-prompted. 180 days (6 months) is the CNIL\'s recommended maximum.', 'admin')); ?></p>
        </div>

        <label class="cc-checkbox-label">
            <input type="checkbox" name="show_reject_all" value="1" <?php echo !empty($settings['show_reject_all']) ? 'checked' : ''; ?>>
            <span><?php echo hsc(cc_t('cc_show_reject_label', 'Show a "Reject all" button next to "Accept all" (recommended for GDPR compliance)', 'admin')); ?></span>
        </label>
    </div>

    <!-- ── Appearance ───────────────────────────────────────────────── -->
    <div class="cc-panel">
        <h2><?php echo hsc(cc_t('cc_appearance_title', 'Appearance', 'admin')); ?></h2>
        <p class="cc-panel-hint"><?php echo hsc(cc_t('cc_appearance_hint', 'Colors used by the banner and settings modal on the front-end. Leave empty to fall back to the built-in palette.', 'admin')); ?></p>

        <div class="cc-color-row">
            <div class="cc-field cc-field--color">
                <label for="cc-bg"><?php echo hsc(cc_t('cc_color_bg', 'Background', 'admin')); ?></label>
                <input type="color" id="cc-bg" name="bg_color" value="<?php echo hsc($settings['bg_color'] ?? '#ffffff'); ?>">
            </div>
            <div class="cc-field cc-field--color">
                <label for="cc-text"><?php echo hsc(cc_t('cc_color_text', 'Text', 'admin')); ?></label>
                <input type="color" id="cc-text" name="text_color" value="<?php echo hsc($settings['text_color'] ?? '#1a1a1a'); ?>">
            </div>
            <div class="cc-field cc-field--color">
                <label for="cc-accent"><?php echo hsc(cc_t('cc_color_accent', 'Accent (primary button)', 'admin')); ?></label>
                <input type="color" id="cc-accent" name="accent_color" value="<?php echo hsc($settings['accent_color'] ?? '#3ea34e'); ?>">
            </div>
        </div>
    </div>

    <!-- ── Categories ───────────────────────────────────────────────── -->
    <div class="cc-panel">
        <h2><?php echo hsc(cc_t('cc_categories_title', 'Cookie categories', 'admin')); ?></h2>
        <p class="cc-panel-hint"><?php echo hsc(cc_t('cc_categories_hint', 'Which cookie categories the visitor can opt into. "Strictly necessary" is always on and cannot be disabled. Uncheck a category if your site never uses it, so the visitor is not shown an irrelevant toggle.', 'admin')); ?></p>

        <div class="cc-cat-row">
            <label class="cc-cat-item">
                <input type="checkbox" name="category_analytics" value="1" <?php echo !empty($settings['category_analytics']) ? 'checked' : ''; ?>>
                <div>
                    <strong><?php echo hsc(cc_t('cc_cat_analytics_title', 'Analytics', 'admin')); ?></strong>
                    <p><?php echo hsc(cc_t('cc_cat_analytics_admin_desc', 'Enable if your site uses analytics tools like Plausible, Umami, Matomo, or Google Analytics.', 'admin')); ?></p>
                </div>
            </label>

            <label class="cc-cat-item">
                <input type="checkbox" name="category_marketing" value="1" <?php echo !empty($settings['category_marketing']) ? 'checked' : ''; ?>>
                <div>
                    <strong><?php echo hsc(cc_t('cc_cat_marketing_title', 'Marketing', 'admin')); ?></strong>
                    <p><?php echo hsc(cc_t('cc_cat_marketing_admin_desc', 'Enable if your site uses ad tracking, retargeting pixels, or conversion tracking (Google Ads, Meta Pixel).', 'admin')); ?></p>
                </div>
            </label>
        </div>
    </div>

    <!-- ── Privacy policy link ──────────────────────────────────────── -->
    <div class="cc-panel">
        <h2><?php echo hsc(cc_t('cc_privacy_title', 'Privacy policy link', 'admin')); ?></h2>
        <p class="cc-panel-hint"><?php echo hsc(cc_t('cc_privacy_hint', 'Optional. Displayed inside the banner as a "Privacy policy" link. Accepts either a full https:// URL or a site path starting with / (e.g. /privacy/). Leave empty to hide the link.', 'admin')); ?></p>

        <div class="cc-field">
            <label for="cc-privacy"><?php echo hsc(cc_t('cc_privacy_url_label', 'URL', 'admin')); ?></label>
            <input type="text" id="cc-privacy" name="privacy_policy_url" value="<?php echo hsc($settings['privacy_policy_url'] ?? ''); ?>" placeholder="/privacy/">
        </div>
    </div>

    <!-- ── Save ─────────────────────────────────────────────────────── -->
        <button style="margin-bottom:20px;" type="submit" class="cc-btn cc-btn--primary"><?php echo hsc(cc_t('cc_save', 'Save settings', 'admin')); ?></button>
</form>

<!-- ── Persistent access shortcode ──────────────────────────────────────── -->
<div class="cc-panel">
    <h2><?php echo hsc(cc_t('cc_shortcode_title', 'Let visitors reopen their preferences', 'admin')); ?></h2>
    <p class="cc-panel-hint"><?php echo hsc(cc_t('cc_shortcode_intro', 'GDPR requires visitors to be able to change or withdraw their consent at any time. Paste this shortcode anywhere — in the site footer text, a menu item, your privacy policy page — and it will render as a text link that opens the preferences modal when clicked. No floating button, no imposed UI.', 'admin')); ?></p>
    <pre class="cc-code">[cookie_settings]</pre>
    <p class="cc-panel-hint" style="margin-top:1rem;"><?php echo hsc(cc_t('cc_shortcode_note', 'The link text can be customized in the plugin\'s lang/ files (key: cc_settings_link_label). Default is "Cookie settings".', 'admin')); ?></p>
</div>

<!-- ── Developer note ───────────────────────────────────────────────── -->
<div class="cc-panel cc-panel--dev">
    <h2><?php echo hsc(cc_t('cc_dev_title', 'For theme and plugin developers', 'admin')); ?></h2>
    <p class="cc-panel-hint"><?php echo hsc(cc_t('cc_dev_intro', 'A public JavaScript API is exposed as window.SynaptikCookies. Use it to defer any tracking script until the visitor has granted consent for the matching category:', 'admin')); ?></p>
    <pre class="cc-code">SynaptikCookies.onConsent('analytics', function () {
    // Load your Plausible / Umami / GA script here.
    // Runs immediately if consent was already given.
});

SynaptikCookies.hasConsent('marketing'); // → true|false
SynaptikCookies.openSettings();          // reopen the modal
SynaptikCookies.resetConsent();          // clear stored choice</pre>
</div>
