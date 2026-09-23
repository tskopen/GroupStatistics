<?php

require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| Subscription Storage
|--------------------------------------------------------------------------
*/

function loadSubscriptions(): array
{
    $path = (getenv('DATA_DIR') ?: '/data') . '/notification-subscriptions.json';

    if (!file_exists($path)) {
        return [
            'subscriptions' => []
        ];
    }

    $data = json_decode(file_get_contents($path), true);

    return is_array($data)
        ? $data
        : ['subscriptions' => []];
}

function saveSubscriptions(array $data): void
{
    $path = (getenv('DATA_DIR') ?: '/data') . '/notification-subscriptions.json';

    file_put_contents(
        $path,
        json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        )
    );
}

function addSubscription(
    string $endpoint,
    string $auth,
    string $p256dh,
    array $squadrons = []
): bool {

    $data = loadSubscriptions();

    $found = false;

    foreach ($data['subscriptions'] as &$sub) {

        if ($sub['endpoint'] === $endpoint) {

            $sub['auth'] = $auth;
            $sub['p256dh'] = $p256dh;
            $sub['squadrons'] = $squadrons;
            $sub['all_scores'] = count($squadrons) === 0;
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
            'last_active' => date('c')
        ];
    }

    saveSubscriptions($data);

    return true;
}

/*
|--------------------------------------------------------------------------
| Score Notification Sender
|--------------------------------------------------------------------------
*/

function sendNotificationForScore(
    array $scoreData,
    ?string $customMessage = null
): array {

    $squadronId = $scoreData['squadron_id'] ?? null;

    error_log('[PUSH-DIAG] sendNotificationForScore called: squadronId=' . $squadronId . ' value=' . ($scoreData['value'] ?? 0));

    $subscriptions = loadSubscriptions();
    error_log('[PUSH-DIAG] Loaded subscriptions: total=' . count($subscriptions['subscriptions'] ?? []));

    if (!$squadronId) {
        return [];
    }

    $scoreValue = $scoreData['value'] ?? 0;

    $body = $customMessage ?: (
        "Squadron {$squadronId} scored {$scoreValue} points!"
    );

    $payload = [
        'title' => 'Score Update',
        'body' => $body,
        'icon' => '/pwa-icon.php?size=192',
        'badge' => '/pwa-icon.php?size=192',
        'tag' => 'score-update-' . time(),
        'data' => [
            'type' => 'score_update',
            'squadron_id' => $squadronId,
            'url' => '/index.php'
        ]
    ];

    if (!isValidNotificationPayload($payload)) {
        error_log(
            '[notifications-helper] ⚠ Skipping send: incomplete payload for squadron ' .
            $squadronId . ': ' . json_encode($payload)
        );
        return [];
    }

    $matched = [];

    foreach ($subscriptions['subscriptions'] as $sub) {

        $followsAll = empty($sub['squadrons']);

        $followsSquadron = in_array(
            $squadronId,
            $sub['squadrons'] ?? []
        );

        if (!$followsAll && !$followsSquadron) {
            continue;
        }

        // Include all subscription fields required by push-service.php
        $matched[] = [
            'endpoint' => $sub['endpoint'],
            'auth' => $sub['auth'],
            'p256dh' => $sub['p256dh'],
            'payload' => $payload
        ];
    }

    error_log('[PUSH-DIAG] Sending to ' . count($matched) . ' matched subscribers for squadron ' . $squadronId);
    if (empty($matched)) {
        error_log('[PUSH-DIAG] ⚠ WARNING: No subscribers matched for squadron ' . $squadronId . '. Check if any subscriptions exist and if squadron filters are correct.');
    }

    return $matched;
}

/**
 * Validate a notification payload before it is handed off to push-service.php.
 *
 * Ensures 'title' and 'body' are non-empty strings, and that 'data' (when
 * present) is an array containing the fields required by the client's
 * notificationclick handler (type, squadron_id, url).
 *
 * @param array $payload
 * @return bool
 */
function isValidNotificationPayload(array $payload): bool
{
    $title = $payload['title'] ?? null;
    $body = $payload['body'] ?? null;

    if (!is_string($title) || trim($title) === '') {
        error_log('[notifications-helper] ⚠ Invalid payload: "title" must be a non-empty string');
        return false;
    }

    error_log('[PUSH-DIAG] Payload validation: title=' . ($title ? 'OK' : 'MISSING/EMPTY'));

    if (!is_string($body) || trim($body) === '') {
        error_log('[notifications-helper] ⚠ Invalid payload: "body" must be a non-empty string');
        return false;
    }

    error_log('[PUSH-DIAG] Payload validation: body=' . ($body ? 'OK' : 'MISSING/EMPTY'));

    $data = $payload['data'] ?? null;

    if (!is_array($data)) {
        error_log('[notifications-helper] ⚠ Invalid payload: "data" must be an object/array');
        return false;
    }

    error_log('[PUSH-DIAG] Payload validation: data=' . (is_array($data) ? 'OK' : 'MISSING/NOT_ARRAY'));

    $requiredDataFields = ['type', 'squadron_id', 'url'];

    foreach ($requiredDataFields as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            error_log("[notifications-helper] ⚠ Invalid payload: \"data.$field\" is missing or empty");
            return false;
        }
    }

    if (empty($requiredDataFields)) {
        error_log('[PUSH-DIAG] Payload validation: all data fields present ✓');
    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Cleanup Helpers
|--------------------------------------------------------------------------
*/

function removeExpiredSubscriptions(
    array $expiredEndpoints
): void {

    $data = loadSubscriptions();

    $data['subscriptions'] = array_values(
        array_filter(
            $data['subscriptions'],
            function ($sub) use ($expiredEndpoints) {
                return !in_array(
                    $sub['endpoint'],
                    $expiredEndpoints
                );
            }
        )
    );

    saveSubscriptions($data);
}

function cleanupSubscriptions(): void
{
    $data = loadSubscriptions();

    $cutoff = strtotime('-30 days');

    $data['subscriptions'] = array_values(
        array_filter(
            $data['subscriptions'],
            function ($sub) use ($cutoff) {

                $lastSeen = strtotime(
                    $sub['last_active']
                        ?? $sub['subscribed_at']
                );

                return $lastSeen > $cutoff;
            }
        )
    );

    saveSubscriptions($data);
}
