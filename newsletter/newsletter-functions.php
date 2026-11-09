<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Newsletter Plugin — Core Functions
 *
 * Settings and the URL helpers used throughout the plugin.
 */

if (defined('NL_FUNCTIONS_LOADED')) return;
define('NL_FUNCTIONS_LOADED', true);

require_once __DIR__ . '/newsletter-data-layer.php';

function nl_settings_path(): string
{
    return __DIR__ . '/data/config.json';
}

function nl_default_settings(): array
{
    return [
        'from_email'          => '',  // falls back to noreply@{host} when empty
        'from_name'           => '',  // falls back to the site title when empty
        'last_digest_sent_at' => null, // Y-m-d H:i:s — used to pick "new since last digest" articles
        // Optional custom HTML wrapper for the article digest (WYSIWYG-authored,
        // Digest tab). Must contain {articles} where the article cards should be
        // inserted. Empty string means "use the built-in default" — see
        // nl_render_digest_template() in newsletter-mail.php. Supports
        // {site_name} and {email} placeholders too; {name} is deliberately not
        // offered since the signup form only ever collects an email address,
        // never a real name — see the docblock on nl_render_digest_template().
        'digest_template'     => '',
        'name_field'          => 'off',
        'name_fallback'       => '',
        'button_color'        => '',
        // Whether the subscriber-count widget appears on the main admin
        // dashboard (see newsletter-init.php, 'admin_dashboard' hook).
        'show_dashboard_widget' => true,
        // SMTP transport configuration (Settings → SMTP tab). When disabled
        // (the default), every outgoing email keeps using PHP's native
        // mail() exactly as before — nothing here changes behaviour until
        // the admin explicitly opts in. See nl_get_mailer() in
        // newsletter-mail.php for how this is consumed. Mirrors the
        // Booking plugin's own SMTP settings shape exactly.
        //
        // The SMTP password itself is never stored here: config.json is
        // the file an admin is most likely to open, screenshot, or paste
        // when asking for help, and it's already the shape most exposed
        // to accidental disclosure in this plugin. The password lives
        // instead in the plugin's private/ directory (nl_private_dir() —
        // same .htaccess-denied store as the CSRF secret) via
        // nl_save_smtp_password() / nl_load_smtp_password().
        'smtp' => [
            'enabled'    => false,
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls', // 'tls', 'ssl', or 'none' for none
            'username'   => '',
            'auth'       => true,
        ],
    ];
}

function nl_clean_name(string $name): string
{
    $name = strip_tags($name);
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    return mb_substr($name, 0, 100);
}

function nl_name_field_mode(): string
{
    $mode = nl_load_settings()['name_field'] ?? 'off';
    return in_array($mode, ['optional', 'required'], true) ? $mode : 'off';
}

function nl_button_color(): string
{
    $color = (string)(nl_load_settings()['button_color'] ?? '');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '';
}

function nl_button_text_color(string $hex): string
{
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#111111' : '#ffffff';
}

function nl_subscriber_name(?array $subscriber): string
{
    $name = trim((string)($subscriber['name'] ?? ''));
    return $name !== '' ? $name : trim((string)(nl_load_settings()['name_fallback'] ?? ''));
}

function nl_apply_placeholders(string $html, string $email, string $name): string
{
    $coreSettings = function_exists('loadConfig') ? loadConfig() : [];
    $safeName     = htmlspecialchars($name);

    return str_replace(
        ['{name}', '{nom}', '{email}', '{site_name}'],
        [$safeName, $safeName, htmlspecialchars($email), htmlspecialchars($coreSettings['site_title'] ?? '')],
        $html
    );
}

