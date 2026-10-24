<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/config.php';
require __DIR__ . '/notifications-helper.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$action = $_GET['action'] ?? '';

if ($method !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// The settings page does not require an account, but cross-origin pages may
// not mutate subscriptions or trigger pushes for a visitor's browser.
if (!isSameOriginNotificationRequest()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Request origin is not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

if ($action === 'test_notification') {
    $endpoint = $data['endpoint'] ?? null;
    if (!is_string($endpoint) || trim($endpoint) === '' || strlen($endpoint) > 2048) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing or invalid endpoint']);
        exit;
    }

    $stmt = getDb()->prepare('SELECT endpoint, auth, p256dh FROM notification_subscriptions WHERE endpoint = ?');
    $stmt->execute([$endpoint]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Subscription not found on server']);
        exit;
    }

    require_once __DIR__ . '/push-service.php';
    $payload = [
        'title' => 'Test Notification',
        'body' => 'This is a test notification triggered from your device.',
        'icon' => '/assets/pwa-icon-192.png',
        'badge' => '/assets/pwa-icon-192.png',
        'tag' => 'user-test-' . bin2hex(random_bytes(8)),
        'data' => ['type' => 'test_notification', 'squadron_id' => 0, 'url' => '/index.php'],
    ];

    $result = sendPushNotifications([[
        'endpoint' => $target['endpoint'],
        'auth' => $target['auth'],
        'p256dh' => $target['p256dh'],
        'payload' => $payload,
    ]]);

    if (($result['sent'] ?? 0) > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Accepted by the push service; check the device for delivery.',
        ]);
    } else {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => 'Push delivery failed. Check server logs for the delivery status.',
        ]);
    }
    exit;
}

try {
    $db = getDb();

    if ($action === 'subscribe') {
        $subscription = $data['subscription'] ?? null;
        $endpoint = is_array($subscription) ? ($subscription['endpoint'] ?? null) : null;
        $auth = is_array($subscription) ? ($subscription['keys']['auth'] ?? null) : null;
        $p256dh = is_array($subscription) ? ($subscription['keys']['p256dh'] ?? null) : null;
        $squadrons = $data['squadrons'] ?? [];
        $allScores = $data['all_scores'] ?? null;

        if (
            !is_string($endpoint) || !is_string($auth) || !is_string($p256dh) ||
            !is_array($squadrons) || ($allScores !== null && !is_bool($allScores))
        ) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing or invalid subscription data']);
            exit;
        }

        if (!addSubscription($endpoint, $auth, $p256dh, $squadrons, $allScores)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Subscription data was rejected']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Subscribed to notifications']);
    } elseif ($action === 'unsubscribe') {
        $endpoint = $data['endpoint'] ?? null;
        if (!is_string($endpoint) || trim($endpoint) === '' || strlen($endpoint) > 2048) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing or invalid endpoint']);
            exit;
        }

        $stmt = $db->prepare('DELETE FROM notification_subscriptions WHERE endpoint = ?');
        $stmt->execute([$endpoint]);
        // Idempotent so repeated cleanup succeeds even after a prior partial failure.
        echo json_encode(['success' => true, 'message' => 'Unsubscribed']);
    } elseif ($action === 'get_preferences') {
        $endpoint = $data['endpoint'] ?? null;
        if (!is_string($endpoint) || trim($endpoint) === '' || strlen($endpoint) > 2048) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing or invalid endpoint']);
            exit;
        }

        $stmt = $db->prepare('SELECT squadrons_json, all_scores FROM notification_subscriptions WHERE endpoint = ?');
        $stmt->execute([$endpoint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Subscription not found']);
            exit;
        }

        $squadrons = json_decode($row['squadrons_json'] ?? '[]', true);
        echo json_encode([
            'success' => true,
            'squadrons' => is_array($squadrons) ? array_values(array_map('intval', $squadrons)) : [],
            'all_scores' => (bool)$row['all_scores'],
        ]);
    } elseif ($action === 'update_preferences') {
        $endpoint = $data['endpoint'] ?? null;
        $squadrons = $data['squadrons'] ?? null;
        $allScores = $data['all_scores'] ?? null;

        if (
            !is_string($endpoint) || trim($endpoint) === '' || strlen($endpoint) > 2048 ||
            !is_array($squadrons) || !is_bool($allScores)
        ) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing or invalid preferences data']);
            exit;
        }

        $normalized = [];
        foreach ($squadrons as $id) {
            if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int)$id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Squadron IDs must be positive integers']);
                exit;
            }
            $normalized[] = (int)$id;
        }
        $normalized = array_values(array_unique($normalized));

        $validSquadrons = array_map('intval', $db->query('SELECT id FROM squadrons')->fetchAll(PDO::FETCH_COLUMN));
        if (array_diff($normalized, $validSquadrons)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A selected squadron does not exist']);
            exit;
        }

        $stmt = $db->prepare('UPDATE notification_subscriptions SET squadrons_json = ?, all_scores = ?, last_active = ? WHERE endpoint = ?');
        $stmt->execute([json_encode($normalized), $allScores ? 1 : 0, date('c'), $endpoint]);
        if ($stmt->rowCount() === 0) {
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
    error_log('[notifications] Subscription API failure: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update notification subscription']);
}
