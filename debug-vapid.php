<?php
// Admin-only debug page for VAPID key validation
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

require __DIR__ . '/config.php';

$vapidFile = DATA_DIR . '/vapid-keys.json';
$keys = [];

if (file_exists($vapidFile)) {
    $keys = json_decode(file_get_contents($vapidFile), true) ?? [];
}

$publicKey = $keys['publicKey'] ?? 'MISSING';

// Decode to show byte length
$decoded = null;
$byteLength = 0;
$isValid = false;

if (!empty($publicKey)) {
    try {
        $base64 = str_replace(['-', '_'], ['+', '/'], $publicKey);
        $decoded = base64_decode($base64, true);
        if ($decoded !== false) {
            $byteLength = strlen($decoded);
            $isValid = $byteLength === 65;
        }
    } catch (Exception $e) {
        // Silent fail
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>VAPID Key Debug</title>
    <style>
        body { font-family: Arial; background: #f4f4f4; margin: 0; padding: 20px; }
        .panel { max-width: 700px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 6px; }
        h1 { color: #002147; }
        .status { padding: 10px; border-radius: 4px; margin: 10px 0; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .info { background: #d1ecf1; color: #0c5460; }
        pre { background: #f9f9f9; padding: 10px; border-radius: 4px; overflow-x: auto; }
        code { font-family: monospace; }
        .back { display: block; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="panel">
        <h1>VAPID Key Diagnostics</h1>

        <h3>Public Key Status</h3>
        <div class="status <?php echo $isValid ? 'success' : 'error'; ?>">
            <?php if ($isValid): ?>
                ✓ VALID - Key is properly formatted (65 bytes)
            <?php else: ?>
                ✗ INVALID - Key is malformed or missing
            <?php endif; ?>
        </div>

        <h3>Key Details</h3>
        <div class="status info">
            <strong>Public Key (first 30 chars):</strong> <code><?php echo htmlspecialchars(substr($publicKey, 0, 30)); ?>...</code><br>
            <strong>Public Key Length:</strong> <?php echo strlen($publicKey); ?> characters<br>
            <strong>Decoded Byte Length:</strong> <?php echo $byteLength; ?> bytes (expected: 65)<br>
            <strong>Format:</strong> base64url (uses - and _ not + and /)<br>
        </div>

        <h3>Requirements for Valid VAPID Key</h3>
        <ul>
            <li>✓ RFC 7748 Curve25519 format</li>
            <li>✓ Exactly 65 bytes when decoded (87-88 chars base64url)</li>
            <li>✓ Base64url encoding (- and _ instead of + and /)</li>
            <li>✓ No padding characters (=)</li>
        </ul>

        <h3>To Generate New Keys</h3>
        <pre>npm install -g web-push
web-push generate-vapid-keys</pre>

        <a href="admin-panel.php" class="back">← Back to Admin Panel</a>
    </div>
</body>
</html>
