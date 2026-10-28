<?php
require __DIR__ . '/config.php';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit; }
$stmt = getDb()->prepare('SELECT p.filename, a.status FROM engagement_award_photos p JOIN engagement_awards a ON a.id=p.award_id WHERE p.id=?');
$stmt->execute([$id]);
$photo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$photo || ($photo['status'] !== 'published' && empty($_SESSION['admin']))) { http_response_code(404); exit; }
$filename = basename((string)$photo['filename']);
if (!preg_match('/^award-[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) { http_response_code(404); exit; }
$path = DATA_DIR . '/award-images/' . $filename;
if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit; }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowed, true)) { http_response_code(415); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: ' . ($photo['status'] === 'published' ? 'public, max-age=3600' : 'private, no-store'));
readfile($path);