function nl_load_settings(): array
{
    if (isset($GLOBALS['_nl_config_cache'])) {
        return $GLOBALS['_nl_config_cache'];
    }

    $settings = nl_default_settings();
    $path     = nl_settings_path();

    if (file_exists($path)) {
        $decoded = json_decode(file_get_contents($path), true);
        if (is_array($decoded)) {
            $settings = array_merge($settings, $decoded);
            // 'smtp' is a nested array — the top-level array_merge() above
            // replaces it wholesale rather than merging its own keys, so a
            // config.json written before a new sub-key existed would
            // silently lose any default not explicitly present on disk.
            // Re-merged one level deeper here so defaults always backfill
            // missing sub-keys the same way the rest of $settings does.
            // Mirrors the same fix in the Booking plugin's bk_load_settings().
            if (!empty($decoded['smtp']) && is_array($decoded['smtp'])) {
                $settings['smtp'] = array_merge(nl_default_settings()['smtp'], $decoded['smtp']);
            }
        }
    }

    $GLOBALS['_nl_config_cache'] = $settings;
    return $settings;
}

function nl_save_settings(array $settings): bool
{
    nl_ensure_dirs();
    $success = _nl_write_json(nl_settings_path(), $settings);
    if ($success) {
        unset($GLOBALS['_nl_config_cache']);
    }
    return $success;
}

/**
 * Path to the SMTP password file, inside the plugin's .htaccess-denied
 * private/ store (nl_private_dir(), newsletter-security.php) rather than
 * data/config.json. Kept separate so the password never ends up in a
 * file an admin might reasonably copy/paste/screenshot when asking for
 * help with an unrelated setting. Mirrors the Booking plugin's
 * bk_smtp_password_path().
 *
 * Loads newsletter-security.php defensively if it hasn't been loaded yet
 * (rather than assuming call-site ordering), so this works correctly
 * regardless of which entry point reaches it first.
 */
function nl_smtp_password_path(): string
{
    if (!function_exists('nl_private_dir')) {
        require_once __DIR__ . '/newsletter-security.php';
    }
    return nl_private_dir() . '/smtp_password.php';
}

/**
 * Saves the SMTP password, wrapped in a PHP file that refuses direct
 * access if requested — defense in depth alongside the private/
 * directory's own .htaccess. Pass an empty string to clear the stored
 * password (e.g. when SMTP auth is turned off).
 */
function nl_save_smtp_password(string $password): bool
{
    nl_ensure_dirs();
    if (!function_exists('nl_private_dir')) {
        require_once __DIR__ . '/newsletter-security.php';
    }
    nl_private_dir(); // ensures the directory (and its .htaccess) exist

    $php = "<?php // Do not access directly.\nif (!defined('NL_FUNCTIONS_LOADED')) { http_response_code(403); exit; }\nreturn " . var_export($password, true) . ";\n";

    $path = nl_smtp_password_path();
    $tmp  = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $php, LOCK_EX) === false) return false;

    return rename($tmp, $path);
}

/**
 * Loads the stored SMTP password, or '' if none has been set.
 */
function nl_load_smtp_password(): string
{
    $path = nl_smtp_password_path();
    if (!file_exists($path)) return '';

    $password = include $path;
    return is_string($password) ? $password : '';
}

/**
 * Returns the site's base URL with a trailing slash, correctly accounting
 * for the CMS living in a sub-directory rather than the domain root.
 *
 * Derived from this file's own filesystem position (CMS root two levels
 * up from plugins/newsletter/) relative to DOCUMENT_ROOT, rather than
 * delegating to the core's getBaseUrl() — that function reads
 * SCRIPT_NAME, which only resolves correctly when index.php is the
 * currently-executing script. This plugin's own public endpoints
 * (newsletter-submit.php, newsletter-confirm.php, newsletter-unsubscribe.php)
 * run directly, so SCRIPT_NAME would point inside plugins/newsletter/ instead.
 */
function nl_site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    $host = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $cmsRootFilesystemPath = dirname(__DIR__, 2); // plugins/newsletter/ -> plugins/ -> CMS root
    $documentRoot          = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $cmsRootFilesystemPath = str_replace('\\', '/', $cmsRootFilesystemPath);

    if ($documentRoot !== '' && strpos($cmsRootFilesystemPath, $documentRoot) === 0) {
        $urlPath = substr($cmsRootFilesystemPath, strlen($documentRoot));
    } else {
        // DOCUMENT_ROOT unavailable or mismatched (rare, some proxy setups) —
        // fall back to the core's getBaseUrl() logic as a best effort.
        $urlPath = function_exists('getBaseUrl') ? parse_url(getBaseUrl(), PHP_URL_PATH) : '/';
    }

    $urlPath = '/' . trim($urlPath, '/');
    if ($urlPath === '/') {
        return $host . '/';
    }
    return $host . $urlPath . '/';
}

