<?php
header('Content-Type: application/json');
require __DIR__ . '/config.php';
require __DIR__ . '/notifications-helper.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST') {
    if ($action === 'subscribe') {
        $data = json_decode(file_get_contents('php://input'), true);
        $endpoint = $data['subscription']['endpoint'] ?? null;
        $auth = $data['subscription']['keys']['auth'] ?? null;
        $p256dh = $data['subscription']['keys']['p256dh'] ?? null;
        $squadrons = $data['squadrons'] ?? [];

        if ($endpoint && $auth && $p256dh) {
            addSubscription($endpoint, $auth, $p256dh, $squadrons);
            echo json_encode(['success' => true, 'message' => 'Subscribed to notifications']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing subscription data']);
        }
    } elseif ($action === 'unsubscribe') {
        $data = json_decode(file_get_contents('php://input'), true);
        $endpoint = $data['endpoint'] ?? null;

        if ($endpoint) {
            $subs = loadSubscriptions();
            $subs['subscriptions'] = array_values(array_filter(
                $subs['subscriptions'],
                fn($sub) => $sub['endpoint'] !== $endpoint
            ));
            saveSubscriptions($subs);
            echo json_encode(['success' => true, 'message' => 'Unsubscribed']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing endpoint']);
        }
    } elseif ($action === 'update_preferences') {
        $data = json_decode(file_get_contents('php://input'), true);
        $endpoint = $data['endpoint'] ?? null;
        $squadrons = $data['squadrons'] ?? [];

        if ($endpoint) {
            $subs = loadSubscriptions();
            foreach ($subs['subscriptions'] as &$sub) {
                if ($sub['endpoint'] === $endpoint) {
                    $sub['squadrons'] = $squadrons;
                    break;
                }
            }
            unset($sub);
            saveSubscriptions($subs);
            echo json_encode(['success' => true, 'message' => 'Preferences updated']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing endpoint']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
