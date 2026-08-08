<?php
/**
 * Push notification delivery via Firebase Cloud Messaging and Windows Notification Service
 * 
 * Handles sending Web Push notifications directly to push service endpoints without Node.js
 */

/**
 * CONFIGURATION
 * 
 * Firebase offers two authentication methods:
 * 
 * METHOD 1: Service Account (Recommended - Modern API V1)
 * For Firebase projects created after 2020, use Service Account authentication.
 * 
 * 1. Firebase Console → Project Settings → Service Accounts
 * 2. Click "Manage Service Accounts" → Google Cloud Console opens
 * 3. Find your Firebase service account (firebase-adminsdk-xxxxx@...)
 * 4. Click 3 dots → "Manage keys" → "Create new key" → JSON
 * 5. Download and extract the JSON file
 * 6. Create file: /data/firebase-service-account.json with the JSON content
 * 7. Or set FIREBASE_CREDENTIALS as base64-encoded JSON
 * 
 * METHOD 2: Legacy Server API Key (if you have the old key)
 * For older Firebase projects that still have the Server API Key:
 * 
 * 1. Firebase Console → Project Settings → Cloud Messaging tab
 * 2. Copy "Server API Key" (if shown)
 * 3. Set FCM_SERVER_KEY environment variable in Railway
 * 
 * To test which method works:
 * Admin Panel → Send Notifications → Check Railway logs
 * Look for: "✓ FCM sent" or error messages showing which auth failed
 */

// Try Method 1: Service Account
$firebaseServiceAccount = null;
$serviceAccountPath = '/data/firebase-service-account.json';

if (file_exists($serviceAccountPath)) {
    $firebaseServiceAccount = json_decode(file_get_contents($serviceAccountPath), true);
}

// Try Method 2: Legacy Server API Key
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
    global $FCM_SERVER_KEY, $firebaseServiceAccount;
    
    // Extract FCM token from endpoint URL
    if (!preg_match('/\/send\/([a-zA-Z0-9_:-]+)/', $endpoint, $matches)) {
        error_log('Failed to extract FCM token from: ' . substr($endpoint, 0, 100));
        return false;
    }
    
    $token = $matches[1];
    $title = $payload['title'] ?? 'Squadron Tracker';
    $body = $payload['body'] ?? '';
    
    // Determine which authentication method to use
    if ($firebaseServiceAccount) {
        return sendToFCMv1($token, $title, $body, $firebaseServiceAccount);
    } elseif ($FCM_SERVER_KEY) {
        return sendToFCMLegacy($token, $title, $body, $FCM_SERVER_KEY);
    } else {
        error_log('FCM not configured. Provide either:');
        error_log('  1. /data/firebase-service-account.json (Service Account), or');
        error_log('  2. FCM_SERVER_KEY environment variable (Legacy Server API Key)');
        error_log('See NOTIFICATION_SETUP.md for instructions.');
        return false;
    }
}

function sendToFCMv1($token, $title, $body, $serviceAccount) {
    // Firebase Cloud Messaging API V1
    // Requires OAuth 2.0 access token from Service Account
    
    $projectId = $serviceAccount['project_id'] ?? null;
    $privateKey = $serviceAccount['private_key'] ?? null;
    $clientEmail = $serviceAccount['client_email'] ?? null;
    
    if (!$projectId || !$privateKey || !$clientEmail) {
        error_log('Invalid Firebase Service Account - missing required fields');
        return false;
    }
    
    // Get OAuth 2.0 access token
    $accessToken = getFCMAccessToken($serviceAccount);
    if (!$accessToken) {
        error_log('Failed to obtain FCM access token');
        return false;
    }
    
    // Call FCM V1 API
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://fcm.googleapis.com/v1/projects/$projectId/messages:send",
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body
                ],
                'android' => [
                    'ttl' => '3600s',
                    'priority' => 'high'
                ]
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
        error_log("FCM V1 curl error: $error");
        return false;
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("✓ FCM V1 sent to token: " . substr($token, 0, 20) . "...");
        return true;
    } else {
        $responseData = json_decode($response, true);
        $errorMsg = $responseData['error']['message'] ?? 'Unknown error';
        error_log("✗ FCM V1 failed with HTTP $httpCode: $errorMsg");
        return false;
    }
}

function sendToFCMLegacy($token, $title, $body, $serverKey) {
    // Legacy FCM API (older projects)
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://fcm.googleapis.com/fcm/send',
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: key=' . $serverKey
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
        error_log("FCM Legacy curl error: $error");
        return false;
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        error_log("✓ FCM Legacy sent to token: " . substr($token, 0, 20) . "...");
        return true;
    } else {
        error_log("✗ FCM Legacy failed with HTTP $httpCode: " . substr($response, 0, 200));
        return false;
    }
}

function getFCMAccessToken($serviceAccount) {
    // Generate JWT and exchange for OAuth 2.0 access token
    
    $now = time();
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $claim = json_encode([
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]);
    
    // Sign with private key
    $header64 = base64_encode($header);
    $claim64 = base64_encode($claim);
    $signature = '';
    
    $key = $serviceAccount['private_key'];
    openssl_sign($header64 . '.' . $claim64, $signature, $key, 'sha256');
    
    $jwt = $header64 . '.' . $claim64 . '.' . base64_encode($signature);
    
    // Exchange JWT for access token
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://oauth2.googleapis.com/token',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5
    ]);
    
    $response = json_decode(curl_exec($curl), true);
    curl_close($curl);
    
    return $response['access_token'] ?? null;
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
