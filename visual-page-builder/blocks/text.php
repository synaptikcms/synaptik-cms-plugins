<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
$anchor = ($b['anchor'] ?? '') !== '' ? ' id="' . hsc($b['anchor']) . '"' : '';
?>
<section<?php echo $anchor; ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-text'); ?>>
    <div class="vpb-c-container">
        <?php if ($b['eyebrow'] !== ''): ?><p class="vpb-c-eyebrow"><?php echo hsc($b['eyebrow']); ?></p><?php endif; ?>
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <?php if ($b['body'] !== ''): ?><div class="vpb-c-body"><?php echo render_content_html($b['body'], ['content_format' => 'markdown']); ?></div><?php endif; ?>
    </div>
</section>
