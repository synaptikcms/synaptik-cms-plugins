<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
?>
<div <?php echo vpb_attrs($b, 'vpb-c-sp-' . (in_array($b['size'], ['s', 'm', 'l'], true) ? $b['size'] : 'm')); ?> aria-hidden="true"></div>
