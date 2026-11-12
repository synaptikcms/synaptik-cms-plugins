<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
$items = array_values(array_filter($b['items'], static fn($it) => $it['src'] !== ''));
if (!$items) return;
?>
<section<?php echo vpb_anchor_attr($b); ?> <?php echo vpb_attrs($b, 'vpb-c vpb-c-logos'); ?>>
    <div class="vpb-c-container vpb-c-wide">
        <?php if ($b['title'] !== ''): ?><h2 class="vpb-c-title"><?php echo hsc($b['title']); ?></h2><?php endif; ?>
        <div class="vpb-c-logorow">
            <?php foreach ($items as $it):
                $img = '<img src="' . hsc(vpb_img_url($it['src'])) . '" alt="' . hsc($it['alt']) . '" loading="lazy" decoding="async">';
                echo $it['url'] !== '' ? '<a href="' . hsc($it['url']) . '">' . $img . '</a>' : $img;
            endforeach; ?>
        </div>
    </div>
</section>
