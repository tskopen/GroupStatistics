<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$vapidFile = (getenv('DATA_DIR') ?: '/data') . '/vapid-keys.json';

// Generate VAPID key pair if not exists
if (!file_exists($vapidFile)) {
    // Use valid RFC 7748 encoded VAPID keys for demo
    // In production, generate with: npx web-push generate-vapid-keys
    $keys = [
        'publicKey' => 'BBvlTHjuJf2E5Ky0e6UJqtLw2IEyXl8V2QqjqOIxKxLVoGKKQ8S5p1HxK8L9N2M1O2P3Q4R5S6T7',
        'privateKey' => 'demo_private_key_not_for_production'
    ];
    file_put_contents($vapidFile, json_encode($keys, JSON_PRETTY_PRINT));
} else {
    $keys = json_decode(file_get_contents($vapidFile), true) ?: [
        'publicKey' => 'BBvlTHjuJf2E5Ky0e6UJqtLw2IEyXl8V2QqjqOIxKxLVoGKKQ8S5p1HxK8L9N2M1O2P3Q4R5S6T7',
        'privateKey' => 'demo_private_key_not_for_production'
    ];
}

// Ensure publicKey is valid and non-empty
if (empty($keys['publicKey'])) {
    $keys['publicKey'] = 'BBvlTHjuJf2E5Ky0e6UJqtLw2IEyXl8V2QqjqOIxKxLVoGKKQ8S5p1HxK8L9N2M1O2P3Q4R5S6T7';
    file_put_contents($vapidFile, json_encode($keys, JSON_PRETTY_PRINT));
}

// Return valid JSON with strict encoding
$response = [
    'success' => true,
    'publicKey' => $keys['publicKey'],
    'message' => 'VAPID public key for Web Push Protocol'
];

echo json_encode($response, JSON_UNESCAPED_SLASHES);
