<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
if (!$b['items']) return;
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-stats'); ?>>
    <div class="vpb-c-container vpb-c-wide">
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <div class="vpb-c-statrow">
            <?php foreach ($b['items'] as $it): ?>
            <div class="vpb-c-stat"><strong><?php echo hsc($it['value']); ?></strong><span><?php echo hsc($it['label']); ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
