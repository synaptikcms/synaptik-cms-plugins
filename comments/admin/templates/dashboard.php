<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Comments moderation dashboard.
 * Status filter, text search, pagination (25/page), and bulk actions on
 * top of the per-row approve/reject/delete controls. Each row shows a
 * stripped preview, not the raw content, to keep the table scannable.
 */

$csrf   = cm_generate_csrf();
$msg    = $_GET['msg'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
// $filter and $search are resolved and validated by comments_render_admin_page()
// before this template is included — the tab bar and this list must agree on them.

$perPage = 25;

$allComments = cm_load_all_comments();

$filtered = $filter === 'all'
    ? $allComments
    : array_filter($allComments, fn($c) => ($c['status'] ?? '') === $filter);

if ($search !== '') {
    $needle   = mb_strtolower($search);
    $filtered = array_filter($filtered, function ($c) use ($needle) {
        return mb_stripos($c['author_name'] ?? '', $needle) !== false
            || mb_stripos($c['author_email'] ?? '', $needle) !== false
            || mb_stripos($c['content'] ?? '', $needle) !== false
            || mb_stripos($c['_slug'] ?? '', $needle) !== false;
    });
}

$filtered   = array_values($filtered);
$totalCount = count($filtered);
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$pageItems  = array_slice($filtered, ($page - 1) * $perPage, $perPage);

/**
 * Short, tag-free preview of a comment's content for the table view.
 */
function cm_admin_preview(string $content, int $length = 140): string
{
    $stripped = trim(strip_tags($content));
    return mb_strlen($stripped) > $length ? mb_substr($stripped, 0, $length) . '…' : $stripped;
}

/**
 * Builds a dashboard URL preserving the current filter/search/page state,
 * overriding only the given keys — keeps pagination links from silently
 * resetting the admin's current search or filter.
 *
 * State is passed in rather than read with `global`: this template is
 * included from inside comments_render_admin_page(), so $filter/$search/$page
 * are function locals and never reach the global scope.
 */
function cm_a_url(array $overrides, string $filter, string $search, int $page): string
{
    $params = array_merge(['status' => $filter, 'q' => $search !== '' ? $search : null, 'page' => $page > 1 ? $page : null], $overrides);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return cm_admin_page_url($params);
}
?>
<?php if ($msg === 'approved'): ?>
    <div class="message success"><?php echo hsc(cm_t('cm_msg_approved', 'Comment approved.', 'admin')); ?></div>
<?php elseif ($msg === 'rejected'): ?>
    <div class="message success"><?php echo hsc(cm_t('cm_msg_rejected', 'Comment rejected.', 'admin')); ?></div>
<?php elseif ($msg === 'deleted'): ?>
    <div class="message success"><?php echo hsc(cm_t('cm_msg_deleted', 'Comment deleted.', 'admin')); ?></div>
<?php elseif ($msg === 'bulk_approve'): ?>
    <div class="message success"><?php echo hsc(cm_t('cm_msg_bulk_approve', 'Selected comments approved.', 'admin')); ?></div>
<?php elseif ($msg === 'bulk_reject'): ?>
    <div class="message success"><?php echo hsc(cm_t('cm_msg_bulk_reject', 'Selected comments rejected.', 'admin')); ?></div>
<?php elseif ($msg === 'bulk_delete'): ?>
    <div class="message success"><?php echo hsc(cm_t('cm_msg_bulk_delete', 'Selected comments deleted.', 'admin')); ?></div>
<?php endif; ?>

<div class="cm-a-shortcode-hint">
    <span class="cm-a-shortcode-hint-text"><?php echo hsc(cm_t('cm_shortcode_hint', "Add this shortcode to your theme's template (e.g. content-articles.php) to show it on every item, or paste it directly into an individual article/page/project's content:", 'admin')); ?></span>
    <code class="cm-a-shortcode-copy" id="cm-shortcode-copy" title="<?php echo hsc(cm_t('cm_click_to_copy', 'Click to copy', 'admin')); ?>">[comments]</code>
</div>

<form method="get" action="<?php echo hsc(cm_admin_page_url()); ?>" class="cm-a-search">
    <input type="hidden" name="action" value="plugin_page">
    <input type="hidden" name="slug" value="comments">
    <input type="hidden" name="view" value="dashboard">
    <input type="hidden" name="status" value="<?php echo hsc($filter); ?>">
    <input type="search" name="q" id="cm-a-search-input" value="<?php echo hsc($search); ?>" placeholder="<?php echo hsc(cm_t('cm_search_placeholder', 'Search by author, email, content, or slug…', 'admin')); ?>">
    <button type="submit" class="cm-a-btn cm-a-btn--outline"><?php echo hsc(cm_t('cm_search', 'Search', 'admin')); ?></button>
    <?php if ($search !== ''): ?>
    <a href="<?php echo hsc(cm_admin_page_url(['status' => $filter])); ?>" class="cm-a-btn cm-a-btn--outline"><?php echo hsc(cm_t('cm_clear_search', 'Clear', 'admin')); ?></a>
    <?php endif; ?>
</form>

<div class="cm-a-panel">
    <?php if (empty($pageItems)): ?>
        <p class="cm-a-empty"><?php echo hsc(cm_t('cm_no_comments_admin', 'No comments here.', 'admin')); ?></p>
    <?php else: ?>
        <form method="post" action="<?php echo hsc(cm_admin_actions_url()); ?>" id="cm-a-bulk-form">
            <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
            <input type="hidden" name="action" value="bulk_action">
            <input type="hidden" name="return_status" value="<?php echo hsc($filter); ?>">
            <input type="hidden" name="bulk_type" id="cm-a-bulk-type" value="">

            <div class="cm-a-bulk-bar">
                <span class="cm-a-bulk-count" id="cm-a-bulk-count"><?php echo hsc(cm_t('cm_bulk_none_selected', 'No comments selected', 'admin')); ?></span>
                <button type="button" class="cm-a-btn cm-a-btn--sm cm-a-btn--primary" data-bulk="approve"><?php echo hsc(cm_t('cm_approve', 'Approve', 'admin')); ?></button>
                <button type="button" class="cm-a-btn cm-a-btn--sm cm-a-btn--outline" data-bulk="reject"><?php echo hsc(cm_t('cm_reject', 'Reject', 'admin')); ?></button>
                <button type="button" class="cm-a-btn cm-a-btn--sm cm-a-btn--delete" data-bulk="delete"><?php echo hsc(cm_t('cm_delete', 'Delete', 'admin')); ?></button>
            </div>

            <table class="cm-a-table">
                <thead>
                    <tr>
                        <th class="cm-a-th-check"><input type="checkbox" id="cm-a-select-all"></th>
                        <th><?php echo hsc(cm_t('cm_col_author', 'Author', 'admin')); ?></th>
                        <th><?php echo hsc(cm_t('cm_col_comment', 'Comment', 'admin')); ?></th>
                        <th><?php echo hsc(cm_t('cm_col_on', 'On', 'admin')); ?></th>
                        <th><?php echo hsc(cm_t('cm_col_date', 'Date', 'admin')); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pageItems as $comment): ?>
                    <tr>
                        <td class="cm-a-th-check">
                            <input type="checkbox" class="cm-a-row-check" name="ids[]" value="<?php echo hsc($comment['_id']); ?>">
                        </td>
                        <td>
                            <div class="cm-a-author"><?php echo hsc($comment['author_name'] ?? ''); ?></div>
                            <div class="cm-a-email"><?php echo hsc($comment['author_email'] ?? ''); ?></div>
                        </td>
                        <td class="cm-a-content">
                            <?php echo hsc(cm_admin_preview($comment['content'] ?? '')); ?>
                            <?php if (!empty($comment['parent_id'])): ?>
                            <span class="cm-a-badge cm-a-badge--reply"><?php echo hsc(cm_t('cm_is_reply', 'Reply', 'admin')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="cm-a-badge"><?php echo hsc($comment['_type'] ?? ''); ?></span>
                            <div class="cm-a-slug"><?php echo hsc($comment['_slug'] ?? ''); ?></div>
                        </td>
                        <td class="cm-a-date"><?php echo hsc($comment['created_at'] ?? ''); ?></td>
                        <td class="cm-a-actions">
                            <?php if (($comment['status'] ?? '') !== 'approved'): ?>
                            <button type="button" class="cm-a-btn cm-a-btn--sm cm-a-btn--primary" data-row-action="approve" data-row-id="<?php echo hsc($comment['_id'], ENT_QUOTES); ?>"><?php echo hsc(cm_t('cm_approve', 'Approve', 'admin')); ?></button>
                            <?php endif; ?>
                            <?php if (($comment['status'] ?? '') !== 'rejected'): ?>
                            <button type="button" class="cm-a-btn cm-a-btn--sm cm-a-btn--outline" data-row-action="reject" data-row-id="<?php echo hsc($comment['_id'], ENT_QUOTES); ?>"><?php echo hsc(cm_t('cm_reject', 'Reject', 'admin')); ?></button>
                            <?php endif; ?>
                            <button type="button" class="cm-a-btn cm-a-btn--sm cm-a-btn--delete" data-row-action="delete" data-row-id="<?php echo hsc($comment['_id'], ENT_QUOTES); ?>"><?php echo hsc(cm_t('cm_delete', 'Delete', 'admin')); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <?php if ($totalPages > 1): ?>
        <div class="cm-a-pagination">
            <?php if ($page > 1): ?>
            <a href="<?php echo hsc(cm_a_url(['page' => $page - 1], $filter, $search, $page)); ?>" class="cm-a-btn cm-a-btn--sm cm-a-btn--outline">&larr; <?php echo hsc(cm_t('cm_prev_page', 'Previous', 'admin')); ?></a>
            <?php endif; ?>
            <span class="cm-a-pagination-status"><?php echo hsc(sprintf(cm_t('cm_page_of', 'Page %1$d of %2$d (%3$d comments)', 'admin'), $page, $totalPages, $totalCount)); ?></span>
            <?php if ($page < $totalPages): ?>
            <a href="<?php echo hsc(cm_a_url(['page' => $page + 1], $filter, $search, $page)); ?>" class="cm-a-btn cm-a-btn--sm cm-a-btn--outline"><?php echo hsc(cm_t('cm_next_page', 'Next', 'admin')); ?> &rarr;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$cmDashboardData = [
    'copied'              => cm_t('cm_copied', 'Copied!', 'admin'),
    'confirmDelete'       => cm_t('cm_confirm_delete', 'Permanently delete this comment?', 'admin'),
    'confirmBulkDelete'   => cm_t('cm_confirm_bulk_delete', 'Permanently delete the {count} selected comments?', 'admin'),
    'confirmTitle'        => cm_t('cm_confirm_title', 'Confirm deletion', 'admin'),
    'confirmDeleteButton' => cm_t('cm_delete', 'Delete', 'admin'),
    'cancel'              => cm_t('cm_cancel', 'Cancel', 'admin'),
    'noneSelected'        => cm_t('cm_bulk_none_selected', 'No comments selected', 'admin'),
    'nSelected'           => cm_t('cm_bulk_n_selected', '{count} selected', 'admin'),
];
$cmDashboardJsV = @filemtime(__DIR__ . '/../../assets/js/dashboard.js') ?: time();
?>
<script type="application/json" id="cm-dashboard-data"><?php echo json_encode($cmDashboardData); ?></script>
<script src="<?php echo hsc(cm_plugin_base_url() . 'assets/js/dashboard.js'); ?>?v=<?php echo $cmDashboardJsV; ?>"></script>
