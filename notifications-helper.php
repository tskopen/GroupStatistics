<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

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

    $subscriptions = loadSubscriptions();

    $squadronId = $scoreData['squadron_id'] ?? null;

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

    return $matched;
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
