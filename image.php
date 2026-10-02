<?php
require __DIR__ . '/config.php';

$file=isset($_GET['file']) ? basename((string)$_GET['file']) : '';
if ($file==='' || !preg_match('/^[A-Za-z0-9._-]+$/',$file)) { http_response_code(400); exit; }
$path=IMAGES_DIR.'/'.$file;
if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit; }

$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
$allowed=['image/png','image/jpeg','image/gif','image/webp'];
if (!in_array($mime,$allowed,true)) { http_response_code(415); exit; }

header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400, immutable');
readfile($path);
