<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Analytics — Referrers template
 */

$range = $_GET['range'] ?? '30d';
if (!in_array($range, ['7d', '30d', '90d', '365d'], true)) $range = '30d';

$stats     = an_build_aggregate($range);
$referrers = $stats['top_referrers'];
$refUrls   = $stats['ref_urls'] ?? [];
$media     = $stats['ref_media'];
$medTotal  = max(1, array_sum($media));

$ranges = [
    '7d'   => an_t('an_range_7d',   'Last 7 days',   'admin'),
    '30d'  => an_t('an_range_30d',  'Last 30 days',  'admin'),
    '90d'  => an_t('an_range_90d',  'Last 90 days',  'admin'),
    '365d' => an_t('an_range_365d', 'Last 365 days', 'admin'),
];

$medColors = ['direct' => '#6c63ff', 'search' => '#22c55e', 'social' => '#f59e0b', 'referral' => '#3b82f6', 'ai' => '#d946ef'];
$medLabels = [
    'direct'   => an_t('an_medium_direct',   'Direct',   'admin'),
    'search'   => an_t('an_medium_search',   'Search',   'admin'),
    'social'   => an_t('an_medium_social',   'Social',   'admin'),
    'referral' => an_t('an_medium_referral', 'Referral', 'admin'),
    'ai'       => an_t('an_medium_ai',       'AI assistants', 'admin'),
];
?>
<div class="an-wrap">

    <div class="an-range-bar">
        <?php foreach ($ranges as $key => $label): ?>
        <a href="<?php echo hsc(an_admin_page_url('referrers', ['range' => $key])); ?>"
           class="an-range-btn <?php echo $range === $key ? 'active' : ''; ?>">
            <?php echo hsc($label); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php /* ── Source medium breakdown ────────────────────────────────── */ ?>
    <div class="an-panel">
        <h3 class="an-panel-title"><?php echo hsc(an_t('an_medium_title', 'Traffic sources', 'admin')); ?></h3>
        <div class="an-medium-cards">
            <?php foreach ($media as $k => $v):
                $pct = round($v / $medTotal * 100);
            ?>
            <div class="an-medium-card">
                <div class="an-medium-dot" style="background:<?php echo $medColors[$k] ?? '#999'; ?>"></div>
                <div class="an-medium-name"><?php echo hsc($medLabels[$k] ?? $k); ?></div>
                <div class="an-medium-value"><?php echo number_format($v); ?></div>
                <div class="an-medium-pct"><?php echo $pct; ?>%</div>
                <div class="an-medium-bar-wrap">
                    <div class="an-medium-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $medColors[$k] ?? '#999'; ?>"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php /* ── Top referrer domains ──────────────────────────────────── */ ?>
    <div class="an-panel">
        <h3 class="an-panel-title"><?php echo hsc(an_t('an_referrer_domains', 'Referrer domains', 'admin')); ?></h3>

        <?php if (empty($referrers)): ?>
        <p class="an-empty-inline"><?php echo hsc(an_t('an_no_referrers', 'No external referrers recorded for this period.', 'admin')); ?></p>
        <?php else: ?>

        <table class="an-table">
            <thead>
                <tr>
                    <th><?php echo hsc(an_t('an_col_domain',  'Domain',  'admin')); ?></th>
                    <th><?php echo hsc(an_t('an_col_medium',  'Medium',  'admin')); ?></th>
                    <th class="an-col-num"><?php echo hsc(an_t('an_col_visits', 'Visits', 'admin')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($referrers as $host => $count):
                $ref     = an_parse_referrer('https://' . $host);
                $medium  = $ref['medium'];
                $medName = $medLabels[$medium] ?? $medium;
                $medCol  = $medColors[$medium]  ?? '#999';
                $urls    = $refUrls[$host] ?? [];
                $rowId   = 'an-ref-' . md5($host);
            ?>
            <tr class="an-ref-host-row<?php echo !empty($urls) ? ' an-ref-expandable' : ''; ?>"<?php echo !empty($urls) ? ' data-ref-toggle="' . hsc($rowId, ENT_QUOTES) . '"' : ''; ?> role="<?php echo !empty($urls) ? 'button' : 'row'; ?>">
                <td>
                    <span class="an-ref-expand-icon" aria-hidden="true"><?php echo !empty($urls) ? '&#9654;' : ''; ?></span>
                    <a href="https://<?php echo hsc($host); ?>" target="_blank" rel="noopener noreferrer" class="an-domain-link">
                        <?php echo hsc($host); ?>
                    </a>
                </td>
                <td><span class="an-medium-pill" style="border-color:<?php echo $medCol; ?>;color:<?php echo $medCol; ?>"><?php echo hsc($medName); ?></span></td>
                <td class="an-col-num"><?php echo number_format($count); ?></td>
            </tr>
            <?php if (!empty($urls)): ?>
            <tr id="<?php echo $rowId; ?>" class="an-ref-urls-row" style="display:none">
                <td colspan="3" class="an-ref-urls-cell">
                    <ul class="an-ref-url-list">
                        <?php foreach ($urls as $url => $urlCount): ?>
                        <li>
                            <a href="<?php echo hsc($url); ?>" target="_blank" rel="noopener noreferrer" class="an-ref-url-link"><?php echo hsc($url); ?></a>
                            <span class="an-ref-url-count"><?php echo number_format($urlCount); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>
    </div>

</div>

<style>
.an-ref-expandable { cursor: pointer; }
.an-ref-expandable:hover { background: var(--surface-2); }
.an-ref-expand-icon {
    display: inline-block;
    font-size: .6rem;
    margin-right: .4rem;
    color: var(--text-muted);
    transition: transform .15s;
    min-width: .8rem;
}
.an-ref-host-row.open .an-ref-expand-icon { transform: rotate(90deg); }
.an-ref-urls-row td { padding: 0; }
.an-ref-urls-cell { padding: .25rem .75rem .75rem 2.2rem !important; background: var(--surface-2); }
.an-ref-url-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .3rem; }
.an-ref-url-list li { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
.an-ref-url-link { font-size: .8rem; color: var(--primary); text-overflow: ellipsis; overflow: hidden; white-space: nowrap; max-width: 480px; }
.an-ref-url-link:hover { text-decoration: underline; }
.an-ref-url-count { font-size: .75rem; color: var(--text-muted); white-space: nowrap; }
</style>

<?php
$referrersJsV = @filemtime(__DIR__ . '/../../assets/js/referrers.js') ?: time();
?>
<script src="<?php echo hsc(an_site_base_url() . 'plugins/analytics/assets/js/referrers.js'); ?>?v=<?php echo $referrersJsV; ?>"></script>
