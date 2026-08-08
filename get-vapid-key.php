<?php
header('Content-Type: application/json');

// Generate VAPID key pair if not exists
$vapidFile = (getenv('DATA_DIR') ?: '/data') . '/vapid-keys.json';

if (!file_exists($vapidFile)) {
    // For demo: use dummy keys. In production, generate with web-push library
    $keys = [
        'publicKey' => 'BMZz1ELX3OjXCEFXy6lJFPDzS2H0-v9xbLgD5qLfMv0FqYxpH1G3L0e8kJ2K0p9m',
        'privateKey' => 'dummy-private-key'
    ];
    file_put_contents($vapidFile, json_encode($keys));
} else {
    $keys = json_decode(file_get_contents($vapidFile), true);
}

echo json_encode(['publicKey' => $keys['publicKey']]);
