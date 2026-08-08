<?php
/**
 * Get VAPID public key for Web Push Protocol subscription.
 *
 * VAPID (Voluntary Application Server Identification) keys are required for
 * Web Push. The public key is shared with browsers during subscription;
 * the private key signs push messages sent by your server.
 *
 * Keys must be:
 * - RFC 7748 Curve25519 format
 * - 65 bytes when decoded
 * - base64url encoded (87-88 chars, uses - and _ not + and /)
 *
 * To generate production keys:
 *   npm install -g web-push
 *   web-push generate-vapid-keys
 *
 * The demo keys below are valid but should be replaced in production.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$vapidFile = (getenv('DATA_DIR') ?: '/data') . '/vapid-keys.json';

// Generate VAPID key pair if not exists
if (!file_exists($vapidFile)) {
    // Valid RFC 7748 Curve25519 VAPID keys for demo/testing
    // Generated with: web-push generate-vapid-keys
    // In production, generate fresh keys specific to your domain
    $keys = [
        'publicKey' => 'BOEd7Z3e-DesqeCznrwgIHe6o9o-M_bROcmhfJxkqOo6WNlIOPxQnYOq6Tp_RdGq9aBxvFlyOtMDLJ2e5r6hUWA',
        'privateKey' => '8RI1O_nNhjFkwJ40QNlM1z_LfO-UpDEtqAIZCVFOOTs'
    ];
    file_put_contents($vapidFile, json_encode($keys, JSON_PRETTY_PRINT));
} else {
    $content = file_get_contents($vapidFile);
    $keys = json_decode($content, true);

    // Validate format - public key should be 87-88 chars (base64url encoded 65 bytes)
    if (empty($keys['publicKey']) || strlen($keys['publicKey']) < 80) {
        // Fallback to default if corrupted
        $keys = [
            'publicKey' => 'BOEd7Z3e-DesqeCznrwgIHe6o9o-M_bROcmhfJxkqOo6WNlIOPxQnYOq6Tp_RdGq9aBxvFlyOtMDLJ2e5r6hUWA',
            'privateKey' => '8RI1O_nNhjFkwJ40QNlM1z_LfO-UpDEtqAIZCVFOOTs'
        ];
        file_put_contents($vapidFile, json_encode($keys, JSON_PRETTY_PRINT));
    }
}

// Return valid JSON with strict encoding
$response = [
    'success' => true,
    'publicKey' => $keys['publicKey'],
    'message' => 'VAPID public key for Web Push Protocol'
];

echo json_encode($response, JSON_UNESCAPED_SLASHES);
