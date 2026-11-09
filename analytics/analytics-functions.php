<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Analytics Plugin — Core Functions
 *
 * Shared helpers used by both the tracker and the admin data layer.
 */

if (defined('AN_FUNCTIONS_LOADED')) return;
define('AN_FUNCTIONS_LOADED', true);

// ── Paths ────────────────────────────────────────────────────────────────────

function an_data_dir(): string   { return __DIR__ . '/data'; }
function an_logs_dir(): string   { return __DIR__ . '/data/logs'; }
function an_cache_dir(): string  { return __DIR__ . '/data/cache'; }

function an_private_dir(): string
{
    $dir = __DIR__ . '/private';

    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
        $htaccess = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                  . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        @file_put_contents($dir . '/.htaccess', $htaccess);
    }

    return $dir;
}

/**
 * Loads (or generates on first use) the per-install secret used to salt
 * hashed IPs. Mirrors the Newsletter plugin's nl_get_secret() — replaces a
 * hardcoded salt that shipped identically in every install (making the
 * "pseudonymized" IP hash trivially reversible: with a public salt and a
 * known month, the full IPv4 space is enumerable in minutes).
 */
function an_get_secret(): string
{
    $secretFile = an_private_dir() . '/analytics.secret';

    if (file_exists($secretFile)) {
        $secret = trim(file_get_contents($secretFile));
        if (strlen($secret) === 64) {
            return $secret;
        }
    }

    $secret = bin2hex(random_bytes(32));
    @file_put_contents($secretFile, $secret, LOCK_EX);
    return $secret;
}

function an_log_file(string $date): string
{
    return an_logs_dir() . '/' . $date . '.json';
}

function an_cache_file(string $range): string
{
    return an_cache_dir() . '/aggregate-' . $range . '.json';
}

// ── URL / admin helpers ──────────────────────────────────────────────────────

/**
 * Returns the CMS base URL with a trailing slash, correctly accounting for
 * subdirectory installs (e.g. https://host/synaptikcms/) and OVH reverse proxy.
 *
 * Derived from the plugin's own filesystem position relative to DOCUMENT_ROOT,
 * not from SCRIPT_NAME — SCRIPT_NAME changes depending on which script is
 * currently executing (index.php, admin/index.php, actions.php…), making
 * it unreliable for computing a stable CMS root URL.
 */
function an_site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])  && $_SERVER['HTTP_X_FORWARDED_SSL']  === 'on');

    $host = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    // __DIR__ is plugins/analytics/ — two levels below the CMS root
    $cmsRoot      = str_replace('\\', '/', dirname(__DIR__, 2));
    $documentRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

    if ($documentRoot !== '' && strpos($cmsRoot, $documentRoot) === 0) {
        $urlPath = substr($cmsRoot, strlen($documentRoot));
    } else {
        // DOCUMENT_ROOT unavailable or mismatched — delegate to core getBaseUrl() if loaded
        $urlPath = function_exists('getBaseUrl') ? parse_url(getBaseUrl(), PHP_URL_PATH) : '/';
    }

    $urlPath = '/' . trim((string)$urlPath, '/');
    return $host . ($urlPath === '/' ? '/' : $urlPath . '/');
}

function an_admin_page_url(string $view, array $extra = []): string
{
    $params = array_merge(['action' => 'plugin_page', 'slug' => 'analytics', 'view' => $view], $extra);
    $file   = dirname(__DIR__, 2) . '/config.json';
    $dir    = 'admin';
    if (file_exists($file)) {
        $d = json_decode(file_get_contents($file), true);
        if (is_array($d) && !empty($d['admin_dir'])) $dir = $d['admin_dir'];
    }
    return an_site_base_url() . $dir . '/index.php?' . http_build_query($params);
}

// ── Directory bootstrap ───────────────────────────────────────────────────────

function an_ensure_dirs(): void
{
    $htaccess = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
              . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";

    if (!is_dir(an_data_dir())) {
        mkdir(an_data_dir(), 0755, true);
    }
    if (!is_file(an_data_dir() . '/.htaccess')) {
        @file_put_contents(an_data_dir() . '/.htaccess', $htaccess);
    }

    foreach ([an_logs_dir(), an_cache_dir()] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/.htaccess', $htaccess);
    }
}

// ── Atomic JSON write ────────────────────────────────────────────────────────