/**
 * Returns the base URL of the plugin's own folder, with a trailing slash.
 * Use this for links to the confirm/unsubscribe endpoints and assets.
 */
function nl_plugin_base_url(): string
{
    return nl_site_base_url() . 'plugins/newsletter/';
}

/**
 * Formats a stored Y-m-d H:i:s timestamp (subscriber confirmation time,
 * newsletter send time, ...) using the core's format_date() for the date
 * portion, plus a fixed H:i time portion — consistent with the rest of
 * the CMS admin, which never localizes clock time, only calendar dates.
 * Only ever called from the admin dashboard, where the core is always
 * loaded, but guarded regardless since this file has no hard dependency
 * on functions.php being present.
 */
function nl_format_datetime(?string $datetime): string
{
    if (empty($datetime)) return '';

    $timestamp = strtotime($datetime);
    if ($timestamp === false) return $datetime;

    $datePart = function_exists('format_date') ? format_date($datetime) : date('Y-m-d', $timestamp);
    return $datePart . ' ' . date('H:i', $timestamp);
}

/**
 * Resolves the "From" address/name for outgoing emails, falling back to a
 * noreply@{host} address and the site title when not explicitly configured.
 */
function nl_from_address(): array
{
    $settings = nl_load_settings();
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $email = $settings['from_email'] !== '' ? $settings['from_email'] : ('noreply@' . $host);

    $name = $settings['from_name'];
    if ($name === '' && function_exists('loadConfig')) {
        $coreSettings = loadConfig();
        $name = $coreSettings['site_title'] ?? 'Newsletter';
    }
    if ($name === '') {
        $name = 'Newsletter';
    }

    return ['email' => $email, 'name' => $name];
}

/**
 * Returns published articles more recent than the given reference date
 * (last digest send, or "beginning of time" the very first send), newest
 * first. Reads only the lightweight index — full article bodies are never
 * needed for a digest, which links out to the article rather than
 * reproducing its content.
 *
 * Requires the core's sl_load_index() (core/data-layer.php). That file is
 * loaded automatically when this runs through the plugin's admin page
 * (admin/index.php → admin-functions.php loads the full core), but NOT
 * when it runs through this plugin's own admin/actions.php — a
 * standalone POST endpoint that only loads the plugin's own bootstrap.php,
 * never the core. Without this require, sl_load_index() silently doesn't
 * exist there and this function always returned [] on that path — the
 * exact symptom of "No new articles" appearing regardless of what's
 * actually published. Requires core/functions.php rather than
 * core/data-layer.php directly: data-layer.php's sl_data_dir() depends on
 * the CMS_ROOT constant, which functions.php defines before pulling in
 * data-layer.php itself — requiring data-layer.php alone here would trade
 * the silent no-op for a fatal "undefined constant" instead. Included
 * defensively (existence checks) since dirname(__DIR__, 2) assumes the
 * plugin's standard install location (plugins/newsletter/ two levels below
 * the CMS root).
 *
 * @param string|null $sinceDate Y-m-d H:i:s, or null for "all published articles"
 */
function nl_articles_since(?string $sinceDate): array
{
    if (!function_exists('sl_load_index')) {
        $coreFunctions = dirname(__DIR__, 2) . '/core/functions.php';
        if (file_exists($coreFunctions)) {
            require_once $coreFunctions;
        }
    }
    if (!function_exists('sl_load_index')) return [];

    $index = sl_load_index('article');
    $sinceTs = $sinceDate !== null ? strtotime($sinceDate) : false;

    $matches = array_filter($index, function ($entry) use ($sinceTs) {
        if (($entry['status'] ?? 'published') !== 'published') return false;
        if ($sinceTs === false) return true;
        $entryTs = strtotime($entry['date'] ?? '');
        return $entryTs !== false && $entryTs > $sinceTs;
    });

    usort($matches, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

    return array_values($matches);
}
