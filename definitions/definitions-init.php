<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

if (defined('DF_INIT_LOADED')) return;
define('DF_INIT_LOADED', true);

require_once __DIR__ . '/definitions-functions.php';
require_once __DIR__ . '/definitions-i18n.php';

require_once __DIR__ . '/admin/admin-page.php';

if (function_exists('pl_on_admin_menu')) {
    pl_on_admin_menu(function () {
        pl_register_admin_menu(
            'definitions',
            df_t('df_admin_title', 'Definitions', 'admin'),
            df_admin_page_url(),
            '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>'
        );
    });
}

$__df_isCli    = PHP_SAPI === 'cli';
$__df_isPlugin = strpos($_SERVER['REQUEST_URI'] ?? '', '/plugins/') !== false;
$__df_cfgFile  = dirname(__DIR__, 2) . '/config.json';
$__df_adminDir = 'admin';
if (file_exists($__df_cfgFile)) {
    $__df_cfg = json_decode(file_get_contents($__df_cfgFile), true);
    if (is_array($__df_cfg) && !empty($__df_cfg['admin_dir'])) {
        $__df_adminDir = $__df_cfg['admin_dir'];
    }
    unset($__df_cfg);
}
unset($__df_cfgFile);
$__df_isAdmin = strpos($_SERVER['REQUEST_URI'] ?? '', '/' . $__df_adminDir . '/') !== false;

if (!$__df_isCli && !$__df_isPlugin && !$__df_isAdmin) {
    $__df_terms = df_load_enabled_terms();

    // Registered this early (plugin load time, before index.php's own
    // page-cache short-circuit) so a cached response's echo+exit still
    // flows through this buffer and gets wrapped — the alternative,
    // hooking 'after_routing', never fires on a cache hit at all and so
    // never wraps anything on the pages that matter most. The cost of
    // running on every request, cache hit included, is what
    // df_render_page()'s own cache absorbs — see its doc comment.
    if (!empty($__df_terms)) {
        $__df_settings       = df_load_settings();
        $__df_wrapAllDefault = !empty($__df_settings['wrap_all_by_default']);
        $__df_maxOccDefault  = max(1, (int)($__df_settings['max_occurrences_default'] ?? 1));
        $__df_skipHeadings   = !empty($__df_settings['skip_headings']);
        ob_start(function (string $html) use ($__df_terms, $__df_wrapAllDefault, $__df_maxOccDefault, $__df_skipHeadings): string {
            return df_render_page($html, $__df_terms, $__df_wrapAllDefault, $__df_maxOccDefault, $__df_skipHeadings);
        });
        unset($__df_settings, $__df_wrapAllDefault, $__df_maxOccDefault, $__df_skipHeadings);
    }
    unset($__df_terms);
}

unset($__df_isCli, $__df_isPlugin, $__df_isAdmin, $__df_adminDir);
