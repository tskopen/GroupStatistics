<?php
/**
 * Helpers for managing PWA push notification subscriptions and building
 * notification payloads for score updates.
 *
 * Subscriptions and preferences are stored in
 * /data/notification-subscriptions.json (see config.php for the shared
 * DATA_DIR convention).
 */

// Load subscriptions
function loadSubscriptions() {
    $path = (getenv('DATA_DIR') ?: '/data') . '/notification-subscriptions.json';
    if (!file_exists($path)) {
        return ['subscriptions' => []];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : ['subscriptions' => []];
}

// Save subscriptions
function saveSubscriptions($data) {
    $path = (getenv('DATA_DIR') ?: '/data') . '/notification-subscriptions.json';
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Add or update subscription
function addSubscription($endpoint, $auth, $p256dh, $squadrons = []) {
    $data = loadSubscriptions();
    $found = false;

    foreach ($data['subscriptions'] as &$sub) {
        if ($sub['endpoint'] === $endpoint) {
            $sub['squadrons'] = $squadrons;
            $sub['last_active'] = date('c');
            $found = true;
            break;
        }
    }
    unset($sub);

    if (!$found) {
        $data['subscriptions'][] = [
            'endpoint' => $endpoint,
            'auth' => $auth,
            'p256dh' => $p256dh,
            'squadrons' => $squadrons,
            'all_scores' => count($squadrons) === 0,
            'subscribed_at' => date('c'),
            'last_active' => date('c'),
        ];
    }

    saveSubscriptions($data);
    return true;
}

// Send notification to matching subscriptions
function sendNotificationForScore($scoreData, $customMessage = null) {
    $data = loadSubscriptions();
    $squadronId = $scoreData['squadron_id'] ?? null;

    if (!$squadronId) return [];

    $notifications = [];
    $defaultBody = 'Squadron ' . $squadronId . ' scored ' . ($scoreData['value'] ?? 0) . ' points!';
    $body = !empty($customMessage) ? $customMessage : $defaultBody;

    foreach ($data['subscriptions'] as $sub) {
        // Send to users who follow this squadron or follow all scores
        if (empty($sub['squadrons']) || in_array($squadronId, $sub['squadrons'])) {
            $notifications[] = [
                'endpoint' => $sub['endpoint'],
                'auth' => $sub['auth'],
                'p256dh' => $sub['p256dh'],
                'payload' => [
                    'title' => 'Score Update',
                    'body' => $body,
                    'icon' => 'pwa-icon.php?size=192',
                    'badge' => 'pwa-icon.php?size=192',
                    'tag' => 'score-update-' . time(),
                    'data' => [
                        'type' => 'score_update',
                        'squadron_id' => $squadronId,
                        'url' => 'index.php'
                    ]
                ]
            ];
        }
    }

    return $notifications;
}

// Clean up expired subscriptions
function cleanupSubscriptions() {
    $data = loadSubscriptions();
    $cutoff = strtotime('-30 days');

    $data['subscriptions'] = array_values(array_filter(
        $data['subscriptions'],
        fn($sub) => strtotime($sub['last_active'] ?? $sub['subscribed_at']) > $cutoff
    ));

    saveSubscriptions($data);
}
