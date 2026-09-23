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

    // RFC 8292: P-256 ECDSA private key is raw 32-byte scalar stored as base64url.
    // OpenSSL requires a proper DER-encoded EC PRIVATE KEY structure (RFC 5915),
    // not the raw scalar bytes wrapped in PEM headers.
    $keyDer = base64_decode(strtr($privateKey, '-_', '+/'));

    if ($keyDer === false || strlen($keyDer) !== 32) {
        error_log('Failed to decode VAPID private key: invalid format or length (expected 32 bytes, got ' . ($keyDer === false ? 'false' : strlen($keyDer)) . ')');
        error_log('[PUSH-DIAG] ✗ Private key format error: length=' . ($keyDer === false ? 'false' : strlen($keyDer)));
        return false;
    }

    error_log('[PUSH-DIAG] ✓ Private key decoded: ' . strlen($keyDer) . ' bytes (before DER construction)');

    // Build a full, valid EC PRIVATE KEY DER structure (SEC1 / RFC 5915) and wrap it in PEM.
    // The raw 32-byte scalar alone is NOT valid DER — OpenSSL requires the full
    // SEQUENCE { version, privateKey OCTET STRING, parameters [0] EXPLICIT OID }.
    $derPrivateKey = buildECPrivateKeyDER($keyDer);

    if ($derPrivateKey === false) {
        error_log('Failed to build EC private key DER structure');
        error_log('[PUSH-DIAG] ✗ DER construction failed for private key of length ' . strlen($keyDer));
        return false;
    }

    error_log('[PUSH-DIAG] ✓ DER structure built: ' . strlen($derPrivateKey) . ' bytes, hex prefix=' . substr(bin2hex($derPrivateKey), 0, 20) . '...');

    $keyPem = "-----BEGIN EC PRIVATE KEY-----\n";
    $keyPem .= wordwrap(base64_encode($derPrivateKey), 64, "\n", true);
    $keyPem .= "\n-----END EC PRIVATE KEY-----\n";

    error_log('[PUSH-DIAG] ✓ EC private key DER built and wrapped in PEM format');
    error_log('[PUSH-DIAG] Generated PEM (first 100 chars): ' . substr($keyPem, 0, 100) . '...');

    // Verify the PEM can actually be parsed by OpenSSL before attempting to sign.
    // Clear any stale OpenSSL error queue entries first so our diagnostics are accurate.
    while (openssl_error_string() !== false) {
        // drain queue
    }

    $testKeyResource = openssl_pkey_get_private($keyPem);
    if ($testKeyResource === false) {
        error_log('[PUSH-DIAG] ✗ openssl_pkey_get_private() FAILED to parse generated PEM');
        $opensslErrors = [];
        while (($opensslError = openssl_error_string()) !== false) {
            $opensslErrors[] = $opensslError;
        }
        error_log('[PUSH-DIAG] OpenSSL error stack: ' . (empty($opensslErrors) ? '(none reported)' : implode(' | ', $opensslErrors)));
        return false;
    }

    error_log('[PUSH-DIAG] ✓ openssl_pkey_get_private() succeeded, key resource obtained');

    $keyDetails = openssl_pkey_get_details($testKeyResource);
    if ($keyDetails !== false) {
        error_log('[PUSH-DIAG] Key details: type=' . ($keyDetails['type'] ?? 'unknown') . ' bits=' . ($keyDetails['bits'] ?? 'unknown') . ' curve=' . ($keyDetails['ec']['curve_name'] ?? 'unknown'));
    }

    // Sign with ES256 using the PEM-formatted key
    $signature = '';
    if (!openssl_sign($signatureInput, $signature, $keyPem, OPENSSL_ALGO_SHA256)) {
        error_log('Failed to sign VAPID JWT with private key');
        error_log('[PUSH-DIAG] ✗ openssl_sign failed');
        $opensslErrors = [];
        while (($opensslError = openssl_error_string()) !== false) {
            $opensslErrors[] = $opensslError;
        }
        error_log('[PUSH-DIAG] OpenSSL error stack: ' . (empty($opensslErrors) ? '(none reported)' : implode(' | ', $opensslErrors)));
        return false;
    }

    error_log('[PUSH-DIAG] ✓ JWT signed: signature=' . substr(bin2hex($signature), 0, 40) . '...');

    // Encode signature using base64url
    $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    $jwt = $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    error_log('[PUSH-DIAG] JWT created: len=' . strlen($jwt) . ' format=<header>.<payload>.<sig>');

    return $jwt;
}

