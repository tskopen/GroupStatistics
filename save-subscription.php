<?php

require_once 'notifications-helper.php';

$data = json_decode(
    file_get_contents('php://input'),
    true
);

if (!$data) {
    http_response_code(400);
    exit;
}

addSubscription(
    $data['endpoint'],
    $data['keys']['auth'],
    $data['keys']['p256dh']
);

error_log('[PUSH-DIAG] Subscription saved: endpoint=' . substr($data['endpoint'] ?? '', 0, 60) . '... auth_len=' . strlen($data['keys']['auth'] ?? '') . ' p256dh_len=' . strlen($data['keys']['p256dh'] ?? ''));

header('Content-Type: application/json');

echo json_encode([
    'success' => true
]);
