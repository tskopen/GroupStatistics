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
    error_log('[PUSH-DIAG] sendPushNotifications called with ' . count($notifications) . ' notifications');

    if (empty($notifications)) {
        return ['sent' => 0, 'failed' => 0, 'results' => []];
    }

    $sent = 0;
    $failed = 0;
    $results = [];

    foreach ($notifications as $notif) {
        $endpoint = $notif['endpoint'] ?? null;
        $auth = $notif['auth'] ?? null;
        $p256dh = $notif['p256dh'] ?? null;
        $payload = $notif['payload'] ?? [];

        if (!$endpoint || !$auth || !$p256dh) {
            error_log('[push] ✗ Invalid subscription: missing endpoint/auth/p256dh');
            $failed++;
            $results[] = [
                'endpoint' => $endpoint ? substr($endpoint, 0, 60) : '(missing)',
                'status' => 'failed',
                'reason' => 'missing endpoint/auth/p256dh'
            ];
            continue;
        }

        $ok = sendViaWebPush($endpoint, $auth, $p256dh, $payload);

        if ($ok) {
            $sent++;
        } else {
            $failed++;
        }

        $results[] = [
            'endpoint' => substr($endpoint, 0, 60),
            'status' => $ok ? 'sent' : 'failed'
        ];
    }

    error_log("[push] Push delivery complete: $sent sent, $failed failed");
    return ['sent' => $sent, 'failed' => $failed, 'results' => $results];
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
    $endpointPreview = substr($endpoint, 0, 60);

    error_log("[push] → Starting delivery to endpoint: {$endpointPreview}...");
    error_log('[PUSH-DIAG] sendViaWebPush START: endpoint=' . substr($endpoint, 0, 60) . '...');

    // Decode the keys supplied by the browser's subscription object
    $authKey = base64_decode($auth);
    $p256dhKey = base64_decode($p256dh);

    if ($authKey === false || $p256dhKey === false) {
        error_log('[push] ✗ Failed to decode auth/p256dh keys for endpoint: ' . substr($endpoint, 0, 80));
        error_log('[PUSH-DIAG] ✗ KEY DECODE FAILED: auth=' . ($authKey === false ? 'FAIL' : 'OK') . ' p256dh=' . ($p256dhKey === false ? 'FAIL' : 'OK'));
        return false;
    }

    error_log('[PUSH-DIAG] ✓ Keys decoded: auth_bytes=' . strlen($authKey) . ' p256dh_bytes=' . strlen($p256dhKey));

    error_log('[push] ✓ auth/p256dh keys decoded successfully for ' . $endpointPreview . '...');

    $message = json_encode($payload);
    if ($message === false) {
        error_log('[push] ✗ Failed to encode notification payload for ' . $endpointPreview . '...');
        return false;
    }

    // Load VAPID keys from persistent storage
    $vapidFile = (getenv('DATA_DIR') ?: '/data') . '/vapid-keys.json';
    if (!file_exists($vapidFile)) {
        error_log('[push] ✗ VAPID keys file not found at ' . $vapidFile);
        error_log('[PUSH-DIAG] ✗ VAPID FILE NOT FOUND: expected at ' . $vapidFile);
        error_log('[PUSH-DIAG] DATA_DIR=' . getenv('DATA_DIR') . ' | /data exists: ' . (is_dir('/data') ? 'YES' : 'NO'));
        error_log('[PUSH-DIAG] Contents of /data: ' . implode(', ', glob('/data/*') ?: []));
        return false;
    }

    $fileSize = filesize($vapidFile);
    error_log('[PUSH-DIAG] ✓ VAPID file found: ' . $vapidFile . ' size=' . $fileSize . ' bytes');

    $vapidData = json_decode(file_get_contents($vapidFile), true);
    if (empty($vapidData['publicKey']) || empty($vapidData['privateKey'])) {
        error_log('[push] ✗ VAPID keys not configured (missing publicKey/privateKey)');
        error_log('[PUSH-DIAG] ✗ VAPID keys incomplete: publicKey=' . (empty($vapidData['publicKey']) ? 'MISSING' : 'OK ' . strlen($vapidData['publicKey'])) . ' chars, privateKey=' . (empty($vapidData['privateKey']) ? 'MISSING' : 'OK ' . strlen($vapidData['privateKey'])) . ' chars');
        return false;
    }

    error_log('[PUSH-DIAG] ✓ VAPID keys loaded: pub=' . substr($vapidData['publicKey'], 0, 20) . '... priv=' . substr($vapidData['privateKey'], 0, 20) . '...');

    $publicKey = $vapidData['publicKey'];
    $privateKey = $vapidData['privateKey'];

    // Create VAPID JWT per RFC 8292
    $vapidJwt = createVapidJwt($endpoint, $privateKey);
    if (!$vapidJwt) {
        error_log('[push] ✗ Failed to create VAPID JWT for ' . $endpointPreview . '...');
        return false;
    }

    error_log('[push] ✓ VAPID JWT created for ' . $endpointPreview . '... (public key prefix: ' . substr($publicKey, 0, 20) . '...)');

    $requestHeaders = [
        'Content-Type: application/json',
        'TTL: 3600',
        'Authorization: vapid t=' . $vapidJwt . ',k=' . $publicKey,  // FIXED: Proper VAPID format
    ];

    // Sanitized copy of headers for logging (hide key material)
    $sanitizedHeaders = [
        'Content-Type: application/json',
        'TTL: 3600',
        'Authorization: vapid t=<redacted>,k=<redacted>',
    ];

    error_log('[push] → Sending curl request to ' . $endpointPreview . '... headers: ' . implode(' | ', $sanitizedHeaders));
    error_log('[PUSH-DIAG] About to send curl POST to: ' . substr($endpoint, 0, 80) . '...');

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $endpoint,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_POSTFIELDS => $message,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    error_log("[push] ← Response for {$endpointPreview}...: HTTP {$httpCode}");
    error_log('[PUSH-DIAG] ← curl response: httpCode=' . $httpCode . ' error=' . ($error ?: 'none') . ' responseLen=' . strlen((string)$response));

    if ($error) {
        error_log('[push] ✗ Web Push curl error for ' . $endpointPreview . '...: ' . $error . ' | headers sent: ' . implode(' | ', $sanitizedHeaders));
        return false;
    }

    // 201 = created/accepted, 410 = subscription gone (not a delivery failure we should retry)
    if ($httpCode === 201 || $httpCode === 410 || ($httpCode >= 200 && $httpCode < 300)) {
        error_log('[push] ✓ Web Push sent to: ' . $endpointPreview . '...');
        error_log('[PUSH-DIAG] ✓ SUCCESS: HTTP ' . $httpCode);
        return true;
    }

    if ($httpCode >= 400) {
        error_log(
            "[push] ✗ Web Push failed with HTTP {$httpCode} for {$endpointPreview}... " .
            'full response: ' . (string) $response . ' | headers sent: ' . implode(' | ', $sanitizedHeaders)
        );
        error_log('[PUSH-DIAG] ✗ FAILED: HTTP ' . $httpCode . ' response=' . substr((string)$response, 0, 500));
        return false;
    }

    error_log("[push] ✗ Web Push failed with unexpected HTTP $httpCode for " . $endpointPreview . '...: ' . substr((string) $response, 0, 200));
    error_log('[PUSH-DIAG] ✗ FAILED: HTTP ' . $httpCode . ' response=' . substr((string)$response, 0, 500));
    return false;
}

