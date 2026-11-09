<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Analytics — Overview template
 * Renders the main stats dashboard: KPI cards, line chart, device/medium donuts.
 */

$range = $_GET['range'] ?? '30d';
if (!in_array($range, ['7d', '30d', '90d', '365d'], true)) $range = '30d';

$stats    = an_build_aggregate($range);
$today    = an_today_stats();
$logDates = an_log_date_range();

// ── Range selector labels ─────────────────────────────────────────────────────
$ranges = [
    '7d'   => an_t('an_range_7d',   'Last 7 days',   'admin'),
    '30d'  => an_t('an_range_30d',  'Last 30 days',  'admin'),
    '90d'  => an_t('an_range_90d',  'Last 90 days',  'admin'),
    '365d' => an_t('an_range_365d', 'Last 365 days', 'admin'),
];

// ── Prepare chart data (JSON for inline JS) ───────────────────────────────────
$chartDays       = array_keys($stats['by_day']);
$chartViews      = array_values($stats['by_day']);
$chartUnique     = array_values($stats['by_day_unique'] ?? array_fill(0, count($chartDays), 0));

// Device donut data
$devices  = $stats['devices'];
$devTotal = max(1, array_sum($devices));

// Medium donut data
$media    = $stats['ref_media'];
$medTotal = max(1, array_sum($media));

// Best day
$bestDay   = '';
$bestCount = 0;
foreach ($stats['by_day'] as $d => $c) {
    if ($c > $bestCount) { $bestCount = $c; $bestDay = $d; }
}

// i18n strings for the metric toggle
$labelViews  = an_t('an_chart_metric_views',  'Views',            'admin');
$labelUnique = an_t('an_chart_metric_unique', 'Unique visitors',  'admin');
?>

