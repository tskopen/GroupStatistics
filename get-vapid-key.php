<?php
require_once __DIR__ . '/vapid-config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$keys = loadVapidKeys();
if ($keys === null) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Push notifications are not configured on the server. Please contact the administrator.',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'publicKey' => $keys['publicKey'],
], JSON_UNESCAPED_SLASHES);
