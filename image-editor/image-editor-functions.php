<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }

if (defined('IE_FUNCTIONS_LOADED')) return;
define('IE_FUNCTIONS_LOADED', true);

const IE_MAX_UPLOAD_BYTES = 40 * 1024 * 1024;
const IE_MAX_PIXELS       = 100000000;
const IE_EDITABLE_EXT     = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

function ie_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = [];
        $file   = dirname(__DIR__, 2) . '/config.json';
        if (is_file($file)) {
            $decoded = json_decode((string)file_get_contents($file), true);
            if (is_array($decoded)) $config = $decoded;
        }
    }
    return $config;
}

function ie_strings(): array
{
    static $strings = null;
    if ($strings !== null) return $strings;

    $config = ie_config();
    $locale = preg_replace('/[^a-z]/', '', strtolower((string)($config['admin_language'] ?? $config['active_language'] ?? 'en')));
    $strings = [];
    foreach (['en', $locale] as $code) {
        $path = __DIR__ . '/lang/' . $code . '.json';
        if ($code !== '' && is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) $strings = array_merge($strings, $decoded);
        }
    }
    return $strings;
}

function ie_t(string $key, string $fallback = ''): string
{
    $strings = ie_strings();
    return isset($strings[$key]) ? (string)$strings[$key] : ($fallback !== '' ? $fallback : $key);
}

function ie_file_manager_footer($html, $currentPath = '')
{
    $webp   = function_exists('imagewebp');
    $ver    = (string)@filemtime(__DIR__ . '/assets/image-editor.js');
    $verCss = (string)@filemtime(__DIR__ . '/assets/image-editor.css');
    $config = [
        'csrf'    => (string)($_SESSION['csrf_token'] ?? ''),
        'path'    => (string)$currentPath,
        'webp'    => $webp,
        'strings' => ie_strings(),
    ];
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return (string)$html
        . "\n" . '<link rel="stylesheet" href="../plugins/image-editor/assets/image-editor.css?v=' . hsc($verCss) . '">'
        . "\n" . '<script type="application/json" id="ie-config">' . $json . '</script>'
        . "\n" . '<script src="../plugins/image-editor/assets/image-editor.js?v=' . hsc($ver) . '"></script>';
}

function ie_error(string $key): array
{
    return ['success' => false, 'message' => ie_t($key)];
}

function ie_file_manager_action($result, $action = '', $baseUploadPath = '../files/')
{
    if ($action !== 'ie_save') return $result;

    if (!function_exists('admin_is_logged_in') || !admin_is_logged_in()) {
        http_response_code(403);
        return ie_error('ie_err_auth');
    }

    $baseReal = realpath($baseUploadPath);
    if ($baseReal === false) return ie_error('ie_err_path');

    $relDir = trim(str_replace('\\', '/', (string)($_POST['path'] ?? '')), '/');
    if ($relDir !== '' && !preg_match('#^[A-Za-z0-9_\-./ ]+$#', $relDir)) return ie_error('ie_err_path');
    if (strpos($relDir, '..') !== false) return ie_error('ie_err_path');

    $dirReal = realpath($baseReal . ($relDir !== '' ? '/' . $relDir : ''));
    if ($dirReal === false || ($dirReal !== $baseReal && strpos($dirReal, $baseReal . DIRECTORY_SEPARATOR) !== 0) || !is_dir($dirReal) || !is_writable($dirReal)) {
        return ie_error('ie_err_path');
    }

    $original    = basename((string)($_POST['name'] ?? ''));
    $originalExt = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($original === '' || !in_array($originalExt, IE_EDITABLE_EXT, true)) return ie_error('ie_err_type');

    $mode   = ($_POST['mode'] ?? '') === 'replace' ? 'replace' : 'copy';
    $format = (string)($_POST['format'] ?? 'png');
    if (!in_array($format, ['png', 'jpeg', 'webp'], true)) return ie_error('ie_err_format');
    if ($format === 'webp' && !function_exists('imagewebp')) return ie_error('ie_err_format');
    $quality = max(40, min(100, (int)($_POST['quality'] ?? 90)));
    $ext     = $format === 'jpeg' ? 'jpg' : $format;

    $upload = $_FILES['ie_image'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        return ie_error('ie_err_upload');
    }
    if ($upload['size'] > IE_MAX_UPLOAD_BYTES) return ie_error('ie_err_size');

    $info = @getimagesize($upload['tmp_name']);
    if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) return ie_error('ie_err_upload');
    if ($info[0] * $info[1] > IE_MAX_PIXELS) return ie_error('ie_err_size');

    if ($mode === 'replace') {
        $sameFamily = ($ext === 'jpg' && in_array($originalExt, ['jpg', 'jpeg'], true)) || $ext === $originalExt;
        if (!$sameFamily) return ie_error('ie_err_replace_format');
        $targetName = $original;
        $targetPath = $dirReal . '/' . $targetName;
        if (!is_file($targetPath)) return ie_error('ie_err_path');
    } else {
        $stem = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo((string)($_POST['new_name'] ?? ''), PATHINFO_FILENAME));
        $stem = trim((string)$stem, '_');
        if ($stem === '') $stem = pathinfo($original, PATHINFO_FILENAME) . '-edited';
        $stem = substr($stem, 0, 100);
        $targetName = $stem . '.' . $ext;
        $i = 1;
        while (file_exists($dirReal . '/' . $targetName)) {
            $targetName = $stem . '-' . $i . '.' . $ext;
            $i++;
        }
        $targetPath = $dirReal . '/' . $targetName;
    }

    $raw = file_get_contents($upload['tmp_name']);
    $img = $raw === false ? false : @imagecreatefromstring($raw);
    unset($raw);
    if ($img === false) return ie_error('ie_err_upload');

    $w = imagesx($img);
    $h = imagesy($img);
    if ($format === 'jpeg') {
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        $img = $flat;
    } else {
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }

    $tmpPath = $dirReal . '/.ie-' . bin2hex(random_bytes(8)) . '.tmp';
    $ok = false;
    if ($format === 'jpeg')      $ok = imagejpeg($img, $tmpPath, $quality);
    elseif ($format === 'webp')  $ok = imagewebp($img, $tmpPath, $quality);
    else                         $ok = imagepng($img, $tmpPath, (int)round((100 - $quality) / 11));

    if (!$ok || !is_file($tmpPath) || !rename($tmpPath, $targetPath)) {
        @unlink($tmpPath);
        imagedestroy($img);
        return ie_error('ie_err_write');
    }
    @chmod($targetPath, 0644);

    $config = ie_config();
    if (!empty($config['create_thumbnails']) && function_exists('ensureThumbnailsDir') && function_exists('createThumbnail')) {
        $thumbsDir = ensureThumbnailsDir($dirReal);
        if ($thumbsDir) {
            $type = $format === 'jpeg' ? IMAGETYPE_JPEG : ($format === 'webp' ? IMAGETYPE_WEBP : IMAGETYPE_PNG);
            createThumbnail(
                $img,
                $thumbsDir . '/thumb_' . $targetName,
                (int)($config['thumb_width'] ?? 300),
                (int)($config['thumb_height'] ?? 300),
                $type,
                $quality
            );
        }
    }
    imagedestroy($img);

    return ['success' => true, 'name' => $targetName, 'message' => ie_t('ie_saved')];
}
