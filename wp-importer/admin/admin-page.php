<?php
/**
 * WordPress Importer — Admin Page Renderer
 *
 * Exposes wp_importer_render_admin_page($view): array, the contract expected
 * by admin/index.php's plugin_page router.
 *
 * Views:
 *   'upload'  — (default) Upload form + import options.
 *   'process' — Handles the POST from the upload form and shows results.
 */

if (defined('WPI_ADMIN_PAGE_LOADED')) return;
define('WPI_ADMIN_PAGE_LOADED', true);

require_once __DIR__ . '/../wp-importer-i18n.php';
require_once __DIR__ . '/../wp-importer-parser.php';
require_once __DIR__ . '/../wp-importer-writer.php';

/**
 * Returns the absolute URL to the plugin's admin page, reading admin_dir
 * from config.json rather than hardcoding.
 */
function wpi_admin_page_url(array $extraParams = []): string
{
    $params = array_merge(
        ['action' => 'plugin_page', 'slug' => 'wp-importer', 'view' => 'upload'],
        $extraParams
    );

    $configFile = dirname(__DIR__, 3) . '/config.json';
    $adminDir   = 'admin';
    if (file_exists($configFile)) {
        $decoded = json_decode(file_get_contents($configFile), true);
        if (is_array($decoded) && !empty($decoded['admin_dir'])) {
            $adminDir = $decoded['admin_dir'];
        }
    }

    return wpi_site_base_url() . $adminDir . '/index.php?' . http_build_query($params);
}

/**
 * Derives the site base URL from this file's filesystem position.
 * admin-page.php is 3 levels below the CMS root:
 *   admin/ -> wp-importer/ -> plugins/ -> CMS root
 */
function wpi_site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $cmsRoot = str_replace('\\', '/', dirname(__DIR__, 3));
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $realCms = str_replace('\\', '/', realpath($cmsRoot) ?: $cmsRoot);
    $realDoc = $docRoot !== '' ? str_replace('\\', '/', realpath($docRoot) ?: $docRoot) : '';

    if ($realDoc !== '' && strpos($realCms, $realDoc) === 0) {
        $path = substr($realCms, strlen($realDoc));
    } elseif ($docRoot !== '' && strpos($cmsRoot, $docRoot) === 0) {
        $path = substr($cmsRoot, strlen($docRoot));
    } else {
        $path = function_exists('getBaseUrl') ? parse_url(getBaseUrl(), PHP_URL_PATH) : '/';
    }

    $path = '/' . trim((string)$path, '/');
    return $path === '/' ? $host . '/' : $host . $path . '/';
}

/**
 * Returns a cache-busted URL to one of the plugin's assets.
 */
function wpi_asset_url(string $relativePath): string
{
    $abs = dirname(__DIR__) . '/' . $relativePath;
    $v   = @filemtime($abs) ?: time();
    return wpi_site_base_url() . 'plugins/wp-importer/' . $relativePath . '?v=' . $v;
}

/**
 * Plugin page contract — called by admin/index.php's plugin_page router.
 */
function wp_importer_render_admin_page(string $view): array
{
    $cmsRoot = dirname(__DIR__, 3);
    if (!function_exists('sl_admin_save_item')) {
        $adl = $cmsRoot . '/core/admin-data-layer.php';
        if (file_exists($adl)) require_once $adl;
    }
    if (!function_exists('sl_load_index')) {
        $dl = $cmsRoot . '/core/data-layer.php';
        if (file_exists($dl)) require_once $dl;
    }

    $html = '';
    switch ($view) {
        case 'process':
            ob_start();
            wpi_render_process_view();
            $html = ob_get_clean();
            break;
        default:
            ob_start();
            wpi_render_upload_view();
            $html = ob_get_clean();
            break;
    }

    ob_start();
    ?>
    <link rel="stylesheet" href="<?php echo hsc(wpi_asset_url('assets/css/wpi-admin.css')); ?>">
    <?php
    $extraHead = ob_get_clean();

    return [
        'title'      => wpi_t('wpi_admin_title', 'WordPress Importer', 'admin'),
        'html'       => $html,
        'extra_head' => $extraHead,
    ];
}

// ─── Upload view ──────────────────────────────────────────────────────────────

