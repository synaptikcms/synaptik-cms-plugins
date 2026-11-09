<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Analytics — Settings template
 */

$configFile = dirname(__DIR__, 3) . '/config.json';
$config     = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?: []) : [];
$retention  = (int)($config['analytics_retention_days'] ?? 365);
$dashboardWidgetEnabled = !empty($config['analytics_dashboard_widget_enabled'] ?? true);
$logDates   = an_log_date_range();

$saved      = ($_GET['saved']       ?? '') === '1';
$purged     = ($_GET['purged']      ?? '') === '1';
$purgeCount = (int)($_GET['purge_count'] ?? 0);

$saveUrl  = an_admin_page_url('settings', ['_ajax' => 'save_settings']);
$purgeUrl = an_admin_page_url('settings', ['_ajax' => 'purge_all']);
$geoipUrl = an_admin_page_url('settings', ['_ajax' => 'install_geoip']);
$aiEnableUrl  = an_admin_page_url('settings', ['_ajax' => 'ai_export_enable']);
$aiDisableUrl = an_admin_page_url('settings', ['_ajax' => 'ai_export_disable']);
require_once dirname(__DIR__, 2) . '/analytics-ai.php';
$aiToken = an_ai_export_token();

// GeoIP status
require_once dirname(__DIR__, 2) . '/analytics-geoip.php';
$geoInstalled = an_geoip_installed();
$geoMeta      = an_geoip_meta();
?>
<div class="an-wrap">

    <?php if ($saved): ?>
    <div class="notice notice-success"><?php echo hsc(an_t('an_settings_saved', 'Settings saved.', 'admin')); ?></div>
    <?php endif; ?>
    <?php if ($purged): ?>
    <div class="notice notice-success"><?php echo hsc(sprintf(an_t('an_purge_done', '%d log file(s) deleted.', 'admin'), $purgeCount)); ?></div>
    <?php endif; ?>

    <?php /* ── General settings ─────────────────────────────────────── */ ?>
    <div class="an-panel">
        <h3 class="an-panel-title"><?php echo hsc(an_t('an_settings_title', 'Analytics settings', 'admin')); ?></h3>

        <div class="form-group">
            <label for="an-retention"><?php echo hsc(an_t('an_retention_label', 'Log retention (days)', 'admin')); ?></label>
            <input type="number" id="an-retention" name="retention_days"
                   value="<?php echo (int)$retention; ?>" min="7" max="3650"
                   class="form-control" style="max-width:120px">
            <p class="form-hint"><?php echo hsc(an_t('an_retention_hint', 'Daily log files older than this are automatically deleted when settings are saved.', 'admin')); ?></p>
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" id="an-dashboard-widget" name="dashboard_widget_enabled" <?php echo $dashboardWidgetEnabled ? 'checked' : ''; ?>>
                <?php echo hsc(an_t('an_dashboard_widget_toggle', 'Show traffic widget on the admin dashboard', 'admin')); ?>
            </label>
        </div>

        <button type="button" id="an-save-btn" class="btn btn-primary"><?php echo hsc(an_t('an_settings_save', 'Save settings', 'admin')); ?></button>
        <p id="an-save-msg" class="form-hint" style="margin-top:.5rem;display:none"></p>
    </div>

    <?php /* ── GeoIP database ──────────────────────────────────────── */ ?>
    <div class="an-panel">
        <h3 class="an-panel-title"><?php echo hsc(an_t('an_geoip_title', 'Country database (GeoIP)', 'admin')); ?></h3>

        <div class="an-info-grid" style="margin-bottom:1rem">
            <div class="an-info-row">
                <span><?php echo hsc(an_t('an_geoip_status', 'Status', 'admin')); ?></span>
                <?php if ($geoInstalled): ?>
                <strong class="an-status-ok">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php echo hsc(an_t('an_geoip_installed', 'Installed', 'admin')); ?>
                </strong>
                <?php else: ?>
                <strong class="an-status-none"><?php echo hsc(an_t('an_geoip_not_installed', 'Not installed', 'admin')); ?></strong>
                <?php endif; ?>
            </div>
            <?php if ($geoInstalled && !empty($geoMeta['built_at'])): ?>
            <div class="an-info-row">
                <span><?php echo hsc(an_t('an_geoip_built', 'Last updated', 'admin')); ?></span>
                <strong><?php echo hsc($geoMeta['built_at']); ?></strong>
            </div>
            <div class="an-info-row">
                <span><?php echo hsc(an_t('an_geoip_records', 'IP ranges', 'admin')); ?></span>
                <strong>
                    <?php echo number_format((int)($geoMeta['v4_count'] ?? 0)); ?> IPv4 &nbsp;/&nbsp;
                    <?php echo number_format((int)($geoMeta['v6_count'] ?? 0)); ?> IPv6
                </strong>
            </div>
            <?php endif; ?>
        </div>

        <p class="form-hint" style="margin-bottom:.75rem">
            <?php echo hsc(an_t('an_geoip_hint', 'Downloads the DB-IP Country Lite database (~2 MB) and builds a local binary index. No external requests at tracking time. The database is updated monthly — click the button again to refresh it.', 'admin')); ?>
        </p>
        <p class="form-hint" style="margin-bottom:1rem">
            <?php echo an_t('an_geoip_credit', 'IP geolocation data by <a href="https://db-ip.com" target="_blank" rel="noopener" style="color:var(--primary)">DB-IP</a> (CC BY 4.0).', 'admin'); ?>
        </p>

        <button id="an-geoip-btn" class="btn btn-primary">
            <?php echo hsc($geoInstalled
                ? an_t('an_geoip_update_btn', 'Update country database', 'admin')
                : an_t('an_geoip_install_btn', 'Download &amp; install country database', 'admin')
            ); ?>
        </button>

        <div id="an-geoip-progress" style="display:none;margin-top:1rem">
            <div class="an-progress-track">
                <div class="an-progress-bar" id="an-geoip-bar"></div>
            </div>
            <p id="an-geoip-msg" class="form-hint" style="margin-top:.4rem"></p>
        </div>
    </div>

    <div class="an-panel">
        <h3 class="an-panel-title"><?php echo hsc(an_t('an_ai_export_title', 'AI traffic export (SEO Inspector)', 'admin')); ?></h3>
        <p class="form-hint" style="margin-bottom:.75rem"><?php echo hsc(an_t('an_ai_export_hint', 'Lets an external SEO Inspector read aggregated visits coming from AI assistants and AI crawler hits. Only counts are shared, never visitor data. Protected by a secret token sent in a request header.', 'admin')); ?></p>
        <?php if ($aiToken !== ''): ?>
        <div class="form-group">
            <label for="an-ai-url"><?php echo hsc(an_t('an_ai_export_url', 'Site URL to enter in SEO Inspector', 'admin')); ?></label>
            <input type="text" id="an-ai-url" class="form-control" readonly value="<?php echo hsc(an_site_base_url()); ?>">
        </div>
        <div class="form-group">
            <label for="an-ai-token"><?php echo hsc(an_t('an_ai_export_token', 'Secret token', 'admin')); ?></label>
            <input type="text" id="an-ai-token" class="form-control" readonly value="<?php echo hsc($aiToken); ?>">
        </div>
        <button type="button" id="an-ai-enable-btn" class="btn btn-outline"><?php echo hsc(an_t('an_ai_export_regen', 'Regenerate token', 'admin')); ?></button>
        <button type="button" id="an-ai-disable-btn" class="btn btn-danger"><?php echo hsc(an_t('an_ai_export_disable', 'Disable export', 'admin')); ?></button>
        <?php else: ?>
        <button type="button" id="an-ai-enable-btn" class="btn btn-primary"><?php echo hsc(an_t('an_ai_export_enable', 'Enable AI traffic export', 'admin')); ?></button>
        <?php endif; ?>
        <p id="an-ai-msg" class="form-hint" style="margin-top:.5rem;display:none"></p>
    </div>

    <?php /* ── Data ──────────────────────────────────────────────────── */ ?>
    <div class="an-panel">
        <h3 class="an-panel-title"><?php echo hsc(an_t('an_data_title', 'Data', 'admin')); ?></h3>

        <div class="an-info-grid">
            <div class="an-info-row">
                <span><?php echo hsc(an_t('an_oldest_log', 'Oldest log', 'admin')); ?></span>
                <strong><?php echo hsc($logDates['oldest'] ?? an_t('an_none', 'None', 'admin')); ?></strong>
            </div>
            <div class="an-info-row">
                <span><?php echo hsc(an_t('an_newest_log', 'Newest log', 'admin')); ?></span>
                <strong><?php echo hsc($logDates['newest'] ?? an_t('an_none', 'None', 'admin')); ?></strong>
            </div>
        </div>

        <button type="button" id="an-purge-btn" class="btn btn-danger" style="margin-top:1rem">
            <?php echo hsc(an_t('an_purge_all', 'Delete all analytics data', 'admin')); ?>
        </button>
        <p id="an-purge-msg" class="form-hint" style="margin-top:.5rem;display:none"></p>
    </div>

