<?php
if (!defined('INCLUDED')) {
    http_response_code(403);
    exit;
}
$allTerms = df_load_terms_sorted();
$csrf     = df_generate_csrf();
$msg      = $_GET['msg'] ?? '';

$editId     = $_GET['edit'] ?? '';
$editRecord = $editId !== '' ? df_get_term($editId) : null;

$settings             = df_load_settings();
$globalWrapAllDefault = !empty($settings['wrap_all_by_default']);
$globalMaxOccDefault  = max(1, (int)($settings['max_occurrences_default'] ?? 1));

$wrapAllChecked = $editRecord !== null
    ? (array_key_exists('wrap_all', $editRecord) ? !empty($editRecord['wrap_all']) : $globalWrapAllDefault)
    : $globalWrapAllDefault;
$maxOccValue = $editRecord !== null
    ? (int)($editRecord['max_occurrences'] ?? $globalMaxOccDefault)
    : $globalMaxOccDefault;
?>
<?php if ($msg === 'created'): ?>
    <div class="message success"><?php echo hsc(df_t('df_created', 'Term created.', 'admin')); ?></div>
<?php elseif ($msg === 'updated'): ?>
    <div class="message success"><?php echo hsc(df_t('df_updated', 'Term updated.', 'admin')); ?></div>
<?php elseif ($msg === 'deleted'): ?>
    <div class="message success"><?php echo hsc(df_t('df_deleted', 'Term removed.', 'admin')); ?></div>
<?php elseif ($msg === 'duplicate'): ?>
    <div class="message error"><?php echo hsc(df_t('df_error_duplicate', 'A definition for this term already exists.', 'admin')); ?></div>
<?php elseif ($msg === 'invalid'): ?>
    <div class="message error"><?php echo hsc(df_t('df_error_invalid', 'Please fill in both the term and its definition.', 'admin')); ?></div>
<?php endif; ?>

