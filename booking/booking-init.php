<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Booking Plugin — Init
 *
 * Three independent responsibilities, all guarded so they only do work in
 * the right context:
 *
 * 1. Admin menu registration ('admin_menu' hook, see plugin-api.php) — the
 *    sidebar entry is registered here whenever the plugin is loaded in an
 *    admin context (admin/plugins.php triggers this via
 *    pl_get_admin_menu_items(), consumed by admin/includes/sidebar.php).
 *
 * 2. Dashboard widget ('admin_dashboard' hook) — pending count + next
 *    upcoming appointments, same pattern as the Analytics traffic widget.
 *
 * 3. Front-end [booking_form] shortcode resolution — the core's
 *    render_content_html() shortcode pipeline is closed (no filter hook
 *    runs inside it), so there is no clean way to register a new
 *    [shortcode] without editing core files. Instead, this plugin buffers
 *    the entire page output and resolves [booking_form] once the page is
 *    fully rendered — the shortcode passes through the core pipeline
 *    untouched (no parser recognizes it) and is replaced here, right
 *    before the response is sent to the browser. Only runs for front-end
 *    HTML responses (never admin/AJAX/CLI — see the guard below).
 */

if (defined('BK_INIT_LOADED')) return;
define('BK_INIT_LOADED', true);

require_once __DIR__ . '/booking-functions.php';
require_once __DIR__ . '/booking-i18n.php';

// Only needed when the plugin's admin page is actually being rendered
// (admin/plugin-page.php calls booking_render_admin_page()). Loaded
// unconditionally here rather than lazily inside the router, since it's a
// single lightweight require and keeping the contract function always
// available avoids a fragile function_exists() dance in plugin-page.php.
require_once __DIR__ . '/admin/admin-page.php';

// ─── 1. Admin menu entry ────────────────────────────────────────────────────
// pl_on_admin_menu() is a no-op registration when called from a front-end
// request — the 'admin_menu' hook it attaches to is only ever fired from
// admin/plugins.php, so this is safe to call unconditionally here.
if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        $label = bk_t('bk_admin_title', 'Booking', 'admin');

        pl_register_admin_menu(
            'booking',
            $label,
            bk_admin_page_url('calendar'),
            '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/>'
        );

        // The sidebar label is HTML-escaped by admin/includes/sidebar.php,
        // so a real <span> badge can't be injected through the label
        // parameter above. A <link> element (unlike a bare <style> block)
        // is valid anywhere in the body, including in the middle of the
        // sidebar's <ul><li> markup where this hook fires — so the badge
        // rule is served as a tiny data: URI stylesheet instead, targeting
        // this plugin's sidebar link by its unique data-label attribute.
        // Matches the shape of the core's own data-badge pill (see
        // .sidebar-subitem[data-badge]::after in admin-sidebar.css). Same
        // technique as the Comments plugin's own pending-count badge.
        $pending = bk_count_pending();
        if ($pending > 0) {
            $labelAttr = addslashes($label);
            $css = 'a[data-label="' . $labelAttr . '"]{position:relative}'
                 . 'a[data-label="' . $labelAttr . '"]::after{content:"' . (int)$pending . '";'
                 . 'margin-left:auto;padding:1px 7px;line-height:1.4;background:var(--primary);color:#fff;'
                 . 'font-size:.78em;font-weight:700;border-radius:999px}';
            echo '<link rel="stylesheet" href="data:text/css,' . rawurlencode($css) . '">';
        }
    });
}