function wpi_render_upload_view(): void
{
    $redirectsActive = function_exists('rd_create_redirect') ||
                       file_exists(dirname(__DIR__, 2) . '/redirects/plugin.json');
    $csrf = $_SESSION['csrf_token'] ?? '';
    ?>
    <form method="post"
          action="<?php echo hsc(wpi_admin_page_url(['view' => 'process'])); ?>"
          enctype="multipart/form-data">

        <input type="hidden" name="csrf_token" value="<?php echo hsc($csrf); ?>">

        <div class="wpi-panel">
            <h2><?php echo wpi_t('wpi_intro_title', 'Import from WordPress', 'admin'); ?></h2>
            <p class="wpi-panel-hint"><?php echo wpi_t('wpi_intro', 'Upload your WordPress XML export file to import posts, pages, categories, tags and SEO metadata into Synaptik CMS.', 'admin'); ?></p>
            <p class="wpi-panel-hint"><?php echo wpi_t('wpi_export_help', 'In WordPress, go to <strong>Tools → Export</strong>, choose "All content" and download the XML file.', 'admin'); ?></p>

            <div class="wpi-field">
                <label for="wpi_file"><?php echo wpi_t('wpi_file_label', 'WordPress XML file (.xml)', 'admin'); ?></label>
                <input type="file" id="wpi_file" name="wpi_file" accept=".xml,text/xml,application/xml" required>
            </div>
        </div>

        <div class="wpi-panel">
            <h2><?php echo wpi_t('wpi_content_section', 'What to Import', 'admin'); ?></h2>

            <div class="wpi-field">
                <label for="wpi_post_status"><?php echo wpi_t('wpi_post_status', 'Post Status', 'admin'); ?></label>
                <select id="wpi_post_status" name="post_status">
                    <option value="all"><?php echo wpi_t('wpi_status_all', 'All (published + drafts)', 'admin'); ?></option>
                    <option value="published" selected><?php echo wpi_t('wpi_status_published', 'Published only', 'admin'); ?></option>
                    <option value="draft"><?php echo wpi_t('wpi_status_draft', 'Drafts only', 'admin'); ?></option>
                </select>
            </div>

            <div class="wpi-field">
                <label><?php echo wpi_t('wpi_content_types', 'Content Types', 'admin'); ?></label>
                <div class="wpi-checkboxes">
                    <label class="checkbox-label">
                        <input type="checkbox" name="import_categories" value="1" checked>
                        <?php echo wpi_t('wpi_import_categories', 'Categories', 'admin'); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="import_tags" value="1" checked>
                        <?php echo wpi_t('wpi_import_tags', 'Tags', 'admin'); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="import_posts" value="1" checked>
                        <?php echo wpi_t('wpi_import_posts', 'Posts → Articles', 'admin'); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="import_pages" value="1" checked>
                        <?php echo wpi_t('wpi_import_pages', 'Pages', 'admin'); ?>
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="import_projects" value="1">
                        <?php echo wpi_t('wpi_import_projects', 'Projects / Portfolio', 'admin'); ?>
                    </label>
                </div>
            </div>
        </div>

        <div class="wpi-panel">
            <h2><?php echo wpi_t('wpi_options_section', 'Import Options', 'admin'); ?></h2>

            <div class="wpi-field">
                <label><?php echo wpi_t('wpi_duplicates', 'Duplicate Handling', 'admin'); ?></label>
                <label class="checkbox-label">
                    <input type="checkbox" name="overwrite" value="1">
                    <?php echo wpi_t('wpi_overwrite', 'Overwrite existing items with the same URL slug', 'admin'); ?>
                </label>
            </div>

            <?php if ($redirectsActive): ?>
            <div class="wpi-field">
                <label><?php echo wpi_t('wpi_redirects_label', 'URL Redirects', 'admin'); ?></label>
                <label class="checkbox-label">
                    <input type="checkbox" name="generate_redirects" value="1" checked>
                    <?php echo wpi_t('wpi_redirects_help', 'Create 301 redirects from old WordPress URLs to new Synaptik CMS URLs (requires Redirects plugin)', 'admin'); ?>
                </label>
            </div>
            <?php endif; ?>

            <div class="wpi-note wpi-note--warning">
                <strong><?php echo wpi_t('wpi_images_notice_title', 'Note on images:', 'admin'); ?></strong>
                <?php echo wpi_t('wpi_images_notice', 'Images are not downloaded automatically. Your content will retain the original WordPress image URLs. Re-upload them via the File Manager and update the links manually, or keep the external URLs if the images remain accessible.', 'admin'); ?>
            </div>
        </div>

        <div class="wpi-form-actions">
            <button type="submit" class="wpi-btn wpi-btn--primary">
                <?php echo wpi_t('wpi_import_btn', 'Start Import', 'admin'); ?>
            </button>
        </div>

    </form>
    <?php
}

// ─── Process view ─────────────────────────────────────────────────────────────