<div class="df-panel">
    <h2><?php echo hsc($editRecord !== null ? df_t('df_edit_title', 'Edit definition', 'admin') : df_t('df_add_title', 'New definition', 'admin')); ?></h2>
    <p class="df-panel-hint"><?php echo hsc(df_t('df_add_hint', 'The term is underlined wherever it appears in your content. Hovering (or tapping, on mobile) reveals the definition in a small popup.', 'admin')); ?></p>

    <form method="post" action="<?php echo hsc(df_admin_actions_url()); ?>" class="df-form">
        <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
        <input type="hidden" name="action" value="<?php echo $editRecord !== null ? 'update_term' : 'create_term'; ?>">
        <?php if ($editRecord !== null): ?>
        <input type="hidden" name="id" value="<?php echo hsc($editId); ?>">
        <?php endif; ?>

        <div class="df-field">
            <label for="df-term"><?php echo hsc(df_t('df_field_term', 'Term', 'admin')); ?></label>
            <input type="text" id="df-term" name="term" required maxlength="80"
                   value="<?php echo hsc($editRecord['term'] ?? ''); ?>"
                   placeholder="<?php echo hsc(df_t('df_field_term_placeholder', 'e.g. adaptogène', 'admin')); ?>">
        </div>

        <div class="df-field">
            <label for="df-definition"><?php echo hsc(df_t('df_field_definition', 'Definition', 'admin')); ?></label>
            <textarea id="df-definition" name="definition" required maxlength="500" rows="3"
                      placeholder="<?php echo hsc(df_t('df_field_definition_placeholder', 'Short explanation shown in the popup', 'admin')); ?>"><?php echo hsc($editRecord['definition'] ?? ''); ?></textarea>
        </div>

        <div class="df-field">
            <label for="df-variants"><?php echo hsc(df_t('df_field_variants', 'Variants', 'admin')); ?></label>
            <input type="text" id="df-variants" name="variants"
                   value="<?php echo hsc(implode(', ', $editRecord['variants'] ?? [])); ?>"
                   placeholder="<?php echo hsc(df_t('df_field_variants_placeholder', 'e.g. biomarqueurs, marqueurs biologiques', 'admin')); ?>">
            <p class="df-field-hint"><?php echo hsc(df_t('df_field_variants_hint', 'Comma-separated. Plurals or related phrasing that should trigger the same definition (e.g. a plural form). Whichever form actually appears in the text is what gets underlined.', 'admin')); ?></p>
        </div>

        <label class="df-checkbox-label">
            <input type="checkbox" name="enabled" value="1" <?php echo ($editRecord === null || !empty($editRecord['enabled'])) ? 'checked' : ''; ?>>
            <?php echo hsc(df_t('df_field_enabled', 'Active on the site', 'admin')); ?>
        </label>

        <label class="df-checkbox-label">
            <input type="checkbox" id="df-wrap-all" name="wrap_all" value="1"
                   data-df-wrap-all-toggle="df-max-occurrences"
                   <?php echo $wrapAllChecked ? 'checked' : ''; ?>>
            <?php echo hsc(df_t('df_field_wrap_all', 'Underline every occurrence, not just the first', 'admin')); ?>
        </label>

        <div class="df-field df-field--inline">
            <label for="df-max-occurrences"><?php echo hsc(df_t('df_field_max_occurrences', 'Number of first occurrences to underline', 'admin')); ?></label>
            <input type="number" id="df-max-occurrences" name="max_occurrences" min="1" max="99"
                   value="<?php echo (int)$maxOccValue; ?>"
                   <?php echo $wrapAllChecked ? 'disabled' : ''; ?>>
        </div>

        <div class="df-form-actions">
            <button type="submit" class="df-btn df-btn--primary">
                <?php echo hsc($editRecord !== null ? df_t('df_save_changes', 'Save changes', 'admin') : df_t('df_create_button', 'Add definition', 'admin')); ?>
            </button>
            <?php if ($editRecord !== null): ?>
            <a href="<?php echo hsc(df_admin_page_url()); ?>" class="df-btn df-btn--outline"><?php echo hsc(df_t('df_cancel', 'Cancel', 'admin')); ?></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="df-panel">
    <h2><?php echo hsc(df_t('df_list_title', 'Glossary', 'admin')); ?> (<span id="df-count"><?php echo count($allTerms); ?></span>)</h2>

    <?php if (empty($allTerms)): ?>
        <p class="df-empty"><?php echo hsc(df_t('df_no_terms', 'No definitions yet.', 'admin')); ?></p>
    <?php else: ?>
        <div class="df-filter-bar">
            <div class="df-search-wrap">
                <input type="search" id="df-search-input" placeholder="<?php echo hsc(df_t('df_search_placeholder', 'Search by term, definition, or variant…', 'admin')); ?>">
            </div>
            <select id="df-status-filter">
                <option value="all"><?php echo hsc(df_t('df_filter_status_all', 'All statuses', 'admin')); ?></option>
                <option value="active"><?php echo hsc(df_t('df_status_active', 'Active', 'admin')); ?></option>
                <option value="inactive"><?php echo hsc(df_t('df_status_inactive', 'Inactive', 'admin')); ?></option>
            </select>
            <select id="df-per-page-filter">
                <?php foreach (DF_PER_PAGE_OPTIONS as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo $opt === DF_DEFAULT_PER_PAGE ? 'selected' : ''; ?>><?php echo $opt === 0 ? hsc(df_t('df_filter_per_page_all', 'All', 'admin')) : $opt; ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="df-filter-reset" class="df-btn df-btn--outline"><?php echo hsc(df_t('df_filter_reset', 'Reset', 'admin')); ?></button>
        </div>

        <p class="df-empty" id="df-no-results" style="display:none"><?php echo hsc(df_t('df_no_results', 'No definitions match this search or filter.', 'admin')); ?></p>

        <div class="df-table-wrap">
            <table class="df-table" id="df-table">
                <thead>
                    <tr>
                        <th><?php echo hsc(df_t('df_col_term', 'Term', 'admin')); ?></th>
                        <th><?php echo hsc(df_t('df_col_variants', 'Variants', 'admin')); ?></th>
                        <th><?php echo hsc(df_t('df_col_definition', 'Definition', 'admin')); ?></th>
                        <th><?php echo hsc(df_t('df_col_occurrences', 'Occurrences', 'admin')); ?></th>
                        <th><?php echo hsc(df_t('df_col_status', 'Status', 'admin')); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="df-table-body">
                    <?php foreach ($allTerms as $id => $entry):
                        $rowWrapAll  = array_key_exists('wrap_all', $entry) ? !empty($entry['wrap_all']) : $globalWrapAllDefault;
                        $rowMaxOcc   = (int)($entry['max_occurrences'] ?? $globalMaxOccDefault);
                        $rowEnabled  = !empty($entry['enabled']);
                        $searchBlob  = mb_strtolower(($entry['term'] ?? '') . ' ' . ($entry['definition'] ?? '') . ' ' . implode(' ', $entry['variants'] ?? []));
                    ?>
                    <tr data-df-status="<?php echo $rowEnabled ? 'active' : 'inactive'; ?>" data-df-search="<?php echo hsc($searchBlob); ?>">
                        <td class="df-table-term"><?php echo hsc($entry['term'] ?? ''); ?></td>
                        <td class="df-table-variants"><?php echo hsc(implode(', ', $entry['variants'] ?? [])); ?></td>
                        <td class="df-table-definition"><?php echo hsc($entry['definition'] ?? ''); ?></td>
                        <td>
                            <?php if ($rowWrapAll): ?>
                                <span class="df-badge df-badge--info"><?php echo hsc(df_t('df_occurrences_all', 'All', 'admin')); ?></span>
                            <?php elseif ($rowMaxOcc <= 1): ?>
                                <span class="df-badge df-badge--muted"><?php echo hsc(df_t('df_occurrences_first', 'First only', 'admin')); ?></span>
                            <?php else: ?>
                                <span class="df-badge df-badge--muted"><?php echo hsc(sprintf(df_t('df_occurrences_n', 'First %d', 'admin'), $rowMaxOcc)); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($rowEnabled): ?>
                                <span class="df-badge df-badge--active"><?php echo hsc(df_t('df_status_active', 'Active', 'admin')); ?></span>
                            <?php else: ?>
                                <span class="df-badge df-badge--inactive"><?php echo hsc(df_t('df_status_inactive', 'Inactive', 'admin')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="df-table-actions">
                            <a href="<?php echo hsc(df_admin_page_url(['edit' => $id])); ?>" class="df-btn df-btn--sm df-btn--outline"><?php echo hsc(df_t('df_edit', 'Edit', 'admin')); ?></a>
                            <form method="post" action="<?php echo hsc(df_admin_actions_url()); ?>" data-confirm="<?php echo hsc(df_t('df_confirm_delete', 'Remove this definition?', 'admin'), ENT_QUOTES); ?>">
                                <input type="hidden" name="_csrf" value="<?php echo hsc($csrf); ?>">
                                <input type="hidden" name="action" value="delete_term">
                                <input type="hidden" name="id" value="<?php echo hsc($id); ?>">
                                <button type="submit" class="df-btn df-btn--delete df-btn--sm"><?php echo hsc(df_t('df_remove', 'Remove', 'admin')); ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($allTerms)): ?>
<div class="df-pagination" id="df-pagination" style="display:none">
    <div class="df-pagination-info" id="df-pagination-info"></div>
    <div class="df-pagination-controls">
        <button type="button" class="df-pg-btn" id="df-pg-prev" aria-label="<?php echo hsc(df_t('df_prev_page', 'Previous', 'admin')); ?>">&lsaquo;</button>
        <div class="df-pg-pages" id="df-pg-pages"></div>
        <button type="button" class="df-pg-btn" id="df-pg-next" aria-label="<?php echo hsc(df_t('df_next_page', 'Next', 'admin')); ?>">&rsaquo;</button>
    </div>
</div>

<script type="application/json" id="df-i18n"><?php echo json_encode([
    'range' => df_t('df_pagination_range', '%1$d–%2$d of %3$d', 'admin'),
], JSON_UNESCAPED_UNICODE); ?></script>
<?php endif; ?>

