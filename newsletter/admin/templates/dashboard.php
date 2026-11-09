<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Newsletter overview.
 *
 * Subscriber counts and the two ways to send: the free-form composer (a
 * separate tab) and the one-click article digest, whose batch loop is
 * driven from here by newsletter-admin.js. Subscribers, send history and
 * settings each live on their own tab — see admin-page.php for the view list.
 */

$counts   = nl_count_by_status();
$settings = nl_load_settings();
$csrf     = nl_generate_csrf();

$lastDigestLabel = $settings['last_digest_sent_at']
    ? nl_format_datetime($settings['last_digest_sent_at'])
    : nl_t('nl_never', 'Never', 'admin');
?>
<div class="nl-stats">
    <div class="nl-stat-card">
        <span class="nl-stat-value"><?php echo (int)$counts['confirmed']; ?></span>
        <span class="nl-stat-label"><?php echo hsc(nl_t('nl_stat_confirmed', 'Confirmed subscribers', 'admin')); ?></span>
    </div>
    <div class="nl-stat-card">
        <span class="nl-stat-value"><?php echo (int)$counts['pending']; ?></span>
        <span class="nl-stat-label"><?php echo hsc(nl_t('nl_stat_pending', 'Awaiting confirmation', 'admin')); ?></span>
    </div>
    <div class="nl-stat-card">
        <span class="nl-stat-value nl-stat-value--small"><?php echo hsc($lastDigestLabel); ?></span>
        <span class="nl-stat-label"><?php echo hsc(nl_t('nl_stat_last_digest', 'Last digest sent', 'admin')); ?></span>
    </div>
</div>

<p class="nl-panel-hint"><?php echo hsc(nl_t('nl_overview_intro', 'Two ways to email your confirmed subscribers: write a one-off newsletter yourself, or send an automatic digest of your latest articles.', 'admin')); ?></p>

<div class="nl-panel">
    <h2><?php echo hsc(nl_t('nl_compose_title', 'Write a newsletter', 'admin')); ?></h2>
    <p class="nl-panel-hint"><?php echo hsc(nl_t('nl_compose_hint', 'Write free-form content with the same rich-text editor used for articles.', 'admin')); ?></p>
    <a href="<?php echo hsc(nl_admin_page_url(['view' => 'compose'])); ?>" class="nl-btn nl-btn--primary"><?php echo hsc(nl_t('nl_compose_button', 'Open composer', 'admin')); ?></a>
</div>

<div class="nl-panel">
    <h2><?php echo hsc(nl_t('nl_digest_title', 'Send article digest', 'admin')); ?></h2>
    <p class="nl-panel-hint"><?php echo hsc(nl_t('nl_digest_hint', 'Sends every article published since the last digest to all confirmed subscribers, in small batches.', 'admin')); ?></p>

    <button type="button" id="nl-send-digest" class="nl-btn nl-btn--outline" data-batch-action="send_digest_batch" data-endpoint="<?php echo hsc(nl_admin_actions_url()); ?>" data-csrf="<?php echo hsc($csrf); ?>">
        <?php echo hsc(nl_t('nl_digest_send_button', 'Send digest now', 'admin')); ?>
    </button>

    <div id="nl-digest-progress" class="nl-digest-progress" hidden>
        <div class="nl-progress-bar"><div class="nl-progress-fill"></div></div>
        <p class="nl-progress-label"></p>
    </div>
    <div id="nl-digest-result" class="nl-digest-result" hidden></div>
</div>

<script type="application/json" data-window-var="NL_ADMIN_LANG"><?php echo json_encode([
    'sending'          => nl_t('nl_digest_sending', 'Sending…', 'admin'),
    'sent_progress'    => nl_t('nl_digest_sent_progress', 'Sent {sent} of {total}', 'admin'),
    'done'             => nl_t('nl_digest_done', 'Sent to {total} subscriber(s).', 'admin'),
    'no_new_articles'  => nl_t('nl_digest_no_new_articles', 'No new articles since the last digest.', 'admin'),
    'no_subscribers'   => nl_t('nl_no_confirmed_subscribers', 'No confirmed subscribers to send to.', 'admin'),
    'empty_composer'   => nl_t('nl_empty_composer', 'Write a subject and some content first.', 'admin'),
    'error'            => nl_t('nl_digest_error', 'Something went wrong while sending. Please try again.', 'admin'),
], JSON_UNESCAPED_UNICODE); ?></script>
<script src="<?php echo hsc(nl_core_admin_asset_url('assets/js/admin-boot.js')); ?>"></script>
