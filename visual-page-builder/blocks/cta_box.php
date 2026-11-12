<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
$btns = vpb_btn($b['cta1_label'], $b['cta1_url']) . vpb_btn($b['cta2_label'], $b['cta2_url'], true);
if ($b['title'] === '' && $btns === '') return;
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-ctabox'); ?>>
    <div class="vpb-c-container">
        <div class="vpb-c-ctabox-in">
            <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
            <?php if ($b['text'] !== ''): ?><p class="vpb-c-lead"><?php echo nl2br(hsc($b['text'])); ?></p><?php endif; ?>
            <?php if ($btns !== ''): ?><div class="vpb-c-btns"><?php echo $btns; ?></div><?php endif; ?>
        </div>
    </div>
</section>
