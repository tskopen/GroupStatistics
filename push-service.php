<?php
/**
 * Push notification delivery via the standard Web Push Protocol (RFC 8188 / RFC 8292).
 *
 * Browsers create push subscriptions with an endpoint URL plus two keys
 * (auth and p256dh) that are supplied by the push service the browser is
 * using (Mozilla, Google, Microsoft, etc). Delivery does not go through any
 * vendor-specific API (FCM, WNS) — every subscription is delivered the same
 * way: an authenticated POST straight to the subscription's endpoint URL.
 *
 * Subscriptions are stored by subscribe-notifications-api.php with:
 *   - endpoint: the push service URL supplied by the browser
 *   - auth:     base64-encoded authentication secret
 *   - p256dh:   base64-encoded client public key
 */

/**
 * Send a batch of notifications built by notifications-helper.php.
 *
 * @param array $notifications List of ['endpoint' => ..., 'auth' => ..., 'p256dh' => ..., 'payload' => [...]]
 * @return array ['sent' => int, 'failed' => int]
 */
function sendPushNotifications($notifications) {
    if (empty($notifications)) {
        return ['sent' => 0, 'failed' => 0];
    }

    $sent = 0;
    $failed = 0;

    foreach ($notifications as $notif) {
        $endpoint = $notif['endpoint'] ?? null;
        $auth = $notif['auth'] ?? null;
        $p256dh = $notif['p256dh'] ?? null;
        $payload = $notif['payload'] ?? [];

        if (!$endpoint || !$auth || !$p256dh) {
            error_log('Invalid subscription: missing endpoint/auth/p256dh');
            $failed++;
            continue;
        }

        if (sendViaWebPush($endpoint, $auth, $p256dh, $payload)) {
            $sent++;
        } else {
            $failed++;
        }
    }

    error_log("Push delivery complete: $sent sent, $failed failed");
    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Deliver a single notification to a subscription using the Web Push Protocol.
 *
 * The subscription's auth secret is used to sign the outgoing message with
 * HMAC-SHA256. The signature (and the subscription's public key) are sent
 * to the push service via the Authorization header alongside the encoded
 * notification payload.
 *
 * @param string $endpoint Push service subscription URL
 * @param string $auth     Base64-encoded auth secret
 * @param string $p256dh   Base64-encoded client public key
 * @param array  $payload  Notification payload (title, body, icon, etc)
 * @return bool True on success (2xx/201/410 treated as delivered/gone), false otherwise
 */
function sendViaWebPush($endpoint, $auth, $p256dh, $payload) {
    // Decode the keys supplied by the browser's subscription object
    $authKey = base64_decode($auth);
    $p256dhKey = base64_decode($p256dh);

    if ($authKey === false || $p256dhKey === false) {
        error_log('Failed to decode auth/p256dh keys for endpoint: ' . substr($endpoint, 0, 80));
        return false;
    }

    $message = json_encode($payload);
    if ($message === false) {
        error_log('Failed to encode notification payload');
        return false;
    }

    // Sign the message with the subscription's auth secret
    $signature = hash_hmac('sha256', $message, $authKey);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $endpoint,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'TTL: 3600',
            'Authorization: vapid ' . $signature,
            'Crypto-Key: p256dh=' . $p256dh,
        ],
        CURLOPT_POSTFIELDS => $message,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($error) {
        error_log('Web Push curl error for ' . substr($endpoint, 0, 80) . ': ' . $error);
        return false;
    }

    // 201 = created/accepted, 410 = subscription gone (not a delivery failure we should retry)
    if ($httpCode === 201 || $httpCode === 410 || ($httpCode >= 200 && $httpCode < 300)) {
        error_log('✓ Web Push sent to: ' . substr($endpoint, 0, 60) . '...');
        return true;
    }

    error_log("✗ Web Push failed with HTTP $httpCode for " . substr($endpoint, 0, 60) . ': ' . substr((string) $response, 0, 200));
    return false;
}

?>
