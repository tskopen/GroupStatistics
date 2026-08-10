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

header('Content-Type: application/json');

echo json_encode([
    'success' => true
]);