</div>

<?php
$settingsScriptData = [
    'saveUrl'   => $saveUrl,
    'purgeUrl'  => $purgeUrl,
    'geoipUrl'  => $geoipUrl,
    'aiEnableUrl'  => $aiEnableUrl,
    'aiDisableUrl' => $aiDisableUrl,
    'csrfToken' => $_SESSION['csrf_token'] ?? '',
    'i18n' => [
        'settingsSaved' => an_t('an_settings_saved', 'Settings saved.', 'admin'),
        'purgeConfirm'  => an_t('an_purge_confirm', 'Delete ALL analytics data? This cannot be undone.', 'admin'),
        'geoipWaiting'  => an_t('an_geoip_waiting', 'Downloading database, please wait… (this may take up to a minute)', 'admin'),
        'geoipError'    => an_t('an_geoip_error', 'Installation failed. Check server error logs.', 'admin'),
        'aiRegenConfirm'   => an_t('an_ai_export_regen_confirm', 'Generate a new token? SEO Inspector will need the new one.', 'admin'),
        'aiDisableConfirm' => an_t('an_ai_export_disable_confirm', 'Disable the AI traffic export?', 'admin'),
    ],
];
$settingsJsV = @filemtime(__DIR__ . '/../../assets/js/settings.js') ?: time();
?>
<script type="application/json" id="an-settings-data"><?php echo json_encode($settingsScriptData); ?></script>
<script src="<?php echo hsc(an_site_base_url() . 'plugins/analytics/assets/js/settings.js'); ?>?v=<?php echo $settingsJsV; ?>"></script>
