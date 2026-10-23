<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vapid-config.php';

/**
 * Normalize preferences to squadron IDs that actually exist in the database.
 */
function normalizeNotificationSquadrons(array $squadrons): array
{
    $validIds = array_map('intval', getDb()->query('SELECT id FROM squadrons')->fetchAll(PDO::FETCH_COLUMN));
    $validLookup = array_fill_keys($validIds, true);
    $normalized = [];

    foreach ($squadrons as $squadronId) {
        if (!(is_int($squadronId) || (is_string($squadronId) && ctype_digit($squadronId)))) {
            continue;
        }
        $id = (int) $squadronId;
        if ($id > 0 && isset($validLookup[$id])) {
            $normalized[$id] = $id;
        }
    }

    return array_values($normalized);
}

function loadSubscriptions(): array
{
    $rows = getDb()->query(
        'SELECT endpoint,auth,p256dh,squadrons_json,all_scores,subscribed_at,last_active
         FROM notification_subscriptions ORDER BY id'
    )->fetchAll();

    $subscriptions = [];
    foreach ($rows as $row) {
        $squadrons = json_decode($row['squadrons_json'] ?? '[]', true);
        $subscriptions[] = [
            'endpoint' => $row['endpoint'],
            'auth' => $row['auth'],
            'p256dh' => $row['p256dh'],
            'squadrons' => is_array($squadrons) ? $squadrons : [],
            'all_scores' => (bool) $row['all_scores'],
            'subscribed_at' => $row['subscribed_at'],
            'last_active' => $row['last_active'],
        ];
    }

    return ['subscriptions' => $subscriptions];
}

/**
 * If $allScores is omitted, preserve the older convention: an empty list means
 * all squads and a non-empty list means selected squads.
 */
function addSubscription(
    string $endpoint,
    string $auth,
    string $p256dh,
    array $squadrons = [],
    ?bool $allScores = null
): bool {
    $endpoint = trim($endpoint);
    $auth = trim($auth);
    $p256dh = trim($p256dh);

    if (
        $endpoint === '' ||
        strlen($endpoint) > 2048 ||
        strlen($auth) > 512 ||
        strlen($p256dh) > 512 ||
        $auth === '' ||
        $p256dh === '' ||
        !filter_var($endpoint, FILTER_VALIDATE_URL) ||
        !isAllowedPushEndpoint($endpoint)
    ) {
        return false;
    }

    $authBytes = decodeVapidBase64Url($auth);
    $p256dhBytes = decodeVapidBase64Url($p256dh);
    if ($authBytes === null || strlen($authBytes) !== 16 ||
        $p256dhBytes === null || strlen($p256dhBytes) !== 65 || $p256dhBytes[0] !== "\x04") {
        return false;
    }

    $squadrons = normalizeNotificationSquadrons($squadrons);
    $allScores = $allScores ?? empty($squadrons);
    $now = date('c');

    $stmt = getDb()->prepare(
        'INSERT INTO notification_subscriptions
            (endpoint,auth,p256dh,squadrons_json,all_scores,subscribed_at,last_active)
         VALUES(?,?,?,?,?,?,?)
         ON CONFLICT(endpoint) DO UPDATE SET
            auth=excluded.auth,
            p256dh=excluded.p256dh,
            squadrons_json=excluded.squadrons_json,
            all_scores=excluded.all_scores,
            last_active=excluded.last_active'
    );
    $stmt->execute([
        $endpoint,
        $auth,
        $p256dh,
        json_encode($squadrons, JSON_THROW_ON_ERROR),
        $allScores ? 1 : 0,
        $now,
        $now,
    ]);

    return true;
}

/**
 * Only accept known public Web Push services. Subscription endpoints are
 * supplied by clients and are used for outbound requests, so arbitrary URLs
 * must not become an SSRF path into the application or its infrastructure.
 */
function isAllowedPushEndpoint(string $endpoint): bool
{
    $parts = parse_url($endpoint);
    if (!is_array($parts) ||
        strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
        empty($parts['host']) ||
        isset($parts['user']) ||
        isset($parts['pass']) ||
        (isset($parts['port']) && (int) $parts['port'] !== 443)) {
        return false;
    }

    $host = strtolower(rtrim((string) $parts['host'], '.'));
    $allowedExact = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
        'web.push.apple.com',
        'push.apple.com',
        'notify.windows.com',
        'wns.windows.com',
    ];
    if (in_array($host, $allowedExact, true)) {
        return true;
    }

    foreach ([
        '.push.services.mozilla.com',
        '.push.apple.com',
        '.notify.windows.com',
        '.wns.windows.com',
    ] as $suffix) {
        if (str_ends_with($host, $suffix)) {
            return true;
        }
    }

    return false;
}

function sendNotificationForScore(array $scoreData, ?string $customMessage = null): array
{
    $squadronId = $scoreData['squadron_id'] ?? null;
    if (!$squadronId) {
        return [];
    }

    $body = $customMessage ?: ('Squadron ' . $squadronId . ' scored ' . ($scoreData['value'] ?? 0) . ' points!');
    $eventId = $scoreData['event_id'] ?? null;
    $tagIdentity = $eventId !== null
        ? (string) $eventId
        : (string) $squadronId . '-' . (string) ($scoreData['timestamp'] ?? time());

    $payload = [
        'title' => 'Score Update',
        'body' => $body,
        'icon' => '/assets/pwa-icon-192.png',
        'badge' => '/assets/pwa-icon-192.png',
        'tag' => 'score-update-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $tagIdentity),
        'data' => [
            'type' => 'score_update',
            'squadron_id' => $squadronId,
            'url' => '/index.php',
        ],
    ];

    if (!isValidNotificationPayload($payload)) {
        return [];
    }

    $matched = [];
    foreach (loadSubscriptions()['subscriptions'] as $sub) {
        if (!$sub['all_scores'] && !in_array((string) $squadronId, array_map('strval', $sub['squadrons']), true)) {
            continue;
        }

        $matched[] = [
            'endpoint' => $sub['endpoint'],
            'auth' => $sub['auth'],
            'p256dh' => $sub['p256dh'],
            'payload' => $payload,
        ];
    }

    return $matched;
}

function isValidNotificationPayload(array $payload): bool
{
    if (!is_string($payload['title'] ?? null) || trim($payload['title']) === '') {
        return false;
    }
    if (!is_string($payload['body'] ?? null) || trim($payload['body']) === '') {
        return false;
    }

    $data = $payload['data'] ?? null;
    foreach (['type', 'squadron_id', 'url'] as $field) {
        if (!is_array($data) || !isset($data[$field]) || $data[$field] === '') {
            return false;
        }
    }

    return true;
}

function removeExpiredSubscriptions(array $expiredEndpoints): void
{
    if (!$expiredEndpoints) {
        return;
    }

    $stmt = getDb()->prepare('DELETE FROM notification_subscriptions WHERE endpoint = ?');
    foreach (array_unique($expiredEndpoints) as $endpoint) {
        $stmt->execute([(string) $endpoint]);
    }
}

function cleanupSubscriptions(): void
{
    getDb()->exec("DELETE FROM notification_subscriptions WHERE datetime(last_active) < datetime('now','-30 days')");
}
