<?php
/**
 * Maintenance Plugin — Admin Page Renderer
 *
 * Exposes maintenance_render_admin_page($view): array, the contract
 * required by admin/index.php's plugin_page router. Single view: a form
 * with the on/off toggle and the visitor-facing message.
 *
 * admin/actions.php (save settings) remains a standalone POST-only
 * endpoint — it never renders HTML.
 */

if (defined('MT_ADMIN_PAGE_LOADED')) return;
define('MT_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../maintenance-functions.php';
require_once __DIR__ . '/../maintenance-i18n.php';

/**
 * Resolves the CMS install's URL prefix (e.g. '/synaptikcms', or '' when
 * installed at the domain root) by comparing the CMS root's real
 * filesystem path (dirname(__DIR__, 2) — this file lives in
 * plugins/maintenance/admin/) against the server's document root.
 *
 * Deliberately independent of $_SERVER['SCRIPT_NAME'] / getBaseUrl():
 * both of those describe the CURRENTLY EXECUTING script's own location,
 * which is wrong here on two different pages that both need this same
 * prefix — the plugin's admin page (script is .../admin/index.php) and
 * its actions.php endpoint (script is .../plugins/maintenance/admin/
 * actions.php). Deriving the prefix from disk paths instead gives the
 * same, correct answer regardless of which script is asking.
 */
function mt_install_url_prefix(): string
{
    static $prefix = null;
    if ($prefix !== null) return $prefix;

    $cmsRoot = dirname(__DIR__, 3); // .../plugins/maintenance/admin -> plugins/maintenance -> plugins -> CMS root
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');

    if ($docRoot !== '') {
        $realCmsRoot = realpath($cmsRoot);
        $realDocRoot = realpath($docRoot);
        if ($realCmsRoot !== false && $realDocRoot !== false && strpos($realCmsRoot, $realDocRoot) === 0) {
            $prefix = str_replace('\\', '/', substr($realCmsRoot, strlen($realDocRoot)));
            return $prefix;
        }
    }

    $prefix = '';
    return $prefix;
}

/**
 * Scheme + host + port for the current request, always including a
 * non-default port explicitly (e.g. MAMP's :8888) — HTTP_HOST already
 * includes the port when the browser sent one, so this is a single
 * source of truth for the origin rather than something reconstructed
 * from parse_url() in multiple places (the previous, more error-prone
 * approach here).
 */
function mt_request_origin(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return ($isHttps ? 'https' : 'http') . '://' . $host;
}

/**
 * Resolves the core admin folder's real name (default 'admin', or whatever
 * install.php / a manual rename set as admin_dir in config.json). Shared
 * by every URL builder below instead of each re-reading config.json.
 */
function mt_admin_dir_name(): string
{
    static $adminDir = null;
    if ($adminDir !== null) return $adminDir;

    $adminDir     = 'admin';
    $settingsFile = dirname(__DIR__, 3) . '/config.json';
    if (file_exists($settingsFile)) {
        $decoded = json_decode(file_get_contents($settingsFile), true);
        if (is_array($decoded) && !empty($decoded['admin_dir'])) {
            $adminDir = $decoded['admin_dir'];
        }
    }
    return $adminDir;
}

/**
 * Base URL for the plugin's admin page (core's
 * admin/index.php?action=plugin_page router).
 */
function mt_admin_page_url(array $extraParams = []): string
{
    $params = array_merge(['action' => 'plugin_page', 'slug' => 'maintenance', 'view' => 'dashboard'], $extraParams);

    return mt_request_origin() . mt_install_url_prefix() . '/' . mt_admin_dir_name() . '/index.php?' . http_build_query($params);
}

function mt_admin_actions_url(): string
{
    return mt_request_origin() . mt_install_url_prefix() . '/plugins/maintenance/admin/actions.php';
}

/**
 * Absolute URL to the core admin login page, respecting a renamed admin
 * folder (admin_dir in config.json) instead of assuming '/admin/'.
 */
function mt_admin_login_url(): string
{
    return mt_request_origin() . mt_install_url_prefix() . '/' . mt_admin_dir_name() . '/auth.php';
}

/**
 * Renders the self-contained logo image picker: upload a new file or browse
 * the CMS's existing /files/ library, with a live preview and a remove
 * button. Same interaction pattern as the core admin's own "Settings Image
 * Picker" (admin/templates/settings-view.php's _sv_image_picker()) so the
 * experience is familiar, but implemented locally — that core helper is a
 * page-scoped function, not something plugins can call directly, and its
 * companion JS is inlined in that same core template rather than exposed
 * as a reusable script.
 */
function mt_logo_picker(string $currentPath): void
{
    $clean = ltrim($currentPath, '/');
    $src   = $clean ? mt_site_base_url() . $clean : '';
    ?>
    <div class="mt-sip-wrapper">
        <div class="mt-sip-preview"<?php echo $clean ? '' : ' style="display:none"'; ?>>
            <img class="mt-sip-preview-img" src="<?php echo htmlspecialchars($src); ?>" alt="">
            <button type="button" class="mt-btn mt-btn--danger mt-sip-remove-btn"><?php echo htmlspecialchars(mt_t('mt_logo_remove', 'Remove', 'admin')); ?></button>
        </div>
        <div class="mt-sip-controls">
            <input type="file" name="logo_image_file" accept="image/*" class="mt-sip-upload-input">
            <button type="button" class="mt-btn mt-sip-browse-btn"><?php echo htmlspecialchars(mt_t('mt_logo_browse', 'Select from files', 'admin')); ?></button>
        </div>
        <input type="hidden" name="logo_image_path" id="mt-sip-path" value="<?php echo htmlspecialchars($clean); ?>">
        <input type="hidden" name="logo_image_remove" id="mt-sip-remove" value="">
    </div>
    <?php
}

/**
 * Plugin contract entry point — see admin/index.php's plugin_page router
 * for the expected return shape.
 */
function maintenance_render_admin_page(string $view): array
{
    $settings = mt_load_settings();
    $csrf     = mt_generate_csrf();
    $msg      = $_GET['msg'] ?? '';

    ob_start();
    ?>
    <?php if ($msg === 'saved'): ?>
        <div class="message success"><?php echo htmlspecialchars(mt_t('mt_saved', 'Maintenance settings saved.', 'admin')); ?></div>
    <?php endif; ?>

    <form method="post" action="<?php echo htmlspecialchars(mt_admin_actions_url()); ?>" enctype="multipart/form-data" class="mt-form">
        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrf); ?>">

        <div class="mt-field mt-field--toggle">
            <label class="mt-toggle">
                <input type="checkbox" name="enabled" value="1" <?php echo !empty($settings['enabled']) ? 'checked' : ''; ?>>
                <span class="mt-toggle-track"><span class="mt-toggle-thumb"></span></span>
                <span class="mt-toggle-label"><?php echo htmlspecialchars(mt_t('mt_status_label', 'Maintenance mode', 'admin')); ?></span>
            </label>
            <p class="mt-status-text <?php echo !empty($settings['enabled']) ? 'is-on' : 'is-off'; ?>">
                <?php echo htmlspecialchars(!empty($settings['enabled'])
                    ? mt_t('mt_status_on', 'Enabled — visitors see the maintenance page', 'admin')
                    : mt_t('mt_status_off', 'Disabled — the site is live as normal', 'admin')); ?>
            </p>
        </div>

        <div class="mt-field">
            <label for="mt-title"><?php echo htmlspecialchars(mt_t('mt_title_label', 'Page title', 'admin')); ?></label>
            <input type="text" id="mt-title" name="title" value="<?php echo htmlspecialchars($settings['title'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(mt_t('mt_page_title', 'Under maintenance')); ?>">
            <p class="mt-field-help"><?php echo htmlspecialchars(mt_t('mt_title_help', 'Shown above the message and in the browser tab. Leave empty to use the default.', 'admin')); ?></p>
        </div>

        <div class="mt-field">
            <label for="mt-message"><?php echo htmlspecialchars(mt_t('mt_message_label', 'Message shown to visitors', 'admin')); ?></label>
            <textarea id="mt-message" name="message" rows="4"><?php echo htmlspecialchars($settings['message'] ?? ''); ?></textarea>
            <p class="mt-field-help"><?php echo htmlspecialchars(mt_t('mt_message_help', 'You stay able to browse the site normally while logged in as admin. Everyone else sees this message instead, with an HTTP 503 status.', 'admin')); ?></p>
        </div>

        <div class="mt-field">
            <label><?php echo htmlspecialchars(mt_t('mt_logo_label', 'Logo / icon', 'admin')); ?></label>
            <?php mt_logo_picker($settings['logo_image'] ?? ''); ?>
            <p class="mt-field-help"><?php echo htmlspecialchars(mt_t('mt_logo_help', 'Replaces the default icon. Any image size works — it is automatically scaled down to fit.', 'admin')); ?></p>
        </div>

        <div class="mt-field mt-field--colors">
            <label><?php echo htmlspecialchars(mt_t('mt_colors_label', 'Colors', 'admin')); ?></label>
            <div class="mt-color-row">
                <label class="mt-color-swatch">
                    <input type="color" name="bg_color" value="<?php echo htmlspecialchars($settings['bg_color']); ?>">
                    <?php echo htmlspecialchars(mt_t('mt_color_bg', 'Background', 'admin')); ?>
                </label>
                <label class="mt-color-swatch">
                    <input type="color" name="text_color" value="<?php echo htmlspecialchars($settings['text_color']); ?>">
                    <?php echo htmlspecialchars(mt_t('mt_color_text', 'Text', 'admin')); ?>
                </label>
                <label class="mt-color-swatch">
                    <input type="color" name="accent_color" value="<?php echo htmlspecialchars($settings['accent_color']); ?>">
                    <?php echo htmlspecialchars(mt_t('mt_color_accent', 'Accent', 'admin')); ?>
                </label>
            </div>
        </div>

        <div class="mt-form-actions">
            <button type="submit" class="mt-btn mt-btn--primary"><?php echo htmlspecialchars(mt_t('mt_save', 'Save', 'admin')); ?></button>
        </div>
    </form>

    <?php
    $logoPickerData = [
        'getFilesUrl' => mt_request_origin() . mt_install_url_prefix() . '/' . mt_admin_dir_name() . '/get-files.php',
        'siteBaseUrl' => mt_site_base_url(),
        'title'       => mt_t('mt_logo_modal_title', 'Select an image', 'admin'),
        'i18n' => [
            'home'      => mt_t('mt_logo_home', 'Home', 'admin'),
            'loading'   => mt_t('mt_logo_loading', 'Loading files…', 'admin'),
            'noImages'  => mt_t('mt_logo_no_images', 'No images in this folder.', 'admin'),
            'loadError' => mt_t('mt_logo_load_error', 'Error loading files.', 'admin'),
        ],
    ];
    ?>
    <script type="application/json" id="mt-logo-picker-data"><?php echo json_encode($logoPickerData); ?></script>
    <!-- defer: this page's own HTML (with this tag) renders before the core
         admin footer scripts, but logo-picker.js needs window.SynaptikImagePicker
         from common.js, which loads later in that footer — defer runs this
         only after the whole document (including common.js) has parsed. -->
    <script src="<?php echo htmlspecialchars(mt_plugin_asset_url('assets/js/logo-picker.js')); ?>" defer></script>
    <?php
    $html = ob_get_clean();

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(mt_plugin_asset_url('assets/css/maintenance-admin-embed.css')); ?>">
    <?php
    $extraHead = ob_get_clean();

    return [
        'title'      => mt_t('mt_admin_title', 'Maintenance', 'admin'),
        'html'       => $html,
        'extra_head' => $extraHead,
    ];
}

/**
 * Cache-busted URL to one of the plugin's own assets.
 */
function mt_plugin_asset_url(string $relativePath): string
{
    $absPath = dirname(__DIR__) . '/' . $relativePath;
    $v       = @filemtime($absPath) ?: time();

    return mt_request_origin() . mt_install_url_prefix() . '/plugins/maintenance/' . $relativePath . '?v=' . $v;
}
