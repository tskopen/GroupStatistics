<?php
/**
 * Serves uploaded squadron icons from the persistent /data/images
 * directory. Images live outside the webroot (on the Railway volume),
 * so they must be streamed through PHP rather than linked directly.
 */
require __DIR__ . '/config.php';

$file = isset($_GET['file']) ? basename($_GET['file']) : '';

if ($file === '' || strpos($file, '..') !== false) {
    http_response_code(400);
    exit;
}

$path = IMAGES_DIR . '/' . $file;

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeTypes = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'webp' => 'image/webp',
];

$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
readfile($path);
