<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
$anchor = ($b['anchor'] ?? '') !== '' ? ' id="' . hsc($b['anchor']) . '"' : '';
$cols   = $b['columns'] === '3' ? 3 : 2;
?>
<section<?php echo $anchor; ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-columns'); ?>>
    <div class="vpb-c-container vpb-c-wide">
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <div class="vpb-c-cols" style="--vpb-n:<?php echo $cols; ?>">
            <?php foreach ($b['items'] as $col): ?>
            <div class="vpb-c-col">
                <?php if ($col['title'] !== ''): ?><h3><?php echo hsc($col['title']); ?></h3><?php endif; ?>
                <?php if ($col['body'] !== ''): ?><div class="vpb-c-body"><?php echo render_content_html($col['body'], ['content_format' => 'markdown']); ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
