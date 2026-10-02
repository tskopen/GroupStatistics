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

function addSubscription(string $endpoint,string $auth,string $p256dh,array $squadrons=[]): bool {
    $endpoint=trim($endpoint); $auth=trim($auth); $p256dh=trim($p256dh);
    if ($endpoint==='' || !filter_var($endpoint,FILTER_VALIDATE_URL) || $auth==='' || $p256dh==='') return false;
    if (strlen($endpoint)>2048 || strlen($auth)>512 || strlen($p256dh)>512) return false;
    $now=date('c');
    $stmt=getDb()->prepare("INSERT INTO notification_subscriptions(endpoint,auth,p256dh,squadrons_json,all_scores,subscribed_at,last_active)
        VALUES(?,?,?,?,?,?,?)
        ON CONFLICT(endpoint) DO UPDATE SET auth=excluded.auth,p256dh=excluded.p256dh,squadrons_json=excluded.squadrons_json,all_scores=excluded.all_scores,last_active=excluded.last_active");
    $stmt->execute([$endpoint,$auth,$p256dh,json_encode(array_values($squadrons)),empty($squadrons)?1:0,$now,$now]);
    return true;
}

function sendNotificationForScore(array $scoreData,?string $customMessage=null): array {
    $squadronId=$scoreData['squadron_id']??null;
    if (!$squadronId) return [];
    $body=$customMessage ?: ('Squadron '.$squadronId.' scored '.($scoreData['value']??0).' points!');
    $payload=['title'=>'Score Update','body'=>$body,'icon'=>'/pwa-icon.php?size=192','badge'=>'/pwa-icon.php?size=192','tag'=>'score-update-'.time(),'data'=>['type'=>'score_update','squadron_id'=>$squadronId,'url'=>'/index.php']];
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