// ─── 2. Dashboard widget ────────────────────────────────────────────────────
// Reuses .bk-status/design tokens from booking-admin-embed.css (linked here
// since admin_dashboard has no extra_head slot). Reads only the lightweight
// index (bk_load_index()), never opens individual appointment files.
if (function_exists('pl_on_admin_dashboard')) {
    pl_on_admin_dashboard(function () {
        if (empty(bk_load_settings()['show_dashboard_widget'])) return;

        $index = bk_load_index();

        $activeUpcoming = array_values(array_filter($index, function ($a) {
            return in_array($a['status'] ?? '', ['pending', 'confirmed'], true) && !bk_appointment_is_past($a);
        }));
        usort($activeUpcoming, fn($a, $b) => strcmp($a['date'] . $a['start_time'], $b['date'] . $b['start_time']));

        $pendingCount      = bk_count_pending();
        $upcomingCount     = count($activeUpcoming);
        $nextAppointments  = array_slice($activeUpcoming, 0, 3);
        $today             = date('Y-m-d');

        $statusLabels = [
            'pending'   => bk_t('bk_status_pending', 'Pending', 'admin'),
            'confirmed' => bk_t('bk_status_confirmed', 'Confirmed', 'admin'),
        ];

        $cssPath = __DIR__ . '/assets/css/booking-admin-embed.css';
        $cssV    = @filemtime($cssPath) ?: time();
        ?>
        <link rel="stylesheet" href="<?php echo hsc(bk_plugin_base_url() . 'assets/css/booking-admin-embed.css'); ?>?v=<?php echo $cssV; ?>">
        <div class="dashboard-panel dashboard-panel--sm bk-dash-widget">
            <div class="bk-dash-widget-header">
                <h3>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
                    <?php echo hsc(bk_t('bk_dashboard_widget_title', 'Appointments', 'admin')); ?>
                </h3>
                <a href="<?php echo hsc(bk_admin_page_url('requests')); ?>" class="bk-dash-more"><?php echo hsc(bk_t('bk_view_all', 'View all →', 'admin')); ?></a>
            </div>
            <div class="bk-dash-kpi-row">
                <div class="bk-dash-kpi bk-dash-kpi--pending">
                    <div class="bk-dash-kpi-value"><?php echo (int)$pendingCount; ?></div>
                    <div class="bk-dash-kpi-label"><?php echo hsc(bk_t('bk_dashboard_kpi_pending', 'Awaiting confirmation', 'admin')); ?></div>
                </div>
                <div class="bk-dash-kpi">
                    <div class="bk-dash-kpi-value"><?php echo (int)$upcomingCount; ?></div>
                    <div class="bk-dash-kpi-label"><?php echo hsc(bk_t('bk_dashboard_kpi_upcoming', 'Upcoming appointments', 'admin')); ?></div>
                </div>
            </div>
            <?php if (empty($nextAppointments)): ?>
            <p class="bk-dash-empty"><?php echo hsc(bk_t('bk_dashboard_empty', 'No upcoming appointments.', 'admin')); ?></p>
            <?php else: ?>
            <div class="bk-dash-list">
                <?php foreach ($nextAppointments as $a):
                    $type    = bk_get_appointment_type($a['type'] ?? '');
                    $isToday = ($a['date'] ?? '') === $today;
                ?>
                <a class="bk-dash-row" href="<?php echo hsc(bk_admin_page_url('requests', ['date' => $a['date']])); ?>">
                    <div class="bk-dash-row-date<?php echo $isToday ? ' is-today' : ''; ?>">
                        <span class="bk-dash-day"><?php echo hsc(date('j', strtotime($a['date']))); ?></span>
                        <span class="bk-dash-mon"><?php echo hsc(date('M', strtotime($a['date']))); ?></span>
                    </div>
                    <div class="bk-dash-row-body">
                        <span class="bk-dash-row-name"><?php echo hsc(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''))); ?></span>
                        <span class="bk-dash-row-meta"><?php echo hsc(($type['label'] ?? $a['type'] ?? '') . ' · ' . ($a['start_time'] ?? '') . '–' . ($a['end_time'] ?? '')); ?></span>
                    </div>
                    <span class="bk-status bk-status--<?php echo hsc($a['status']); ?>"><?php echo hsc($statusLabels[$a['status']] ?? $a['status']); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    });
}

// ─── 3. Front-end shortcode resolution ─────────────────────────────────────
// strpos(...) !== false (not === 0) because the CMS may live in a
// sub-directory (e.g. /synaptikcms/booking/booking.php), so '/booking/' will not
// necessarily be the first path segment.
$__bk_isCli    = PHP_SAPI === 'cli';
$__bk_isPlugin = strpos($_SERVER['REQUEST_URI'] ?? '', '/booking/') !== false;
$__bk_cfgFile  = dirname(__DIR__, 2) . '/config.json';
$__bk_adminDir = 'admin';
if (file_exists($__bk_cfgFile)) {
    $__bk_cfg = json_decode(file_get_contents($__bk_cfgFile), true);
    if (is_array($__bk_cfg) && !empty($__bk_cfg['admin_dir'])) {
        $__bk_adminDir = $__bk_cfg['admin_dir'];
    }
    unset($__bk_cfg);
}
unset($__bk_cfgFile);
$__bk_isAdmin = strpos($_SERVER['REQUEST_URI'] ?? '', '/' . $__bk_adminDir . '/') !== false;

// llms.txt/llms-full.txt render page content as plain text via
// render_content_html() + strip_tags() (see core/llms.php, core/llms-full.php).
// strip_tags() doesn't touch "[booking_form]" — it isn't an HTML tag — so the
// literal shortcode marker survives into that plain-text buffer. Without this
// guard, this hook would still match it and splice the widget's <link>/<script>
// tags into a text/plain response.
$__bk_isLlms = (bool) preg_match('#/llms(-full)?\.txt(?:\?|$)#', $_SERVER['REQUEST_URI'] ?? '');

if (!$__bk_isCli && !$__bk_isPlugin && !$__bk_isAdmin && !$__bk_isLlms) {
    require_once __DIR__ . '/booking-shortcode.php';

    ob_start(function (string $html): string {
        if (strpos($html, '[booking_form]') === false) {
            return $html;
        }

        // Only ever splice assets into an actual HTML document. A buffer
        // with no </head> isn't one (JSON, XML, plain text, …) — leave it
        // untouched rather than prepending <link>/<script> tags to it.
        if (stripos($html, '</head>') === false) {
            return $html;
        }

        $html = str_replace('[booking_form]', bk_render_form_html(), $html);

        // Inject CSS/JS just before </head> — only happens on pages that use
        // the shortcode, so the asset cost never lands on unrelated pages.
        $assets = bk_render_assets();
        $html = preg_replace('/<\/head>/i', $assets . '</head>', $html, 1);

        return $html;
    });
}

unset($__bk_isCli, $__bk_isPlugin, $__bk_isAdmin, $__bk_isLlms);
