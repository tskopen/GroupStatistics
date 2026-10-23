<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/config.php';
require __DIR__ . '/notifications-helper.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

if ($method !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

// This API mutates subscription state and can trigger device notifications.
// All callers must send the session token embedded by notification-preferences.php.
$expectedToken = (string) ($_SESSION['_csrf'] ?? '');
$providedToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Session security token expired. Reload the page and try again.']);
    exit;
}

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit;
}

try {
    $db = getDb();

    if ($action === 'test_notification') {
        $endpoint = $data['endpoint'] ?? null;
        if (!is_string($endpoint) || trim($endpoint) === '' || !isAllowedPushEndpoint($endpoint)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing or invalid subscription endpoint']);
            exit;
        }

        // Limit user-triggered test pushes so this endpoint cannot be used to spam a device.
        $lastTestAt = (int) ($_SESSION['_notification_test_at'] ?? 0);
        if (time() - $lastTestAt < 15) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Please wait 15 seconds between test notifications.']);
            exit;
        }

        $stmt = $db->prepare(
            'SELECT endpoint,auth,p256dh FROM notification_subscriptions WHERE endpoint = ?'
        );
        $stmt->execute([$endpoint]);
        $target = $stmt->fetch();
        if (!$target) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Subscription not found. Re-enable notifications and try again.']);
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

        $_SESSION['_notification_test_at'] = time();
        $result = sendViaWebPush($target['endpoint'], $target['auth'], $target['p256dh'], $payload);
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Push service accepted the test notification. Check the device to confirm display.',
            ]);
        } else {
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'Delivery failed. Check the application logs for a hashed subscription reference and HTTP result.']);
        }
        exit;
    }

    if ($action === 'subscribe') {
        $subscription = $data['subscription'] ?? null;
        $endpoint = is_array($subscription) ? ($subscription['endpoint'] ?? null) : null;
        $auth = is_array($subscription) ? ($subscription['keys']['auth'] ?? null) : null;
        $p256dh = is_array($subscription) ? ($subscription['keys']['p256dh'] ?? null) : null;
        $squadrons = $data['squadrons'] ?? [];
        if (!is_string($endpoint) || !is_string($auth) || !is_string($p256dh) || !is_array($squadrons)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing or invalid subscription data']);
            exit;
        }

        $normalizedSquadrons = normalizeNotificationSquadrons($squadrons);
        $allScores = array_key_exists('all_scores', $data)
            ? filter_var($data['all_scores'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : empty($normalizedSquadrons);
        if ($allScores === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'all_scores must be true or false']);
            exit;
        }

        if (!addSubscription($endpoint, $auth, $p256dh, $normalizedSquadrons, $allScores)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Subscription was rejected. Check browser support and push-service endpoint.']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Subscribed to notifications']);
        exit;
    }

    if ($action === 'get_preferences') {
        $endpoint = $data['endpoint'] ?? null;
        if (!is_string($endpoint) || trim($endpoint) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing subscription endpoint']);
            exit;
        }

        $stmt = $db->prepare('SELECT squadrons_json,all_scores FROM notification_subscriptions WHERE endpoint = ?');
        $stmt->execute([$endpoint]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Subscription not found']);
            exit;
        }

        $squadrons = json_decode($row['squadrons_json'] ?? '[]', true);
        echo json_encode([
            'success' => true,
            'all_scores' => (bool) $row['all_scores'],
            'squadrons' => is_array($squadrons) ? array_map('intval', $squadrons) : [],
        ]);
        exit;
    }

    if ($action === 'unsubscribe') {
        $endpoint = $data['endpoint'] ?? null;
        if (!is_string($endpoint) || trim($endpoint) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing subscription endpoint']);
            exit;
        }

        $stmt = $db->prepare('DELETE FROM notification_subscriptions WHERE endpoint = ?');
        $stmt->execute([$endpoint]);
        // Treat already-absent rows as success; this makes unsubscribe/recovery idempotent.
        echo json_encode(['success' => true, 'removed' => $stmt->rowCount() > 0, 'message' => 'Server subscription cleared']);
        exit;
    }

    if ($action === 'update_preferences') {
        $endpoint = $data['endpoint'] ?? null;
        $squadrons = $data['squadrons'] ?? [];
        if (!is_string($endpoint) || trim($endpoint) === '' || !is_array($squadrons)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing or invalid preferences data']);
            exit;
        }

        $allScores = array_key_exists('all_scores', $data)
            ? filter_var($data['all_scores'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : empty($squadrons);
        if ($allScores === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'all_scores must be true or false']);
            exit;
        }

        $squadrons = normalizeNotificationSquadrons($squadrons);
        $stmt = $db->prepare(
            'UPDATE notification_subscriptions
             SET squadrons_json = ?, all_scores = ?, last_active = ?
             WHERE endpoint = ?'
        );
        $stmt->execute([json_encode($squadrons, JSON_THROW_ON_ERROR), $allScores ? 1 : 0, date('c'), $endpoint]);

        if ($stmt->rowCount() === 0) {
            $check = $db->prepare('SELECT 1 FROM notification_subscriptions WHERE endpoint = ?');
            $check->execute([$endpoint]);
            if (!$check->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Subscription not found. Re-enable notifications and try again.']);
                exit;
            }
        }

        echo json_encode(['success' => true, 'message' => 'Preferences updated']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('[notifications] Subscription API failure: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update notification subscription']);
}
