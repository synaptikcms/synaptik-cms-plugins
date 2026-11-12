<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
if (!$b['items']) return;
$cols = in_array($b['columns'], ['2', '4'], true) ? (int)$b['columns'] : 3;
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-team'); ?>>
    <div class="vpb-c-container vpb-c-wide">
        <?php if ($b['eyebrow'] !== ''): ?><p class="vpb-c-eyebrow"><?php echo hsc($b['eyebrow']); ?></p><?php endif; ?>
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <div class="vpb-c-grid" style="--vpb-n:<?php echo $cols; ?>">
            <?php foreach ($b['items'] as $it): ?>
            <div class="vpb-c-person">
                <?php if ($it['src'] !== ''): ?><img src="<?php echo hsc(vpb_img_url($it['src'])); ?>" alt="<?php echo hsc($it['name']); ?>" loading="lazy" decoding="async"><?php endif; ?>
                <h3><?php echo hsc($it['name']); ?></h3>
                <?php if ($it['role'] !== ''): ?><p class="vpb-c-role"><?php echo hsc($it['role']); ?></p><?php endif; ?>
                <?php if ($it['body'] !== ''): ?><p><?php echo nl2br(hsc($it['body'])); ?></p><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
