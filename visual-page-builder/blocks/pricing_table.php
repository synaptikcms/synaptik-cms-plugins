<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
if (!$b['items']) return;
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-pricing'); ?>>
    <div class="vpb-c-container vpb-c-wide">
        <?php if ($b['eyebrow'] !== ''): ?><p class="vpb-c-eyebrow"><?php echo hsc($b['eyebrow']); ?></p><?php endif; ?>
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <div class="vpb-c-grid" style="--vpb-n:<?php echo min(4, max(1, count($b['items']))); ?>">
            <?php foreach ($b['items'] as $it): ?>
            <div class="vpb-c-card vpb-c-plan<?php echo !empty($it['highlight']) ? ' vpb-c-plan--hl' : ''; ?>">
                <h3><?php echo hsc($it['name']); ?></h3>
                <p class="vpb-c-price"><strong><?php echo hsc($it['price']); ?></strong><?php if ($it['period'] !== ''): ?><span> <?php echo hsc($it['period']); ?></span><?php endif; ?></p>
                <?php if ($it['bullets']): ?><ul><?php foreach ($it['bullets'] as $li): ?><li><?php echo hsc($li); ?></li><?php endforeach; ?></ul><?php endif; ?>
                <?php echo vpb_btn($it['cta1_label'], $it['cta1_url'], empty($it['highlight'])); ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
