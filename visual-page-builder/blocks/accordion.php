<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
if (!$b['items']) return;
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-accordion'); ?>>
    <div class="vpb-c-container">
        <?php if ($b['eyebrow'] !== ''): ?><p class="vpb-c-eyebrow"><?php echo hsc($b['eyebrow']); ?></p><?php endif; ?>
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <?php foreach ($b['items'] as $it): if ($it['q'] === '') continue; ?>
        <details class="vpb-c-details">
            <summary><?php echo hsc($it['q']); ?></summary>
            <div class="vpb-c-body"><?php echo render_content_html($it['a'], ['content_format' => 'markdown']); ?></div>
        </details>
        <?php endforeach; ?>
    </div>
</section>
