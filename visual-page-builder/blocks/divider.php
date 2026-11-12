<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
?>
<section <?php echo vpb_attrs($b, 'vpb-c vpb-c-divider'); ?>>
    <div class="vpb-c-container"><hr></div>
</section>
