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
require_once __DIR__ . '/vapid-config.php';
require_once __DIR__ . '/notifications-helper.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Load VAPID keys from persistent storage and build a configured WebPush
 * client instance.
 *
 * @return WebPush|null Null if VAPID keys are missing/invalid.
 */
function createWebPushClient() {
    try {
        $vapidData = loadVapidConfiguration();
        return new WebPush([
            'VAPID' => [
                'subject' => $vapidData['subject'],
                'publicKey' => $vapidData['publicKey'],
                'privateKey' => $vapidData['privateKey'],
            ],
        ]);
    } catch (Throwable $e) {
        // The validator emits configuration messages only; key material is never logged.
        error_log('[push] Web Push client could not be initialized: ' . $e->getMessage());
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

    if (empty($notifications)) {
        return ['sent' => 0, 'failed' => 0, 'results' => []];
    }

    $sent = 0;
    $failed = 0;
    $results = [];

    // Validate notifications up front, tracking which ones are actually
    // queued so results line up with the reports returned by flush().
    $queued = [];
    $expiredEndpoints = [];

    $webPush = createWebPushClient();

    if ($webPush === null) {
        foreach ($notifications as $notif) {
            $endpoint = $notif['endpoint'] ?? null;
            $failed++;
            $results[] = [
                'endpoint' => $endpoint ? notificationEndpointId((string)$endpoint) : '(missing)',
                'status' => 'failed',
                'reason' => 'VAPID keys not configured or WebPush init failed'
            ];
        }

        if (!empty($expiredEndpoints)) {
        removeExpiredSubscriptions($expiredEndpoints);
        error_log('[push] Removed ' . count(array_unique($expiredEndpoints)) . ' expired subscription(s).');
    }

    error_log("[push] Push delivery complete: $sent accepted, $failed failed; accepted means the push service accepted the request, not that the device displayed it.");
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
                'endpoint' => $endpoint ? notificationEndpointId((string)$endpoint) : '(missing)',
                'status' => 'failed',
                'reason' => 'missing endpoint/auth/p256dh'
            ];
            continue;
        }

        $endpointPreview = notificationEndpointId((string)$endpoint);
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
                $endpointPreview = notificationEndpointId($report->getEndpoint());

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
                    if ($report->isSubscriptionExpired()) {
                        $expiredEndpoints[] = $report->getEndpoint();
                    }
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
