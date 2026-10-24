<?php
require_once __DIR__ . '/vapid-config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

try {
    $vapid = loadVapidConfiguration();
    echo json_encode([
        'success' => true,
        'publicKey' => $vapid['publicKey'],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[push] VAPID configuration is unavailable: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Push notifications are not configured. Contact the site administrator.',
    ]);
}