<div class="an-wrap">

    <?php /* ── Range selector ───────────────────────────────────────────── */ ?>
    <div class="an-range-bar">
        <?php foreach ($ranges as $key => $label): ?>
        <a href="<?php echo hsc(an_admin_page_url('overview', ['range' => $key])); ?>"
           class="an-range-btn <?php echo $range === $key ? 'active' : ''; ?>">
            <?php echo hsc($label); ?>
        </a>
        <?php endforeach; ?>
        <?php if ($logDates['oldest']): ?>
        <span class="an-data-since">
            <?php echo hsc(an_t('an_data_since', 'Data since', 'admin')); ?>
            <?php echo hsc($logDates['oldest']); ?>
        </span>
        <?php endif; ?>
    </div>

    <?php /* ── KPI cards ───────────────────────────────────────────────── */ ?>
    <div class="an-kpi-row">
        <div class="an-kpi">
            <div class="an-kpi-value"><?php echo number_format($stats['total_views']); ?></div>
            <div class="an-kpi-label"><?php echo hsc(an_t('an_kpi_views', 'Page views', 'admin')); ?></div>
        </div>
        <div class="an-kpi">
            <div class="an-kpi-value"><?php echo number_format($stats['unique_visitors']); ?></div>
            <div class="an-kpi-label"><?php echo hsc(an_t('an_kpi_unique', 'Unique visitors', 'admin')); ?></div>
            <div class="an-kpi-hint"><?php echo hsc(an_t('an_kpi_unique_hint', 'Monthly IP hash', 'admin')); ?></div>
        </div>
        <div class="an-kpi">
            <div class="an-kpi-value"><?php echo number_format($today['views']); ?></div>
            <div class="an-kpi-label"><?php echo hsc(an_t('an_kpi_today', 'Views today', 'admin')); ?></div>
            <div class="an-kpi-hint"><?php echo number_format($today['unique']); ?> <?php echo hsc(an_t('an_kpi_today_unique', 'unique', 'admin')); ?></div>
        </div>
        <div class="an-kpi">
            <div class="an-kpi-value"><?php echo $bestCount > 0 ? number_format($bestCount) : '—'; ?></div>
            <div class="an-kpi-label"><?php echo hsc(an_t('an_kpi_best_day', 'Best day', 'admin')); ?></div>
            <?php if ($bestDay): ?>
            <div class="an-kpi-hint"><?php echo hsc($bestDay); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php /* ── Line chart — views or unique visitors per day ──────────── */ ?>
    <div class="an-panel">
        <div class="an-panel-header">
            <h3 class="an-panel-title" id="an-chart-heading">
                <?php echo hsc(an_t('an_chart_title', 'Views per day', 'admin')); ?>
            </h3>
            <div class="an-metric-toggle" role="group" aria-label="<?php echo hsc(an_t('an_chart_metric_label', 'Metric', 'admin')); ?>">
                <button class="an-metric-btn active" data-metric="views">
                    <?php echo hsc($labelViews); ?>
                </button>
                <button class="an-metric-btn" data-metric="unique">
                    <?php echo hsc($labelUnique); ?>
                </button>
            </div>
        </div>
        <div class="an-chart-wrap">
            <canvas id="an-chart-views" height="90"></canvas>
        </div>
    </div>

    <?php /* ── Device + Medium donuts ─────────────────────────────────── */ ?>
    <div class="an-two-col">

        <div class="an-panel">
            <h3 class="an-panel-title"><?php echo hsc(an_t('an_device_title', 'Devices', 'admin')); ?></h3>
            <div class="an-donut-wrap">
                <canvas id="an-chart-devices" width="180" height="180"></canvas>
                <div class="an-legend">
                    <?php
                    $devColors = ['desktop' => '#6c63ff', 'mobile' => '#22c55e', 'tablet' => '#f59e0b'];
                    $devLabels = [
                        'desktop' => an_t('an_device_desktop', 'Desktop', 'admin'),
                        'mobile'  => an_t('an_device_mobile',  'Mobile',  'admin'),
                        'tablet'  => an_t('an_device_tablet',  'Tablet',  'admin'),
                    ];
                    foreach ($devices as $k => $v):
                        $pct = $devTotal > 0 ? round($v / $devTotal * 100) : 0;
                    ?>
                    <div class="an-legend-item">
                        <span class="an-legend-dot" style="background:<?php echo $devColors[$k] ?? '#999'; ?>"></span>
                        <span class="an-legend-name"><?php echo hsc($devLabels[$k] ?? $k); ?></span>
                        <span class="an-legend-pct"><?php echo $pct; ?>%</span>
                        <span class="an-legend-val"><?php echo number_format($v); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="an-panel">
            <h3 class="an-panel-title"><?php echo hsc(an_t('an_medium_title', 'Traffic sources', 'admin')); ?></h3>
            <div class="an-donut-wrap">
                <canvas id="an-chart-media" width="180" height="180"></canvas>
                <div class="an-legend">
                    <?php
                    $medColors = ['direct' => '#6c63ff', 'search' => '#22c55e', 'social' => '#f59e0b', 'referral' => '#3b82f6', 'ai' => '#d946ef'];
                    $medLabels = [
                        'direct'   => an_t('an_medium_direct',   'Direct',   'admin'),
                        'search'   => an_t('an_medium_search',   'Search',   'admin'),
                        'social'   => an_t('an_medium_social',   'Social',   'admin'),
                        'referral' => an_t('an_medium_referral', 'Referral', 'admin'),
                        'ai'       => an_t('an_medium_ai',       'AI assistants', 'admin'),
                    ];
                    foreach ($media as $k => $v):
                        $pct = $medTotal > 0 ? round($v / $medTotal * 100) : 0;
                    ?>
                    <div class="an-legend-item">
                        <span class="an-legend-dot" style="background:<?php echo $medColors[$k] ?? '#999'; ?>"></span>
                        <span class="an-legend-name"><?php echo hsc($medLabels[$k] ?? $k); ?></span>
                        <span class="an-legend-pct"><?php echo $pct; ?>%</span>
                        <span class="an-legend-val"><?php echo number_format($v); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>

    <?php /* ── Top 5 pages preview ─────────────────────────────────────── */ ?>
    <?php if (!empty($stats['top_pages'])): ?>
    <div class="an-panel">
        <div class="an-panel-header">
            <h3 class="an-panel-title"><?php echo hsc(an_t('an_top_pages_title', 'Top pages', 'admin')); ?></h3>
            <a href="<?php echo hsc(an_admin_page_url('pages', ['range' => $range])); ?>" class="an-panel-more">
                <?php echo hsc(an_t('an_view_all', 'View all →', 'admin')); ?>
            </a>
        </div>
        <div class="an-bar-list">
            <?php
            $topSlice = array_slice($stats['top_pages'], 0, 5, true);
            $maxViews = max(1, reset($topSlice)['views'] ?? 1);
            foreach ($topSlice as $slug => $p):
                $pct = round($p['views'] / $maxViews * 100);
            ?>
            <div class="an-bar-row">
                <div class="an-bar-meta">
                    <span class="an-bar-title"><?php echo hsc($p['title'] ?: $slug); ?></span>
                    <span class="an-type-badge an-type-<?php echo hsc($p['type']); ?>"><?php echo hsc($p['type']); ?></span>
                </div>
                <div class="an-bar-track">
                    <div class="an-bar-fill" style="width:<?php echo $pct; ?>%"></div>
                </div>
                <div class="an-bar-count"><?php echo number_format($p['views']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($stats['total_views'] === 0): ?>
    <div class="an-empty">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
        </svg>
        <p><?php echo hsc(an_t('an_no_data', 'No data recorded yet. Analytics will appear once your site receives visitors.', 'admin')); ?></p>
    </div>
    <?php endif; ?>

</div>

<?php
/* ── Chart JS data (Chart.js bundled in assets/vendor, loaded once by overview-charts.js) ── */
$overviewChartData = [
    'days'        => $chartDays,
    'dataViews'   => $chartViews,
    'dataUnique'  => $chartUnique,
    'devData'     => array_values($devices),
    'devLabels'   => array_values(['desktop' => an_t('an_device_desktop', 'Desktop', 'admin'), 'mobile' => an_t('an_device_mobile', 'Mobile', 'admin'), 'tablet' => an_t('an_device_tablet', 'Tablet', 'admin')]),
    'medData'     => array_values($media),
    'medLabels'   => array_values(['direct' => an_t('an_medium_direct', 'Direct', 'admin'), 'search' => an_t('an_medium_search', 'Search', 'admin'), 'social' => an_t('an_medium_social', 'Social', 'admin'), 'referral' => an_t('an_medium_referral', 'Referral', 'admin'), 'ai' => an_t('an_medium_ai', 'AI assistants', 'admin')]),
    'labelViews'  => $labelViews,
    'labelUnique' => $labelUnique,
    'titleViews'  => an_t('an_chart_title', 'Views per day', 'admin'),
    'titleUnique' => an_t('an_chart_title_unique', 'Unique visitors per day', 'admin'),
];
$overviewChartsV = @filemtime(__DIR__ . '/../../assets/js/overview-charts.js') ?: time();
?>
<script type="application/json" id="an-overview-data"><?php echo json_encode($overviewChartData); ?></script>
<script src="<?php echo hsc(an_site_base_url() . 'plugins/analytics/assets/js/overview-charts.js'); ?>?v=<?php echo $overviewChartsV; ?>"></script>
