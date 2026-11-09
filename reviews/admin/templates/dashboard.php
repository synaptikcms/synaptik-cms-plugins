<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
/**
 * Admin — Reviews dashboard.
 * Aggregate rating, a drag-to-reorder review list with inline edit/delete,
 * an add-review form, shortcode usage reference, and display settings.
 */

$reviews       = rv_load_reviews_sorted();
[$average, $publishedCount] = rv_get_average_rating();
$settings      = rv_load_settings();
$csrf          = rv_generate_csrf();
$msg           = $_GET['msg'] ?? '';
$editId        = $_GET['edit'] ?? '';
$editReview    = $editId !== '' ? rv_get_review($editId) : null;
?>
<?php if ($msg === 'created'): ?>
    <div class="message success"><?php echo hsc(rv_t('rv_review_created', 'Review added.', 'admin')); ?></div>
<?php elseif ($msg === 'updated'): ?>
    <div class="message success"><?php echo hsc(rv_t('rv_review_updated', 'Review updated.', 'admin')); ?></div>
<?php elseif ($msg === 'deleted'): ?>
    <div class="message success"><?php echo hsc(rv_t('rv_review_deleted', 'Review removed.', 'admin')); ?></div>
<?php elseif ($msg === 'saved'): ?>
    <div class="message success"><?php echo hsc(rv_t('rv_settings_saved', 'Settings saved.', 'admin')); ?></div>
<?php elseif ($msg === 'invalid'): ?>
    <div class="message error"><?php echo hsc(rv_t('rv_review_invalid', 'Please fill in the author, a rating, and a review text.', 'admin')); ?></div>
<?php endif; ?>

<div class="rv-a-stats">
    <div class="rv-a-stat-card">
        <span class="rv-a-stat-value"><?php echo hsc((string)$average); ?> / 5</span>
        <span class="rv-a-stat-label"><?php echo hsc(rv_t('rv_stat_average', 'Average rating', 'admin')); ?></span>
    </div>
    <div class="rv-a-stat-card">
        <span class="rv-a-stat-value"><?php echo (int)$publishedCount; ?></span>
        <span class="rv-a-stat-label"><?php echo hsc(rv_t('rv_stat_published', 'Published reviews', 'admin')); ?></span>
    </div>
    <div class="rv-a-stat-card">
        <span class="rv-a-stat-value"><?php echo count($reviews); ?></span>
        <span class="rv-a-stat-label"><?php echo hsc(rv_t('rv_stat_total', 'Total reviews', 'admin')); ?></span>
    </div>
</div>