function wpi_render_process_view(): void
{
    $token   = $_POST['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';
    if ($token === '' || $session === '' || !hash_equals($session, $token)) {
        echo '<div class="message error">' . wpi_t('wpi_csrf_error', 'Security validation failed. Please go back and try again.', 'admin') . '</div>';
        return;
    }

    if (empty($_FILES['wpi_file']['tmp_name'])) {
        echo '<div class="message error">' . wpi_t('wpi_no_file', 'No file was uploaded.', 'admin') . '</div>';
        return;
    }

    $uploadError = $_FILES['wpi_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        echo '<div class="message error">' . wpi_t('wpi_upload_error', 'Upload failed (code: ', 'admin') . $uploadError . ').</div>';
        return;
    }

    $ext = strtolower(pathinfo($_FILES['wpi_file']['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext !== 'xml') {
        echo '<div class="message error">' . wpi_t('wpi_invalid_type', 'Invalid file type. Please upload a WordPress XML export file (.xml).', 'admin') . '</div>';
        return;
    }

    try {
        $parsed = wpi_parse_wxr($_FILES['wpi_file']['tmp_name']);
    } catch (RuntimeException $e) {
        echo '<div class="message error">' . hsc(wpi_t('wpi_parse_error', 'Parse error: ', 'admin') . $e->getMessage()) . '</div>';
        return;
    }

    $options = [
        'import_categories'  => !empty($_POST['import_categories']),
        'import_tags'        => !empty($_POST['import_tags']),
        'import_posts'       => !empty($_POST['import_posts']),
        'import_pages'       => !empty($_POST['import_pages']),
        'import_projects'    => !empty($_POST['import_projects']),
        'overwrite'          => !empty($_POST['overwrite']),
        'post_status'        => in_array($_POST['post_status'] ?? '', ['all', 'published', 'draft'], true)
                                    ? $_POST['post_status'] : 'published',
        'generate_redirects' => !empty($_POST['generate_redirects']),
    ];

    $counts = wpi_import_all($parsed, $options);
    ?>
    <div class="message success">
        <strong><?php echo wpi_t('wpi_import_complete', 'Import complete!', 'admin'); ?></strong>
    </div>

    <div class="wpi-panel">
        <h2><?php echo wpi_t('wpi_results_title', 'Import Summary', 'admin'); ?></h2>
        <table class="wpi-summary-table">
            <tbody>
            <tr><td><?php echo wpi_t('wpi_result_cats',     'Categories imported', 'admin'); ?></td><td><strong><?php echo (int)$counts['categories_imported']; ?></strong></td></tr>
            <tr><td><?php echo wpi_t('wpi_result_tags',     'Tags imported',       'admin'); ?></td><td><strong><?php echo (int)$counts['tags_imported']; ?></strong></td></tr>
            <tr><td><?php echo wpi_t('wpi_result_articles', 'Articles imported',   'admin'); ?></td><td><strong><?php echo (int)$counts['articles_imported']; ?></strong></td></tr>
            <tr><td><?php echo wpi_t('wpi_result_pages',    'Pages imported',      'admin'); ?></td><td><strong><?php echo (int)$counts['pages_imported']; ?></strong></td></tr>
            <tr><td><?php echo wpi_t('wpi_result_projects', 'Projects imported',   'admin'); ?></td><td><strong><?php echo (int)$counts['projects_imported']; ?></strong></td></tr>
            <?php if ($counts['redirects_created'] > 0): ?>
            <tr><td><?php echo wpi_t('wpi_result_redirects', 'Redirects created', 'admin'); ?></td><td><strong><?php echo (int)$counts['redirects_created']; ?></strong></td></tr>
            <?php endif; ?>
            <?php if ($counts['skipped'] > 0): ?>
            <tr><td><?php echo wpi_t('wpi_result_skipped', 'Items skipped', 'admin'); ?></td><td><?php echo (int)$counts['skipped']; ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($counts['errors']) || !empty($parsed['errors'])): ?>
    <div class="wpi-panel">
        <h2><?php echo wpi_t('wpi_warnings_title', 'Warnings', 'admin'); ?></h2>
        <ul class="wpi-errors">
            <?php foreach (array_merge($counts['errors'], $parsed['errors']) as $err): ?>
            <li><?php echo hsc($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="wpi-panel">
        <p class="wpi-panel-hint"><?php echo wpi_t('wpi_next_steps', 'Next steps: review your imported content, re-upload images via the File Manager, and check your redirects if the Redirects plugin is active.', 'admin'); ?></p>
    </div>

    <div class="wpi-form-actions">
        <a href="<?php echo hsc(wpi_admin_page_url()); ?>" class="wpi-btn wpi-btn--secondary">
            <?php echo wpi_t('wpi_import_another', 'Import Another File', 'admin'); ?>
        </a>
    </div>
    <?php
}
