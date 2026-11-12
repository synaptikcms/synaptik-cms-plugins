<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
if ($b['code'] === '') return;
?>
<section <?php echo vpb_attrs($b, 'vpb-c vpb-c-shortcode'); ?>>
    <div class="vpb-c-container">
        <?php echo render_content_html($b['code'], $GLOBALS['_vpb_item'] ?? null); ?>
    </div>
</section>
