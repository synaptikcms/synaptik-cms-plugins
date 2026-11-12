<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
$buttons = [];
if ($b['label'] !== '' && $b['url'] !== '') $buttons[] = [$b['label'], $b['url'], ''];
if ($b['label2'] !== '' && $b['url2'] !== '') $buttons[] = [$b['label2'], $b['url2'], ' vpb-c-btn--ghost'];
if (!$buttons && !vpb_is_preview()) return;
?>
<section <?php echo vpb_attrs($b, 'vpb-c vpb-c-buttons'); ?>>
    <div class="vpb-c-container">
        <div class="vpb-c-btns">
            <?php foreach ($buttons as $btn): ?><a class="vpb-c-btn<?php echo $btn[2]; ?>" href="<?php echo hsc($btn[1]); ?>"><?php echo hsc($btn[0]); ?></a><?php endforeach; ?>
        </div>
    </div>
</section>
