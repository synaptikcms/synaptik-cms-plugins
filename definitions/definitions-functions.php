<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

if (defined('DF_FUNCTIONS_LOADED')) return;
define('DF_FUNCTIONS_LOADED', true);

require_once __DIR__ . '/definitions-data-layer.php';

function df_site_base_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
             || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
    $host = ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $cmsRootFilesystemPath = dirname(__DIR__, 2); // definitions/ -> plugins/ -> CMS root
    $documentRoot          = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $cmsRootFilesystemPath = str_replace('\\', '/', $cmsRootFilesystemPath);

    $realCmsRoot = realpath($cmsRootFilesystemPath) ?: $cmsRootFilesystemPath;
    $realDocRoot = $documentRoot !== '' ? (realpath($documentRoot) ?: $documentRoot) : '';
    $realCmsRoot = str_replace('\\', '/', $realCmsRoot);
    $realDocRoot = str_replace('\\', '/', $realDocRoot);

    if ($realDocRoot !== '' && strpos($realCmsRoot, $realDocRoot) === 0) {
        $urlPath = substr($realCmsRoot, strlen($realDocRoot));
    } elseif ($documentRoot !== '' && strpos($cmsRootFilesystemPath, $documentRoot) === 0) {
        $urlPath = substr($cmsRootFilesystemPath, strlen($documentRoot));
    } else {
        $urlPath = function_exists('getBaseUrl') ? parse_url(getBaseUrl(), PHP_URL_PATH) : '/';
        error_log(sprintf(
            '[Definitions] df_site_base_url() could not match DOCUMENT_ROOT against the plugin path — falling back to getBaseUrl(). cmsRootFilesystemPath=%s documentRoot=%s realCmsRoot=%s realDocRoot=%s fallbackUrlPath=%s',
            $cmsRootFilesystemPath,
            $documentRoot,
            $realCmsRoot,
            $realDocRoot,
            (string)$urlPath
        ));
    }

    $urlPath = '/' . trim($urlPath, '/');
    if ($urlPath === '/') {
        return $host . '/';
    }
    return $host . $urlPath . '/';
}

function df_plugin_base_url(): string
{
    return df_site_base_url() . 'plugins/definitions/';
}

function df_plugin_asset_url(string $relativePath): string
{
    $absPath = __DIR__ . '/' . $relativePath;
    $v       = @filemtime($absPath) ?: time();

    return df_plugin_base_url() . $relativePath . '?v=' . $v;
}

// ─── CSRF (admin forms only — no public-facing endpoint in this plugin) ────

function df_private_dir(): string
{
    $dir = __DIR__ . '/private';

    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $htaccess = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
              . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
    @file_put_contents($dir . '/.htaccess', $htaccess);

    return $dir;
}