<div class="rv-a-panel">
    <h2><?php echo hsc($editReview !== null ? rv_t('rv_edit_title', 'Edit review', 'admin') : rv_t('rv_add_title', 'Add a review', 'admin')); ?></h2>

    <form method="post" action="<?php echo hsc(rv_admin_actions_url()); ?>" enctype="multipart/form-data" class="rv-a-form">
        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
        <input type="hidden" name="action" value="<?php echo $editReview !== null ? 'update_review' : 'create_review'; ?>">
        <?php if ($editReview !== null): ?>
        <input type="hidden" name="id" value="<?php echo hsc($editId); ?>">
        <?php endif; ?>

        <div class="rv-a-form-row">
            <div class="rv-a-field">
                <label for="rv-author"><?php echo hsc(rv_t('rv_field_author', 'Customer name', 'admin')); ?></label>
                <input type="text" id="rv-author" name="author_name" maxlength="120" required value="<?php echo hsc($editReview['author_name'] ?? ''); ?>">
            </div>

            <div class="rv-a-field rv-a-field--narrow">
                <label for="rv-rating"><?php echo hsc(rv_t('rv_field_rating', 'Rating', 'admin')); ?></label>
                <select id="rv-rating" name="rating" required>
                    <?php $currentRating = (int)($editReview['rating'] ?? 5); ?>
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                    <option value="<?php echo $i; ?>" <?php echo $currentRating === $i ? 'selected' : ''; ?>><?php echo $i; ?> ★</option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="rv-a-field rv-a-field--narrow">
                <label for="rv-date"><?php echo hsc(rv_t('rv_field_date', 'Date', 'admin')); ?></label>
                <input type="date" id="rv-date" name="date" value="<?php echo hsc($editReview['date'] ?? date('Y-m-d')); ?>">
            </div>
        </div>

        <div class="rv-a-field">
            <label for="rv-text"><?php echo hsc(rv_t('rv_field_text', 'Review text', 'admin')); ?></label>
            <textarea id="rv-text" name="text" rows="4" maxlength="2000" required><?php echo hsc($editReview['text'] ?? ''); ?></textarea>
        </div>

        <div class="rv-a-form-row">
            <div class="rv-a-field">
                <label for="rv-avatar"><?php echo hsc(rv_t('rv_field_avatar', 'Avatar (optional)', 'admin')); ?></label>
                <input type="file" id="rv-avatar" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif">
                <?php if (!empty($editReview['avatar'])): ?>
                <p class="rv-a-hint"><?php echo hsc(rv_t('rv_avatar_current', 'Leave empty to keep the current avatar.', 'admin')); ?></p>
                <?php endif; ?>
            </div>

            <div class="rv-a-field rv-a-field--narrow">
                <label for="rv-status"><?php echo hsc(rv_t('rv_field_status', 'Status', 'admin')); ?></label>
                <select id="rv-status" name="status">
                    <?php $currentStatus = $editReview['status'] ?? 'published'; ?>
                    <option value="published" <?php echo $currentStatus === 'published' ? 'selected' : ''; ?>><?php echo hsc(rv_t('rv_status_published', 'Published', 'admin')); ?></option>
                    <option value="draft" <?php echo $currentStatus === 'draft' ? 'selected' : ''; ?>><?php echo hsc(rv_t('rv_status_draft', 'Draft', 'admin')); ?></option>
                </select>
            </div>
        </div>

        <div class="rv-a-form-actions">
            <button type="submit" class="rv-a-btn rv-a-btn--primary"><?php echo hsc($editReview !== null ? rv_t('rv_save_changes', 'Save changes', 'admin') : rv_t('rv_add_review', 'Add review', 'admin')); ?></button>
            <?php if ($editReview !== null): ?>
            <a href="<?php echo hsc(rv_admin_page_url()); ?>" class="rv-a-btn"><?php echo hsc(rv_t('rv_cancel', 'Cancel', 'admin')); ?></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="rv-a-panel">
    <h2><?php echo hsc(rv_t('rv_list_title', 'All reviews', 'admin')); ?> (<?php echo count($reviews); ?>)</h2>
    <p class="rv-a-panel-hint"><?php echo hsc(rv_t('rv_list_hint', 'Drag rows to reorder — this order is used on the front-end display.', 'admin')); ?></p>

    <?php if (empty($reviews)): ?>
        <p class="rv-a-empty"><?php echo hsc(rv_t('rv_no_reviews', 'No reviews yet. Add your first one above.', 'admin')); ?></p>
    <?php else: ?>
        <ul class="rv-a-list" data-rv-a-list data-endpoint="<?php echo hsc(rv_admin_actions_url()); ?>" data-csrf="<?php echo hsc($csrf); ?>">
            <?php foreach ($reviews as $id => $review): ?>
            <li class="rv-a-row" draggable="true" data-id="<?php echo hsc($id); ?>">
                <span class="rv-a-drag-handle" aria-hidden="true">&#9776;</span>
                <div class="rv-a-row-body">
                    <div class="rv-a-row-header">
                        <strong><?php echo hsc($review['author_name'] ?? ''); ?></strong>
                        <span class="rv-a-row-stars"><?php echo str_repeat('★', max(1, min(5, (int)($review['rating'] ?? 5)))); ?></span>
                        <span class="rv-a-badge rv-a-badge--<?php echo hsc($review['status'] ?? 'published'); ?>">
                            <?php echo hsc(($review['status'] ?? 'published') === 'draft' ? rv_t('rv_status_draft', 'Draft', 'admin') : rv_t('rv_status_published', 'Published', 'admin')); ?>
                        </span>
                        <span class="rv-a-row-date"><?php echo hsc(format_date($review['date'] ?? '')); ?></span>
                    </div>
                    <p class="rv-a-row-text"><?php echo hsc(mb_strimwidth($review['text'] ?? '', 0, 140, '…')); ?></p>
                </div>
                <div class="rv-a-row-actions">
                    <a href="<?php echo hsc(rv_admin_page_url(['edit' => $id])); ?>" class="rv-a-btn rv-a-btn--sm"><?php echo hsc(rv_t('rv_edit', 'Edit', 'admin')); ?></a>
                    <form method="post" action="<?php echo hsc(rv_admin_actions_url()); ?>" data-confirm="<?php echo hsc(rv_t('rv_confirm_delete', 'Remove this review?', 'admin'), ENT_QUOTES); ?>">
                        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                        <input type="hidden" name="action" value="delete_review">
                        <input type="hidden" name="id" value="<?php echo hsc($id); ?>">
                        <button type="submit" class="rv-a-btn rv-a-btn--delete rv-a-btn--sm"><?php echo hsc(rv_t('rv_remove', 'Remove', 'admin')); ?></button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="rv-a-panel">
    <h2><?php echo hsc(rv_t('rv_usage_title', 'Displaying reviews', 'admin')); ?></h2>
    <p class="rv-a-panel-hint"><?php echo hsc(rv_t('rv_usage_hint', 'Paste one of these shortcodes into any article, page, or project content.', 'admin')); ?></p>
    <div class="rv-a-code-row">
        <code>[reviews_carousel]</code>
        <code>[reviews_grid]</code>
        <code>[reviews_carousel limit="6" min_rating="4"]</code>
    </div>
</div>

<div class="rv-a-panel">
    <h2><?php echo hsc(rv_t('rv_settings_title', 'Display settings', 'admin')); ?></h2>

    <form method="post" action="<?php echo hsc(rv_admin_actions_url()); ?>" class="rv-a-form">
        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
        <input type="hidden" name="action" value="save_settings">

        <div class="rv-a-form-row">
            <div class="rv-a-field rv-a-field--narrow">
                <label for="rv-display-style"><?php echo hsc(rv_t('rv_settings_style', 'Default layout', 'admin')); ?></label>
                <select id="rv-display-style" name="display_style">
                    <option value="carousel" <?php echo $settings['display_style'] === 'carousel' ? 'selected' : ''; ?>><?php echo hsc(rv_t('rv_settings_style_carousel', 'Carousel', 'admin')); ?></option>
                    <option value="grid" <?php echo $settings['display_style'] === 'grid' ? 'selected' : ''; ?>><?php echo hsc(rv_t('rv_settings_style_grid', 'Grid', 'admin')); ?></option>
                </select>
            </div>

            <div class="rv-a-field rv-a-field--narrow">
                <label for="rv-default-limit"><?php echo hsc(rv_t('rv_settings_limit', 'Default number of reviews shown', 'admin')); ?></label>
                <input type="number" id="rv-default-limit" name="default_limit" min="1" max="50" value="<?php echo (int)$settings['default_limit']; ?>">
            </div>
        </div>

        <div class="rv-a-form-actions">
            <button type="submit" class="rv-a-btn rv-a-btn--primary"><?php echo hsc(rv_t('rv_settings_save', 'Save settings', 'admin')); ?></button>
        </div>
    </form>
</div>
