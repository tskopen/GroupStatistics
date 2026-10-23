<?php
require_once __DIR__ . '/config.php';

function loadSubscriptions(): array {
    $rows = getDb()->query('SELECT endpoint,auth,p256dh,squadrons_json,all_scores,subscribed_at,last_active FROM notification_subscriptions ORDER BY id')->fetchAll();
    $subscriptions = [];
    foreach ($rows as $row) {
        $squadrons = json_decode($row['squadrons_json'] ?? '[]', true);
        $subscriptions[] = [
            'endpoint' => $row['endpoint'],
            'auth' => $row['auth'],
            'p256dh' => $row['p256dh'],
            'squadrons' => is_array($squadrons) ? $squadrons : [],
            'all_scores' => (bool)$row['all_scores'],
            'subscribed_at' => $row['subscribed_at'],
            'last_active' => $row['last_active'],
        ];
    }
    return ['subscriptions'=>$subscriptions];
}

function addSubscription(string $endpoint, string $auth, string $p256dh, array $squadrons = [], ?bool $allScores = null): bool {
    $endpoint = trim($endpoint);
    $auth = trim($auth);
    $p256dh = trim($p256dh);
    if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL) || $auth === '' || $p256dh === '') return false;
    if (strlen($endpoint) > 2048 || strlen($auth) > 512 || strlen($p256dh) > 512) return false;

    $normalizedSquadrons = [];
    foreach ($squadrons as $id) {
        if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id > 0) {
            $normalizedSquadrons[] = (int)$id;
        }
    }
    $normalizedSquadrons = array_values(array_unique($normalizedSquadrons));
    // Keep the historic default for new subscribers, but preferences updates
    // must state all_scores explicitly so an empty selection is not ambiguous.
    $allScores = $allScores ?? empty($normalizedSquadrons);
    $now = date('c');

    $stmt = getDb()->prepare("INSERT INTO notification_subscriptions(endpoint,auth,p256dh,squadrons_json,all_scores,subscribed_at,last_active)
        VALUES(?,?,?,?,?,?,?)
        ON CONFLICT(endpoint) DO UPDATE SET auth=excluded.auth,p256dh=excluded.p256dh,
            squadrons_json=excluded.squadrons_json,all_scores=excluded.all_scores,last_active=excluded.last_active");
    $stmt->execute([
        $endpoint, $auth, $p256dh, json_encode($normalizedSquadrons),
        $allScores ? 1 : 0, $now, $now
    ]);
    return true;
}

function notificationEndpointId(string $endpoint): string {
    return substr(hash('sha256', $endpoint), 0, 12);
}

/**
 * Accept API writes only from this site's origin. These routes are public by
 * design, but a third-party page must not be able to subscribe, unsubscribe,
 * change preferences, or trigger test pushes for the visitor's browser.
 */
function isSameOriginNotificationRequest(): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($origin === '' || $requestHost === '') return false;

    $originParts = parse_url($origin);
    if (!is_array($originParts) || empty($originParts['host'])) return false;
    $originScheme = strtolower((string)($originParts['scheme'] ?? ''));
    if (!in_array($originScheme, ['http', 'https'], true)) return false;

    $configuredOrigin = trim((string)(getenv('APP_ORIGIN') ?: ''));
    if ($configuredOrigin !== '') {
        return rtrim(strtolower($origin), '/') === rtrim(strtolower($configuredOrigin), '/');
    }

    $requestHostParts = parse_url('//' . $requestHost);
    if (!is_array($requestHostParts) || empty($requestHostParts['host'])) return false;
    if (strtolower((string)$originParts['host']) !== strtolower((string)$requestHostParts['host'])) return false;

    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $requestScheme = $forwardedProto !== ''
        ? $forwardedProto
        : (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' ? 'https' : 'http');
    if ($requestScheme !== $originScheme) return false;

    $originPort = $originParts['port'] ?? null;
    $requestPort = $requestHostParts['port'] ?? null;
    if ($originPort !== null && $requestPort !== null && (int)$originPort !== (int)$requestPort) return false;
    if ($originPort !== null && $requestPort === null && !in_array((int)$originPort, [80, 443], true)) return false;
    return true;
}

function sendNotificationForScore(array $scoreData,?string $customMessage=null): array {
    $squadronId=$scoreData['squadron_id']??null;
    if (!$squadronId) return [];
    $body=$customMessage ?: ('Squadron '.$squadronId.' scored '.($scoreData['value']??0).' points!');
    $payload=['title'=>'Score Update','body'=>$body,'icon'=>'/pwa-icon.php?size=192','badge'=>'/pwa-icon.php?size=192','tag'=>'score-update-'.bin2hex(random_bytes(8)),'data'=>['type'=>'score_update','squadron_id'=>$squadronId,'url'=>'/index.php']];
    if (!isValidNotificationPayload($payload)) return [];
    $matched=[];
    foreach (loadSubscriptions()['subscriptions'] as $sub) {
        if (!$sub['all_scores'] && !in_array((string)$squadronId,array_map('strval',$sub['squadrons']),true)) continue;
        $matched[]=['endpoint'=>$sub['endpoint'],'auth'=>$sub['auth'],'p256dh'=>$sub['p256dh'],'payload'=>$payload];
    }
    return $matched;
}

function isValidNotificationPayload(array $payload): bool {
    if (!is_string($payload['title']??null) || trim($payload['title'])==='') return false;
    if (!is_string($payload['body']??null) || trim($payload['body'])==='') return false;
    $data=$payload['data']??null;
    foreach (['type','squadron_id','url'] as $field) if (!is_array($data) || !isset($data[$field]) || $data[$field]==='') return false;
    return true;
}

function removeExpiredSubscriptions(array $expiredEndpoints): void {
    if (!$expiredEndpoints) return;
    $stmt=getDb()->prepare('DELETE FROM notification_subscriptions WHERE endpoint=?');
    foreach ($expiredEndpoints as $endpoint) $stmt->execute([(string)$endpoint]);
}

function cleanupSubscriptions(): void {
    getDb()->exec("DELETE FROM notification_subscriptions WHERE datetime(last_active) < datetime('now','-30 days')");
}
