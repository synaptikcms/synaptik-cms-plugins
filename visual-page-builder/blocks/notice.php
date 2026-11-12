<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
if ($b['title'] === '' && $b['body'] === '') return;
$kind = in_array($b['kind'], ['tip', 'warning', 'danger'], true) ? $b['kind'] : 'info';
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-notice-wrap'); ?>>
    <div class="vpb-c-container">
        <div class="vpb-c-notice vpb-c-notice--<?php echo $kind; ?>">
            <?php if ($b['title'] !== ''): ?><strong class="vpb-c-notice-title"><?php echo hsc($b['title']); ?></strong><?php endif; ?>
            <?php if ($b['body'] !== ''): ?><div class="vpb-c-body"><?php echo render_content_html($b['body'], ['content_format' => 'markdown']); ?></div><?php endif; ?>
        </div>
    </div>
</section>