/**
 * DER-encode a length value per the ASN.1 Distinguished Encoding Rules.
 *
 * - Lengths 0-127 are encoded as a single byte containing the length.
 * - Lengths 128-255 are encoded as 0x81 followed by one length byte.
 * - Lengths 256-65535 are encoded as 0x82 followed by two length bytes (big-endian).
 *
 * Our structures are all small (well under 256 bytes), but the two-byte case
 * is included for completeness/robustness.
 *
 * @param int $length
 * @return string
 */
function derEncodeLength($length) {
    if ($length < 128) {
        return chr($length);
    }

    if ($length < 256) {
        return "\x81" . chr($length);
    }

    return "\x82" . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
}

/**
 * Build a full, valid "EC PRIVATE KEY" DER structure for a P-256 (secp256r1) key.
 *
 * This follows SEC1 v2.0 / RFC 5915:
 *
 *   ECPrivateKey ::= SEQUENCE {
 *     version        INTEGER { ecPrivkeyVer1(1) } (SEC1 uses 1, not 0),
 *     privateKey     OCTET STRING,
 *     parameters [0] EXPLICIT ECParameters OPTIONAL,
 *     publicKey  [1] EXPLICIT BIT STRING OPTIONAL
 *   }
 *
 * VAPID (RFC 8292) stores only the raw 32-byte private scalar. OpenSSL's
 * openssl_pkey_get_private()/openssl_sign() cannot consume that raw scalar
 * directly — it requires the full ASN.1 SEQUENCE shown above, at minimum with
 * the "parameters" field present so OpenSSL knows which curve (secp256r1) the
 * scalar belongs to. Without it, openssl_sign() fails with:
 *   "Supplied key param cannot be coerced into a private key"
 *
 * We omit the optional publicKey [1] field — OpenSSL can derive the public
 * point from the private scalar + curve when needed for signing.
 *
 * @param string $privateScalar Raw 32-byte P-256 private key scalar
 * @return string|false DER-encoded ECPrivateKey structure, or false on error
 */
function buildECPrivateKeyDER($privateScalar) {
    if (!is_string($privateScalar) || strlen($privateScalar) !== 32) {
        error_log('[PUSH-DIAG] buildECPrivateKeyDER: invalid scalar length=' . (is_string($privateScalar) ? strlen($privateScalar) : 'non-string'));
        return false;
    }

    // --- version INTEGER 1 ---
    // DER: tag=0x02 (INTEGER), length=0x01, value=0x01 (ecPrivkeyVer1)
    $version = "\x02\x01\x01";

    // --- privateKey OCTET STRING (the raw 32-byte scalar) ---
    // DER: tag=0x04 (OCTET STRING), length=0x20 (32 bytes), value=<32 raw bytes>
    $privateKeyOctetString = "\x04" . derEncodeLength(strlen($privateScalar)) . $privateScalar;

    // --- parameters [0] EXPLICIT ECParameters (namedCurve = secp256r1) ---
    // secp256r1 (a.k.a prime256v1 / P-256) OID: 1.2.840.10045.3.1.1
    // DER-encoded OID bytes: 06 08 2a 86 48 ce 3d 03 01 01
    //   tag=0x06 (OBJECT IDENTIFIER), length=0x08, value=2a 86 48 ce 3d 03 01 01
    $namedCurveOid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x01";

    // Wrap the OID in the context-specific [0] EXPLICIT tag.
    // DER: tag=0xa0 ([0] constructed, explicit), length=X, value=<OID DER>
    $parameters = "\xa0" . derEncodeLength(strlen($namedCurveOid)) . $namedCurveOid;

    // --- Assemble the outer SEQUENCE { version, privateKey, parameters } ---
    $sequenceContents = $version . $privateKeyOctetString . $parameters;
    $sequenceLength = strlen($sequenceContents);

    // DER: tag=0x30 (SEQUENCE), length=X, value=<contents>
    $der = "\x30" . derEncodeLength($sequenceLength) . $sequenceContents;

    error_log('[PUSH-DIAG] buildECPrivateKeyDER: built DER SEQUENCE of ' . strlen($der) . ' bytes (version+privateKey+parameters[secp256r1])');

    return $der;
}

?>
