<?php
/**
 * Push notification delivery via Firebase Cloud Messaging and Windows Notification Service
 * 
 * Handles sending Web Push notifications directly to push service endpoints without Node.js
 */

/**
 * CONFIGURATION
 * 
 * For FCM to work, you need a Firebase Server API Key.
 * Without it, FCM requests will return 404 (Unauthorized).
 * 
 * To get your Firebase Server API Key:
 * 1. Go to Firebase Console: https://console.firebase.google.com
 * 2. Select your project
 * 3. Project Settings → Cloud Messaging tab
 * 4. Copy "Server API Key"
 * 5. Set it in Railway environment: FCM_SERVER_KEY=your-key
 * 
 * Or use WNS for Windows/Edge notifications (no key needed, uses endpoint auth).
 */

$FCM_SERVER_KEY = getenv('FCM_SERVER_KEY') ?: null;

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
            error_log('Invalid notification structure: missing endpoint/auth/p256dh');
            $failed++;
            continue;
        }
        
        // Determine which push service endpoint
        if (strpos($endpoint, 'fcm.googleapis.com') !== false) {
            // Firebase Cloud Messaging
            if (sendToFCM($endpoint, $payload)) {
                $sent++;
            } else {
                $failed++;
            }
        } elseif (strpos($endpoint, 'notify.windows.com') !== false) {
            // Windows Notification Service
            if (sendToWNS($endpoint, $payload)) {
                $sent++;
            } else {
                $failed++;
            }
        } else {
            // Unknown endpoint
            error_log('Unknown push endpoint: ' . substr($endpoint, 0, 100));
            $failed++;
        }
    }
    
    error_log("Push delivery complete: $sent sent, $failed failed");
    return ['sent' => $sent, 'failed' => $failed];
}

function sendToFCM($endpoint, $payload) {
    global $FCM_SERVER_KEY;
    
    // Extract FCM token from endpoint URL
    if (!preg_match('/\/send\/([a-zA-Z0-9_:-]+)/', $endpoint, $matches)) {
        error_log('Failed to extract FCM token from: ' . substr($endpoint, 0, 100));
        return false;
    }
    
    if (!$FCM_SERVER_KEY) {
        error_log('FCM_SERVER_KEY not configured in Railway environment variables.');
        error_log('Set FCM_SERVER_KEY to your Firebase Server API Key to enable FCM notifications.');
        return false;
    }
    
    $token = $matches[1];
    $title = $payload['title'] ?? 'Squadron Tracker';
    $body = $payload['body'] ?? '';
    
    // Use newer Firebase Cloud Messaging v1 API with Server API Key
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://fcm.googleapis.com/fcm/send',
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: key=' . $FCM_SERVER_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'to' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
                'click_action' => 'index.php'
            ]
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        error_log("FCM curl error: $error");
        return false;
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("✓ FCM sent to token: " . substr($token, 0, 20) . "...");
        return true;
    } else {
        if ($httpCode === 401) {
            error_log("✗ FCM 401: Unauthorized. Check FCM_SERVER_KEY in environment variables.");
        } elseif ($httpCode === 404) {
            error_log("✗ FCM 404: Check that FCM_SERVER_KEY is correct.");
        } else {
            error_log("✗ FCM failed with HTTP $httpCode: " . substr($response, 0, 200));
        }
        return false;
    }
}

function sendToWNS($endpoint, $payload) {
    // Windows Notification Service
    // Endpoint is the full URL provided by Windows
    // Just POST the notification to it
    
    $title = $payload['title'] ?? 'Squadron Tracker';
    $body = $payload['body'] ?? '';
    
    // WNS expects XML format for toast notification
    $xmlPayload = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<toast launch="index.php">
    <visual>
        <binding template="ToastText02">
            <text id="1">$title</text>
            <text id="2">$body</text>
        </binding>
    </visual>
</toast>
XML;
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $endpoint,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: text/xml',
            'X-WNS-Type: wns/toast',
            'X-WNS-TTL: 3600',
            'X-WNS-RequestForStatus: true'
        ],
        CURLOPT_POSTFIELDS => $xmlPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        error_log("WNS curl error: $error");
        return false;
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("✓ WNS sent to: " . substr($endpoint, 0, 50) . "...");
        return true;
    } else {
        error_log("✗ WNS failed with HTTP $httpCode: " . substr($response, 0, 200));
        return false;
    }
}

?>
