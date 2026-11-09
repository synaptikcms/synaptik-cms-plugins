<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

if (defined('AN_AI_LOADED')) return;
define('AN_AI_LOADED', true);

require_once __DIR__ . '/analytics-functions.php';

const AN_AI_EXPORT_MAX_DAYS = 90;
const AN_AI_EXPORT_CACHE_TTL = 1800;

function an_ai_bots(): array
{
    return [
        'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'Claude-User', 'anthropic-ai',
        'PerplexityBot', 'Perplexity-User', 'Google-CloudVertexBot', 'Gemini-Deep-Research', 'CCBot', 'Bytespider',
        'meta-externalagent', 'meta-externalfetcher', 'Amazonbot', 'DuckAssistBot', 'MistralAI-User', 'cohere-ai',
        'YouBot', 'Applebot', 'Diffbot', 'Timpibot', 'PetalBot',
    ];
}

function an_ai_bot_name(string $ua): string
{
    if ($ua === '') return '';
    foreach (an_ai_bots() as $bot) {
        if (stripos($ua, $bot) !== false) return $bot;
    }
    return '';
}

function an_ai_bots_dir(): string
{
    return an_data_dir() . '/ai-bots';
}

function an_record_ai_bot(string $bot): void
{
    $dir = an_ai_bots_dir();
    if (!is_file(an_data_dir() . '/.htaccess')) an_ensure_dirs();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return;
    $fp = @fopen($dir . '/' . date('Y-m') . '.json', 'c+');
    if (!$fp) return;
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }
    $raw = stream_get_contents($fp);
    $data = json_decode($raw ?: '[]', true) ?: [];
    $day = date('Y-m-d');
    $data[$day][$bot] = ($data[$day][$bot] ?? 0) + 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if (mt_rand(1, 200) === 1) {
        $limit = date('Y-m', strtotime('-13 months'));
        foreach (glob($dir . '/????-??.json') ?: [] as $f) {
            if (basename($f, '.json') < $limit) @unlink($f);
        }
    }
}

function an_ai_export_token_file(): string
{
    return an_private_dir() . '/ai-export.token';
}

function an_ai_export_token(): string
{
    $file = an_ai_export_token_file();
    if (!is_file($file)) return '';
    $token = trim((string)file_get_contents($file));
    return strlen($token) === 64 ? $token : '';
}

function an_ai_export_enable(): string
{
    $token = bin2hex(random_bytes(32));
    $tmp = an_ai_export_token_file() . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $token, LOCK_EX) === false || !rename($tmp, an_ai_export_token_file())) return '';
    return $token;
}

function an_ai_export_disable(): void
{
    @unlink(an_ai_export_token_file());
}

function an_ai_export_payload(int $days): array
{
    require_once __DIR__ . '/analytics-data-layer.php';
    $days = max(1, min(AN_AI_EXPORT_MAX_DAYS, $days));
    $cacheFile = an_cache_dir() . '/ai-export-' . $days . '.json';
    if (is_file($cacheFile) && time() - filemtime($cacheFile) < AN_AI_EXPORT_CACHE_TTL) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    $totalsByDay = [];
    for ($i = $days - 1; $i >= 0; $i--) $totalsByDay[date('Y-m-d', strtotime("-{$i} days"))] = 0;
    $aiBySource = [];
    $aiByDay = [];
    $aiPages = [];
    $aiRefs = [];
    $views = 0;
    $aiViews = 0;
    foreach (an_load_hits($days) as $h) {
        $day = date('Y-m-d', (int)($h['ts'] ?? 0));
        if (!isset($totalsByDay[$day])) continue;
        $views++;
        $totalsByDay[$day]++;
        $source = an_ai_source((string)($h['ref_host'] ?? ''), (string)($h['utm'] ?? ''));
        if ($source === '') continue;
        $aiViews++;
        $aiBySource[$source] = ($aiBySource[$source] ?? 0) + 1;
        $aiByDay[$day][$source] = ($aiByDay[$day][$source] ?? 0) + 1;
        $key = ($h['type'] ?? '') . '|' . ($h['slug'] ?? '');
        if (!isset($aiPages[$key])) {
            $aiPages[$key] = ['type' => (string)($h['type'] ?? ''), 'slug' => (string)($h['slug'] ?? ''), 'title' => (string)($h['title'] ?? ''), 'views' => 0, 'sources' => []];
        }
        $aiPages[$key]['views']++;
        $aiPages[$key]['sources'][$source] = ($aiPages[$key]['sources'][$source] ?? 0) + 1;
        if (!empty($h['ref_url'])) $aiRefs[$h['ref_url']] = ($aiRefs[$h['ref_url']] ?? 0) + 1;
    }
    arsort($aiBySource);
    usort($aiPages, fn($a, $b) => $b['views'] <=> $a['views']);
    arsort($aiRefs);
    $bots = [];
    $botsByDay = [];
    $firstDay = array_key_first($totalsByDay);
    foreach (glob(an_ai_bots_dir() . '/????-??.json') ?: [] as $f) {
        $data = json_decode((string)file_get_contents($f), true) ?: [];
        foreach ($data as $day => $counts) {
            if ($day < $firstDay || !is_array($counts)) continue;
            foreach ($counts as $bot => $n) {
                $bots[$bot] = ($bots[$bot] ?? 0) + (int)$n;
                $botsByDay[$day][$bot] = ($botsByDay[$day][$bot] ?? 0) + (int)$n;
            }
        }
    }
    arsort($bots);
    ksort($botsByDay);
    $refs = [];
    foreach (array_slice($aiRefs, 0, 50, true) as $url => $n) $refs[] = ['url' => (string)$url, 'views' => $n];
    $payload = [
        'version'   => 1,
        'generated' => time(),
        'days'      => $days,
        'site'      => an_site_base_url(),
        'totals'    => ['views' => $views, 'by_day' => $totalsByDay],
        'ai'        => ['views' => $aiViews, 'by_source' => $aiBySource, 'by_day' => $aiByDay, 'pages' => array_slice($aiPages, 0, 50), 'referrers' => $refs],
        'bots'      => ['by_bot' => $bots, 'by_day' => $botsByDay],
    ];
    an_ensure_dirs();
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        rename($tmp, $cacheFile);
    }
    return $payload;
}

function an_ai_export_handle(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $token = an_ai_export_token();
    $given = (string)($_SERVER['HTTP_X_AN_TOKEN'] ?? '');
    if ($token === '' || $given === '' || !hash_equals($token, $given)) {
        http_response_code($token === '' ? 404 : 403);
        echo json_encode(['error' => $token === '' ? 'disabled' : 'forbidden']);
        exit;
    }
    echo json_encode(an_ai_export_payload((int)($_GET['days'] ?? 90)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