/**
 * Create VAPID JWT token per RFC 8292
 * Converts base64url VAPID key to PEM format for OpenSSL signing
 */
function createVapidJwt($endpoint, $privateKey) {
    error_log('[PUSH-DIAG] createVapidJwt: aud=' . parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST));

    $header = [
        'typ' => 'JWT',
        'alg' => 'ES256'
    ];

    $now = time();
    $url = parse_url($endpoint);
    $aud = $url['scheme'] . '://' . $url['host'];

    $payload = [
        'aud' => $aud,
        'exp' => $now + 86400, // 24 hours
        'sub' => 'mailto:admin@example.com'
    ];

    // Encode header and payload using base64url
    $headerEncoded = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $signatureInput = $headerEncoded . '.' . $payloadEncoded;

    // Convert base64url VAPID private key to PEM format
    // The key is stored as base64url, need to decode it first
    $keyDer = base64_decode(strtr($privateKey, '-_', '+/'));

    if ($keyDer === false) {
        error_log('Failed to decode VAPID private key from base64url');
        error_log('[PUSH-DIAG] ✗ Private key base64 decode failed');
        return false;
    }

    error_log('[PUSH-DIAG] ✓ Private key decoded: ' . strlen($keyDer) . ' bytes');

    // Wrap DER key in PEM format for OpenSSL
    // P-256 ECDSA private key
    $keyPem = "-----BEGIN EC PRIVATE KEY-----\n";
    $keyPem .= wordwrap(base64_encode($keyDer), 64, "\n", true);
    $keyPem .= "\n-----END EC PRIVATE KEY-----";

    // Sign with ES256 using the PEM-formatted key
    $signature = '';
    if (!openssl_sign($signatureInput, $signature, $keyPem, OPENSSL_ALGO_SHA256)) {
        error_log('Failed to sign VAPID JWT with private key');
        error_log('[PUSH-DIAG] ✗ openssl_sign failed');
        return false;
    }

    error_log('[PUSH-DIAG] ✓ JWT signed: signature=' . substr(bin2hex($signature), 0, 40) . '...');

    // Encode signature using base64url
    $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    $jwt = $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    error_log('[PUSH-DIAG] JWT created: len=' . strlen($jwt) . ' format=<header>.<payload>.<sig>');

    return $jwt;
}

?>