function an_write_json(string $path, mixed $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

// ── Bot detection ─────────────────────────────────────────────────────────────

// Paths that only scanners and exploit bots request — never a real visitor.
define('AN_SCAN_PATHS', [
    '.env', 'wp-config', 'phpinfo', '/info.php', '/_debugbar', '/debugbar',
    '/debug/', 'adminer', 'phpmyadmin', 'wp-login', 'xmlrpc', 'eval-stdin',
    '/shell', '/cmd', '/backdoor', 'config.bak', '.git/config',
    '/.aws', '/.ssh', '/etc/passwd', 'base64_decode',
]);

function an_is_bot(string $ua): bool
{
    if ($ua === '') return true;

    // Suspicious path scan — flag as bot regardless of UA
    $uri = strtolower(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
    foreach (AN_SCAN_PATHS as $p) {
        if (strpos($uri, $p) !== false) return true;
    }

    $patterns = [
        'bot','crawl','spider','slurp','facebookexternalhit','ia_archiver',
        'mediapartners','adsbot','google-read-aloud','curl','wget','python',
        'java/','ruby','go-http','okhttp','axios','node-fetch','libwww',
        'headless','phantomjs','selenium','puppeteer','playwright',
    ];
    $ua = strtolower($ua);
    foreach ($patterns as $p) {
        if (strpos($ua, $p) !== false) return true;
    }
    return false;
}

// ── Referrer parsing ─────────────────────────────────────────────────────────

function an_ai_sources(): array
{
    return [
        'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'perplexity.ai' => 'Perplexity',
        'gemini.google.com' => 'Gemini', 'bard.google.com' => 'Gemini', 'copilot.microsoft.com' => 'Copilot',
        'copilot.cloud.microsoft' => 'Copilot', 'claude.ai' => 'Claude', 'chat.deepseek.com' => 'DeepSeek',
        'chat.mistral.ai' => 'Le Chat', 'meta.ai' => 'Meta AI', 'grok.com' => 'Grok', 'you.com' => 'You.com',
        'phind.com' => 'Phind', 'poe.com' => 'Poe', 'duck.ai' => 'Duck.ai', 'chat.qwen.ai' => 'Qwen',
    ];
}

function an_ai_source(string $host, string $utm = ''): string
{
    $host = strtolower($host);
    if (str_starts_with($host, 'www.')) $host = substr($host, 4);
    foreach (an_ai_sources() as $domain => $label) {
        if ($host !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) return $label;
    }
    $utm = strtolower(trim($utm));
    if ($utm === '') return '';
    $utmMap = [
        'chatgpt.com' => 'ChatGPT', 'chatgpt' => 'ChatGPT', 'openai' => 'ChatGPT', 'perplexity' => 'Perplexity',
        'perplexity.ai' => 'Perplexity', 'gemini' => 'Gemini', 'copilot' => 'Copilot', 'claude' => 'Claude',
        'claude.ai' => 'Claude', 'deepseek' => 'DeepSeek', 'mistral' => 'Le Chat', 'grok' => 'Grok', 'meta.ai' => 'Meta AI',
    ];
    return $utmMap[$utm] ?? (an_ai_sources()[$utm] ?? '');
}

function an_parse_referrer(string $url): array
{
    if ($url === '') return ['host' => '', 'medium' => 'direct'];
    $aiHost = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (an_ai_source($aiHost) !== '') {
        return ['host' => str_starts_with($aiHost, 'www.') ? substr($aiHost, 4) : $aiHost, 'medium' => 'ai'];
    }

    $parts = parse_url($url);
    $host  = isset($parts['host']) ? strtolower($parts['host']) : '';
    // ltrim()'s 2nd arg is a character set, not a prefix — strips any leading
    // run of 'w'/'.' characters, mangling hosts like "w3schools.com" or
    // "wordpress.org" into "3schools.com"/"ordpress.org". A literal prefix
    // check is required instead.
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }

    if ($host === '') return ['host' => '', 'medium' => 'direct'];

    $search = [
        'google','bing','yahoo','duckduckgo','ecosia','qwant','brave','baidu',
        'yandex','naver','daum','seznam','sogou','ask.com',
    ];
    $social = [
        'facebook','instagram','twitter','x.com','t.co','linkedin','pinterest',
        'reddit','tiktok','youtube','snapchat','whatsapp','telegram','mastodon',
        'bluesky','threads',
    ];

    foreach ($search as $s) {
        if (strpos($host, $s) !== false) return ['host' => $host, 'medium' => 'search'];
    }
    foreach ($social as $s) {
        if (strpos($host, $s) !== false) return ['host' => $host, 'medium' => 'social'];
    }

    return ['host' => $host, 'medium' => 'referral'];
}

// ── Device detection ──────────────────────────────────────────────────────────

function an_detect_device(string $ua): string
{
    if (preg_match('/tablet|ipad|playbook|silk|(android(?!.*mobile))/i', $ua)) return 'tablet';
    if (preg_match('/mobile|iphone|ipod|android.*mobile|blackberry|iemobile|opera m(ob|in)i/i', $ua)) return 'mobile';
    return 'desktop';
}

// ── IP hashing (GDPR — never store raw IPs) ───────────────────────────────────

/**
 * Hashes an IP address with a monthly rotating salt plus a per-install
 * secret (an_get_secret()) — without the secret, a hash of IP+month is
 * reversible: IPv4 space is small enough (~4.3B) to enumerate in minutes
 * once the salt is known, which it would be if it were a constant shipped
 * in source. That made the hash legally still an IP address (GDPR recital
 * 26 — pseudonymized data stays personal data as long as re-identification
 * is feasible with reasonable effort), despite looking pseudonymized.
 *
 * Monthly salt (Y-m) instead of daily (Y-m-d): the same visitor is
 * identifiable within a calendar month, making "unique visitors" counts
 * meaningful over 7d/30d ranges, while cross-month tracking is impossible
 * by design. Raw IPs are never stored.
 */
function an_hash_ip(string $ip): string
{
    return hash('sha256', $ip . '|' . date('Y-m') . '|' . an_get_secret());
}

/**
 * Hashes an IP with a daily salt — used when the dedup window is a single
 * day (e.g. the "today" unique count on the KPI card).
 */
function an_hash_ip_daily(string $ip): string
{
    return hash('sha256', $ip . '|' . date('Y-m-d') . '|' . an_get_secret());
}
