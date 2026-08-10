<?php
header('Content-Type: application/json');
require __DIR__ . '/config.php';
require __DIR__ . '/notifications-helper.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Client-side diagnostics: allows a subscribed user to send an immediate
// test push to themselves to verify end-to-end delivery, without waiting
// for a real score event or going through the admin panel.
if ($method === 'POST' && $action === 'test_notification') {
    $data = json_decode(file_get_contents('php://input'), true);
    $endpoint = $data['endpoint'] ?? null;

    if (!$endpoint) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing endpoint']);
        exit;
    }

    $subs = loadSubscriptions();
    $target = null;

    foreach ($subs['subscriptions'] as $sub) {
        if ($sub['endpoint'] === $endpoint) {
            $target = $sub;
            break;
        }
    }

    if (!$target) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Subscription not found on server']);
        exit;
    }

    require __DIR__ . '/push-service.php';

    $payload = [
        'title' => 'Test Notification',
        'body' => 'This is a test notification triggered from your device.',
        'icon' => '/pwa-icon.php?size=192',
        'badge' => '/pwa-icon.php?size=192',
        'tag' => 'user-test-' . time(),
        'data' => [
            'type' => 'test_notification',
            'squadron_id' => 0,
            'url' => '/index.php'
        ]
    ];

    $ok = sendViaWebPush($target['endpoint'], $target['auth'], $target['p256dh'], $payload);

    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Test notification sent']);
    } else {
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'Delivery failed — check Railway logs for "[push]" entries']);
    }
    exit;
}

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
