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
 *
 * Actual VAPID signing and payload encryption is delegated to the
 * minishlink/web-push library, which correctly builds the EC private key
 * DER structure (including the curve OID and public key point) and signs
 * the VAPID JWT with ES256. Hand-rolled OpenSSL/DER code previously lived
 * here and was prone to misparsing the P-256 key as a different curve
 * (e.g. P-192), causing "too small buffer" errors from openssl_sign().
 */

require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Load VAPID keys from persistent storage and build a configured WebPush
 * client instance.
 *
 * @return WebPush|null Null if VAPID keys are missing/invalid.
 */
function createWebPushClient() {
    $vapidFile = (getenv('DATA_DIR') ?: '/data') . '/vapid-keys.json';

    if (!file_exists($vapidFile)) {
        error_log('[push] ✗ VAPID keys file not found at ' . $vapidFile);
        error_log('[PUSH-DIAG] ✗ VAPID FILE NOT FOUND: expected at ' . $vapidFile);
        error_log('[PUSH-DIAG] DATA_DIR=' . getenv('DATA_DIR') . ' | /data exists: ' . (is_dir('/data') ? 'YES' : 'NO'));
        error_log('[PUSH-DIAG] Contents of /data: ' . implode(', ', glob('/data/*') ?: []));
        return null;
    }

    $fileSize = filesize($vapidFile);
    error_log('[PUSH-DIAG] ✓ VAPID file found: ' . $vapidFile . ' size=' . $fileSize . ' bytes');

    $vapidData = json_decode(file_get_contents($vapidFile), true);
    if (empty($vapidData['publicKey']) || empty($vapidData['privateKey'])) {
        error_log('[push] ✗ VAPID keys not configured (missing publicKey/privateKey)');
        error_log('[PUSH-DIAG] ✗ VAPID keys incomplete: publicKey=' . (empty($vapidData['publicKey']) ? 'MISSING' : 'OK ' . strlen($vapidData['publicKey'])) . ' chars, privateKey=' . (empty($vapidData['privateKey']) ? 'MISSING' : 'OK ' . strlen($vapidData['privateKey'])) . ' chars');
        return null;
    }

    error_log('[PUSH-DIAG] ✓ VAPID keys loaded: pub=' . substr($vapidData['publicKey'], 0, 20) . '... priv=' . substr($vapidData['privateKey'], 0, 20) . '...');

    try {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => 'mailto:admin@example.com',
                'publicKey' => $vapidData['publicKey'],
                'privateKey' => $vapidData['privateKey'],
            ],
        ]);

        error_log('[PUSH-DIAG] ✓ WebPush initialized');

        return $webPush;
    } catch (\Throwable $e) {
        error_log('[push] ✗ Failed to initialize WebPush client: ' . $e->getMessage());
        error_log('[PUSH-DIAG] ✗ WebPush init failed: ' . $e->getMessage());
        return null;
    }
}

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

    // Validate notifications up front, tracking which ones are actually
    // queued so results line up with the reports returned by flush().
    $queued = [];

    $webPush = createWebPushClient();

    if ($webPush === null) {
        foreach ($notifications as $notif) {
            $endpoint = $notif['endpoint'] ?? null;
            $failed++;
            $results[] = [
                'endpoint' => $endpoint ? substr($endpoint, 0, 60) : '(missing)',
                'status' => 'failed',
                'reason' => 'VAPID keys not configured or WebPush init failed'
            ];
        }

        error_log("[push] Push delivery complete: $sent sent, $failed failed");
        return ['sent' => $sent, 'failed' => $failed, 'results' => $results];
    }

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

        $endpointPreview = substr($endpoint, 0, 60);
        $message = json_encode($payload);

        if ($message === false) {
            error_log('[push] ✗ Failed to encode notification payload for ' . $endpointPreview . '...');
            $failed++;
            $results[] = [
                'endpoint' => $endpointPreview,
                'status' => 'failed',
                'reason' => 'failed to encode payload'
            ];
            continue;
        }

        try {
            $subscription = Subscription::create([
                'endpoint' => $endpoint,
                'keys' => [
                    'auth' => $auth,
                    'p256dh' => $p256dh,
                ],
            ]);

            error_log('[push] → Queueing notification for endpoint: ' . $endpointPreview . '...');
            $webPush->queueNotification($subscription, $message);

            $queued[] = ['endpoint' => $endpoint, 'endpointPreview' => $endpointPreview];
        } catch (\Throwable $e) {
            error_log('[push] ✗ Failed to queue notification for ' . $endpointPreview . '...: ' . $e->getMessage());
            $failed++;
            $results[] = [
                'endpoint' => $endpointPreview,
                'status' => 'failed',
                'reason' => $e->getMessage()
            ];
        }
    }

    if (!empty($queued)) {
        try {
            foreach ($webPush->flush() as $report) {
                $endpointPreview = substr($report->getEndpoint(), 0, 60);

                if ($report->isSuccess()) {
                    $sent++;
                    error_log('[push] ✓ Notification sent to: ' . $endpointPreview . '...');
                    $results[] = [
                        'endpoint' => $endpointPreview,
                        'status' => 'sent'
                    ];
                } else {
                    $failed++;
                    $reason = $report->getReason();
                    error_log('[push] ✗ Notification failed for ' . $endpointPreview . '...: ' . $reason);
                    $results[] = [
                        'endpoint' => $endpointPreview,
                        'status' => 'failed',
                        'reason' => $reason
                    ];
                }
            }
        } 
        catch (\Throwable $e) {
            error_log('[push] ✗ WebPush flush() failed: ' . $e->getMessage());
            error_log('[push] ✗ Exception class: ' . get_class($e));
            error_log('[push] ✗ Full trace: ' . $e->getTraceAsString());
            foreach ($queued as $q) {
                $failed++;
                $results[] = [
                    'endpoint' => $q['endpointPreview'],
                    'status' => 'failed',
                    'reason' => 'flush error: ' . $e->getMessage()
                ];
            }
        }
    }

    error_log("[push] Push delivery complete: $sent sent, $failed failed");
    return ['sent' => $sent, 'failed' => $failed, 'results' => $results];
}

/**
 * Deliver a single notification to a subscription using the Web Push Protocol.
 *
 * Kept as a thin wrapper around sendPushNotifications() for backward
 * compatibility with any callers expecting the single-notification API.
 *
 * @param string $endpoint Push service subscription URL
 * @param string $auth     Base64-encoded auth secret
 * @param string $p256dh   Base64-encoded client public key
 * @param array  $payload  Notification payload (title, body, icon, etc)
 * @return bool True on success, false otherwise
 */
function sendViaWebPush($endpoint, $auth, $p256dh, $payload) {
    $result = sendPushNotifications([
        [
            'endpoint' => $endpoint,
            'auth' => $auth,
            'p256dh' => $p256dh,
            'payload' => $payload,
        ]
    ]);

    return ($result['sent'] ?? 0) > 0;
}
