<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Analytics — Top Pages template
 */

$range = $_GET['range'] ?? '30d';
if (!in_array($range, ['7d', '30d', '90d', '365d'], true)) $range = '30d';

$stats = an_build_aggregate($range);
$pages = $stats['top_pages'];

$ranges = [
    '7d'   => an_t('an_range_7d',   'Last 7 days',   'admin'),
    '30d'  => an_t('an_range_30d',  'Last 30 days',  'admin'),
    '90d'  => an_t('an_range_90d',  'Last 90 days',  'admin'),
    '365d' => an_t('an_range_365d', 'Last 365 days', 'admin'),
];
?>
<div class="an-wrap">

    <div class="an-range-bar">
        <?php foreach ($ranges as $key => $label): ?>
        <a href="<?php echo hsc(an_admin_page_url('pages', ['range' => $key])); ?>"
           class="an-range-btn <?php echo $range === $key ? 'active' : ''; ?>">
            <?php echo hsc($label); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="an-panel">
        <h3 class="an-panel-title"><?php echo hsc(an_t('an_top_pages_title', 'Top pages', 'admin')); ?></h3>

        <?php if (empty($pages)): ?>
        <p class="an-empty-inline"><?php echo hsc(an_t('an_no_data_short', 'No data for this period.', 'admin')); ?></p>
        <?php else: ?>

        <table class="an-pages-table">
            <tbody>
            <?php
            $maxViews = max(1, reset($pages)['views'] ?? 1);
            foreach ($pages as $slug => $p):
                $pct = round($p['views'] / $maxViews * 100);
            ?>
            <tr>
                <td class="an-pages-meta">
                    <div class="an-pages-title-row">
                        <span class="an-pages-title"><?php echo hsc($p['title'] ?: $slug); ?></span>
                        <span class="an-type-badge an-type-<?php echo hsc($p['type']); ?>"><?php echo hsc($p['type']); ?></span>
                    </div>
                    <span class="an-pages-slug"><?php echo hsc($slug); ?></span>
                </td>
                <td class="an-pages-bar-cell">
                    <div class="an-country-bar-track">
                        <div class="an-country-bar-fill" style="width:<?php echo $pct; ?>%"></div>
                    </div>
                </td>
                <td class="an-pages-count"><?php echo number_format($p['views']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>
    </div>

</div>
