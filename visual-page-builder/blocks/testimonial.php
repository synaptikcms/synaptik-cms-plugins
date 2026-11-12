<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
if (!$b['items']) return;
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-testimonial'); ?>>
    <div class="vpb-c-container vpb-c-wide">
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <div class="vpb-c-grid" style="--vpb-n:<?php echo min(3, max(1, count($b['items']))); ?>">
            <?php foreach ($b['items'] as $it): if ($it['text'] === '') continue; ?>
            <figure class="vpb-c-card vpb-c-quotecard">
                <blockquote><p><?php echo nl2br(hsc($it['text'])); ?></p></blockquote>
                <figcaption>
                    <?php if ($it['src'] !== ''): ?><img src="<?php echo hsc(vpb_img_url($it['src'])); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                    <span><strong><?php echo hsc($it['author']); ?></strong><?php if ($it['role'] !== ''): ?><br><?php echo hsc($it['role']); ?><?php endif; ?></span>
                </figcaption>
            </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
