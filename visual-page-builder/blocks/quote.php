<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
if ($b['text'] === '') return;
$cite = trim($b['author'] . ($b['author'] !== '' && $b['role'] !== '' ? ', ' : '') . $b['role']);
?>
<section <?php echo vpb_attrs($b, 'vpb-c vpb-c-quote'); ?>>
    <div class="vpb-c-container">
        <blockquote>
            <p><?php echo nl2br(hsc($b['text'])); ?></p>
            <?php if ($cite !== ''): ?><cite><?php echo hsc($cite); ?></cite><?php endif; ?>
        </blockquote>
    </div>
</section>
