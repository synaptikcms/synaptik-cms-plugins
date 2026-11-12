<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
$head = $b['head'];
$rows = [];
foreach ($b['rows'] as $line) {
    $cells = array_map('trim', explode('|', $line));
    if (array_filter($cells, static fn($c) => $c !== '')) $rows[] = $cells;
}
if (!$head && !$rows) return;
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-tablewrap'); ?>>
    <div class="vpb-c-container vpb-c-wide">
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <div class="vpb-c-scroll">
            <table class="vpb-c-table">
                <?php if ($head): ?><thead><tr><?php foreach ($head as $cell): ?><th><?php echo hsc($cell); ?></th><?php endforeach; ?></tr></thead><?php endif; ?>
                <tbody>
                    <?php foreach ($rows as $cells): ?><tr><?php foreach ($cells as $cell): ?><td><?php echo hsc($cell); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
