<?php

declare(strict_types=1);

$url = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $url;
$docRoot = realpath(__DIR__);
$realFile = is_file($file) ? realpath($file) : false;

if (
    $url !== '/'
    && $docRoot !== false
    && $realFile !== false
    && ($realFile === $docRoot || str_starts_with($realFile, $docRoot . DIRECTORY_SEPARATOR))
) {
    $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'mjs' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
    ];

    if (isset($types[$ext])) {
        $isVersionedAsset = str_starts_with($url, '/assets/');
        $maxAge = $isVersionedAsset || in_array($ext, ['css', 'js', 'mjs', 'woff', 'woff2'], true)
            ? 31536000
            : 2592000;

        header('Content-Type: ' . $types[$ext]);
        header('Content-Length: ' . (string) filesize($realFile));
        header('Cache-Control: public, max-age=' . $maxAge . ($maxAge >= 31536000 ? ', immutable' : ''));
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($realFile)) . ' GMT');
        readfile($realFile);
        return true;
    }

    // Andere existierende Dateien dem Built-in-Server überlassen
    return false;
}

require __DIR__ . '/index.php';
