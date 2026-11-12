<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
if (!$b['items']) return;
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-process'); ?>>
    <div class="vpb-c-container">
        <?php if ($b['eyebrow'] !== ''): ?><p class="vpb-c-eyebrow"><?php echo hsc($b['eyebrow']); ?></p><?php endif; ?>
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <ol class="vpb-c-steps">
            <?php foreach ($b['items'] as $it): ?>
            <li>
                <?php if ($it['title'] !== ''): ?><h3><?php echo hsc($it['title']); ?></h3><?php endif; ?>
                <?php if ($it['body'] !== ''): ?><p><?php echo nl2br(hsc($it['body'])); ?></p><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>
