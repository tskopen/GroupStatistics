<?php
header('Content-Type: application/json');
require __DIR__ . '/config.php';
require __DIR__ . '/notifications-helper.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Client-side diagnostics: send a test push to the requesting device only.
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
        'data' => ['type' => 'test_notification', 'squadron_id' => 0, 'url' => '/index.php']
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

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

try {
    $db = getDb();

    if ($action === 'subscribe') {
        $endpoint = $data['subscription']['endpoint'] ?? null;
        $auth = $data['subscription']['keys']['auth'] ?? null;
        $p256dh = $data['subscription']['keys']['p256dh'] ?? null;
        $squadrons = $data['squadrons'] ?? [];

        if (!is_string($endpoint) || !is_string($auth) || !is_string($p256dh) || !is_array($squadrons)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing or invalid subscription data']);
            exit;
        }

        if (!addSubscription($endpoint, $auth, $p256dh, $squadrons)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Subscription data was rejected']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Subscribed to notifications']);
    } elseif ($action === 'unsubscribe') {
        $endpoint = $data['endpoint'] ?? null;
        if (!is_string($endpoint) || trim($endpoint) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing endpoint']);
            exit;
        }

        $stmt = $db->prepare('DELETE FROM notification_subscriptions WHERE endpoint = ?');
        $stmt->execute([$endpoint]);
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Subscription not found']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Unsubscribed']);
    } elseif ($action === 'update_preferences') {
        $endpoint = $data['endpoint'] ?? null;
        $squadrons = $data['squadrons'] ?? [];
        if (!is_string($endpoint) || trim($endpoint) === '' || !is_array($squadrons)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing or invalid preferences data']);
            exit;
        }

        // Store only positive integer squadron IDs; an empty list means all scores.
        $squadrons = array_values(array_unique(array_filter(
            $squadrons,
            static fn($id) => (is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id > 0
        )));
        $allScores = empty($squadrons) ? 1 : 0;
        $stmt = $db->prepare('UPDATE notification_subscriptions SET squadrons_json = ?, all_scores = ?, last_active = ? WHERE endpoint = ?');
        $stmt->execute([json_encode($squadrons), $allScores, date('c'), $endpoint]);

        if ($stmt->rowCount() === 0) {
            // SQLite reports zero changed rows if the values were already identical.
            $check = $db->prepare('SELECT 1 FROM notification_subscriptions WHERE endpoint = ?');
            $check->execute([$endpoint]);
            if (!$check->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Subscription not found']);
                exit;
            }
        }

        echo json_encode(['success' => true, 'message' => 'Preferences updated']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('[notifications] Subscription API failure: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update notification subscription']);
}