function df_get_secret(): string
{
    $secretFile = df_private_dir() . '/definitions.secret';

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

function df_generate_csrf(): string
{
    $ts     = time();
    $secret = df_get_secret();
    $mac    = hash_hmac('sha256', (string)$ts, $secret);
    return $ts . '.' . $mac;
}

function df_verify_csrf(string $token): bool
{
    if (empty($token) || substr_count($token, '.') !== 1) return false;

    [$ts, $mac] = explode('.', $token, 2);
    if (!is_numeric($ts)) return false;
    if ((time() - (int)$ts) > 7200) return false;

    $expected = hash_hmac('sha256', $ts, df_get_secret());
    return hash_equals($expected, $mac);
}

const DF_MAX_VARIANTS = 15;
const DF_DEFAULT_PER_PAGE = 25;
const DF_PER_PAGE_OPTIONS = [10, 25, 50, 0];


/**
 * Parses the admin form's comma-separated variants field into a clean
 * array: trimmed, empty entries dropped, deduplicated case-insensitively
 * against each other and against the term itself (listing the term as
 * its own variant would just make it match twice for no gain), capped in
 * count and per-entry length to keep the alternation regex in
 * df_wrap_terms() bounded.
 */
function df_sanitize_variants(string $raw, string $term): array
{
    $seen = [mb_strtolower(trim($term))];
    $out  = [];

    foreach (explode(',', $raw) as $candidate) {
        $candidate = mb_substr(trim($candidate), 0, 80);
        if ($candidate === '') continue;

        $key = mb_strtolower($candidate);
        if (in_array($key, $seen, true)) continue;
        $seen[] = $key;

        $out[] = $candidate;
        if (count($out) >= DF_MAX_VARIANTS) break;
    }

    return $out;
}

const DF_MAX_OCCURRENCES_CAP = 99;

function df_sanitize_max_occurrences($raw): int
{
    $value = (int)$raw;
    return max(1, min(DF_MAX_OCCURRENCES_CAP, $value ?: 1));
}

// ─── Front-end term wrapping ────────────────────────────────────────────────

/**
 * Tag names inside which a term must never be wrapped: markup, scripts,
 * form controls and existing links all break if a <span> lands inside them.
 */
const DF_SKIP_TAGS = ['script', 'style', 'textarea', 'select', 'option', 'a', 'title'];
const DF_HEADING_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

function df_skip_tag_pattern(bool $skipHeadings = false): string
{
    $tags = $skipHeadings ? array_merge(DF_SKIP_TAGS, DF_HEADING_TAGS) : DF_SKIP_TAGS;
    return '(' . implode('|', $tags) . ')';
}

/**
 * Wraps each enabled term — or one of its variants (plurals, related
 * phrasing, entered in the admin as a comma-separated list) — found in
 * the document's <body> with a <span class="df-term">, carrying the
 * definition in a data attribute for the front-end tooltip script to
 * read. Only the body is scanned — head/meta/script content never
 * touches user-visible text anyway, and skipping it avoids corrupting
 * <title> or inline JSON.
 *
 * Matching is Unicode-aware whole-word (accented French terms included),
 * case-insensitive. All of a term's forms (its own name plus its variants)
 * share one alternation pattern, so whichever form is actually present
 * still resolves to that term's single definition — the label shown in
 * each popup is whatever text was actually matched, not the canonical
 * term name. Terms are tried longest-form-first so a multi-word term (or
 * a longer variant) is preferred over a shorter one it contains.
 *
 * Per term, only its first $globalMaxOccurrences matching occurrences are
 * wrapped by default — a glossary tooltip on every repetition of a common
 * word would be more noise than help — unless the term's own 'wrap_all'
 * flag is set, in which case every occurrence is wrapped instead. A term
 * can override both the on/off flag ('wrap_all') and the count
 * ('max_occurrences') individually; either key absent (e.g. on data saved
 * before these options existed) falls back to the matching global default.
 *
 * $skipHeadings additionally excludes <h1>-<h6> from matching — off by
 * default, since it changes existing installs' output the moment it's
 * turned on.
 */
function df_wrap_terms(string $html, array $terms, bool $globalWrapAllDefault = false, int $globalMaxOccurrences = 1, bool $skipHeadings = false): string
{
    if (empty($terms)) return $html;

    if (!preg_match('/<body\b[^>]*>/i', $html, $bodyOpen, PREG_OFFSET_CAPTURE)) {
        return $html;
    }
    $bodyStart = $bodyOpen[0][1];
    $bodyEndTagPos = strripos($html, '</body>');
    if ($bodyEndTagPos === false || $bodyEndTagPos < $bodyStart) {
        return $html;
    }
    $bodyEnd = $bodyEndTagPos + strlen('</body>');

    $before = substr($html, 0, $bodyStart);
    $body   = substr($html, $bodyStart, $bodyEnd - $bodyStart);
    $after  = substr($html, $bodyEnd);

    $pending = [];
    foreach ($terms as $id => $record) {
        $term = trim($record['term'] ?? '');
        if ($term === '') continue;

        $variants = array_filter(array_map('trim', $record['variants'] ?? []), fn($v) => $v !== '');
        $forms    = array_values(array_unique(array_merge([$term], $variants)));
        $alternation = implode('|', array_map(fn($f) => preg_quote($f, '/'), $forms));

        $wrapAll = array_key_exists('wrap_all', $record) ? (bool)$record['wrap_all'] : $globalWrapAllDefault;
        if ($wrapAll) {
            $remaining = null; // unlimited
        } else {
            $maxOccurrences = array_key_exists('max_occurrences', $record) ? (int)$record['max_occurrences'] : $globalMaxOccurrences;
            $remaining = max(1, $maxOccurrences);
        }

        $pending[$id] = [
            'definition' => $record['definition'] ?? '',
            'pattern'    => '/(?<![\p{L}\p{N}_])(?:' . $alternation . ')(?![\p{L}\p{N}_])/ui',
            'maxLen'     => max(array_map('mb_strlen', $forms)),
            'remaining'  => $remaining,
        ];
    }
    if (empty($pending)) return $html;

    uasort($pending, fn($a, $b) => $b['maxLen'] <=> $a['maxLen']);

    $tokens       = preg_split('/(<[^>]+>)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $skipDepth    = 0;
    $skipTagRe    = '/^<\s*(\/?)\s*' . df_skip_tag_pattern($skipHeadings) . '(\s|>|\/)/i';
    $placeholders = [];

    foreach ($tokens as &$token) {
        if ($token === '' || empty($pending)) continue;

        if ($token[0] === '<') {
            if (preg_match($skipTagRe, $token, $m)) {
                $isClosing   = $m[1] === '/';
                $selfClosing = substr(rtrim($token), -2) === '/>';
                if ($isClosing) {
                    if ($skipDepth > 0) $skipDepth--;
                } elseif (!$selfClosing) {
                    $skipDepth++;
                }
            }
            continue;
        }

        if ($skipDepth > 0) continue;

        foreach ($pending as $id => $info) {
            $limit = $info['remaining'] === null ? -1 : $info['remaining'];

            // A term's definition text can itself contain another term's
            // name (e.g. the "IL-6" definition mentions "cytokine"). Once a
            // match is wrapped, its <span data-df-definition="..."> is part
            // of $token — if a later term's pattern were run against that
            // same $token, it could match inside the attribute we just
            // wrote and nest a second <span> inside it, corrupting the
            // markup. Swapping in an opaque placeholder here (restored only
            // after every term has been tried on this token) keeps already-
            // wrapped spans out of reach of subsequent patterns.
            $replaced = preg_replace_callback(
                $info['pattern'],
                function ($matches) use ($id, $info, &$placeholders) {
                    $label = hsc($matches[0]);
                    $def   = hsc($info['definition']);
                    $span  = '<span class="df-term" tabindex="0" role="button" data-df-id="' . hsc($id) . '" data-df-definition="' . $def . '">' . $label . '</span>';
                    $key   = "\x00DF" . count($placeholders) . "\x00";
                    $placeholders[$key] = $span;
                    return $key;
                },
                $token,
                $limit,
                $count
            );

            if ($count > 0 && $replaced !== null) {
                $token = $replaced;
                if ($info['remaining'] !== null) {
                    $pending[$id]['remaining'] -= $count;
                    if ($pending[$id]['remaining'] <= 0) {
                        unset($pending[$id]);
                    }
                }
            }
        }
    }
    unset($token);

    $result = $before . implode('', $tokens) . $after;
    if ($placeholders) {
        $result = strtr($result, $placeholders);
    }

    return $result;
}

/**
 * <link>/<script> tags for the tooltip widget, injected once per page only
 * when at least one term was actually wrapped — pages without a glossary
 * hit never pay for the asset requests.
 */
function df_render_assets(): string
{
    $cssUrl = df_plugin_asset_url('assets/css/definitions-front.css');
    $jsUrl  = df_plugin_asset_url('assets/js/definitions-front.js');

    $html  = '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl) . '">' . "\n";
    $html .= '<script src="' . htmlspecialchars($jsUrl) . '" defer></script>' . "\n";

    $jsStrings = [
        'close' => df_t('df_close', 'Close', 'front'),
    ];
    $html .= '<script type="application/json" data-window-var="DF_LANG">' . json_encode($jsStrings, JSON_UNESCAPED_UNICODE) . '</script>' . "\n";

    return $html;
}

/**
 * Full front-end pipeline for one page: term-wrapping (cached) + fresh
 * asset injection. This is the only function definitions-init.php's output
 * buffer callback calls — see its own comment for why the buffer has to
 * stay registered before the core's page-cache short-circuit, and why that
 * makes the wrapping step run on every request, cache hit included.
 *
 * The expensive part (tokenizing <body>, regex-matching every term) is
 * cached on a hash of the raw input + the current term list: identical
 * input always produces identical wrapped output, so a repeat hit — the
 * overwhelming majority of traffic on a page-cached site — is a file read,
 * not a re-scan. Asset tags are re-injected on every call regardless of
 * cache state: building them is a cheap string concat plus a filemtime()
 * stat, and caching them would risk serving a stale ?v= after a CSS/JS
 * update until the page's own content or the glossary next changed.
 */
function df_render_page(string $html, array $terms, bool $globalWrapAllDefault = false, int $globalMaxOccurrences = 1, bool $skipHeadings = false): string
{
    if (empty($terms)) return $html;

    $fingerprint = md5(serialize([
        'wrapAll'  => $globalWrapAllDefault,
        'maxOcc'   => $globalMaxOccurrences,
        'skipH'    => $skipHeadings,
        'terms'    => array_map(
            fn($t) => [
                $t['term'] ?? '',
                $t['definition'] ?? '',
                !empty($t['enabled']),
                $t['variants'] ?? [],
                $t['wrap_all'] ?? null,
                $t['max_occurrences'] ?? null,
            ],
            $terms
        ),
    ]));
    $cacheKey = sha1($html . "\x00" . $fingerprint);

    $wrapped = df_render_cache_get($cacheKey);
    if ($wrapped === null) {
        $wrapped = df_wrap_terms($html, $terms, $globalWrapAllDefault, $globalMaxOccurrences, $skipHeadings);
        df_render_cache_set($cacheKey, $wrapped);
    }

    if ($wrapped === $html) {
        return $html;
    }

    $assets = df_render_assets();
    if (stripos($wrapped, '</head>') !== false) {
        return preg_replace('/<\/head>/i', $assets . '</head>', $wrapped, 1);
    }
    return $assets . $wrapped;
}
