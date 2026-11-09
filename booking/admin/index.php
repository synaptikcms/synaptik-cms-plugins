<?php
/**
 * Booking Plugin — Legacy Admin Entry (redirect only)
 *
 * The plugin's admin page now renders inside the core admin layout via
 * admin/index.php?action=plugin_page&slug=booking&view={view} (see
 * admin/admin-page.php). This file only exists so old bookmarks/links to
 * /plugins/booking/admin/ still land somewhere useful instead of a 404.
 */

require_once __DIR__ . '/../booking-functions.php';

$view = $_GET['view'] ?? 'calendar';
$view = preg_replace('/[^a-z0-9_-]/', '', $view);

header('Location: ' . bk_site_base_url() . 'admin/index.php?action=plugin_page&slug=booking&view=' . urlencode($view));
exit;
