<?php
// Admin-only debug page for VAPID key validation
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

require __DIR__ . '/config.php';

require_once __DIR__ . '/vapid-config.php';

$keys = loadVapidKeys();
$publicKey = $keys['publicKey'] ?? '';
$displayPublicKey = $publicKey !== '' ? $publicKey : 'MISSING';
$decoded = $publicKey !== '' ? decodeVapidBase64Url($publicKey) : null;
$byteLength = $decoded === null ? 0 : strlen($decoded);
$isValid = $keys !== null;
$usingEnvironment = trim((string) getenv('VAPID_PUBLIC_KEY')) !== '' ||
    trim((string) getenv('VAPID_PRIVATE_KEY')) !== '';
$configurationSource = $usingEnvironment ? 'Railway environment variables' : 'DATA_DIR/vapid-keys.json';
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
            <strong>Public Key (first 30 chars):</strong> <code><?php echo htmlspecialchars(substr($displayPublicKey, 0, 30)); ?>...</code><br>
            <strong>Public Key Length:</strong> <?php echo strlen($displayPublicKey); ?> characters<br>
            <strong>Decoded Byte Length:</strong> <?php echo $byteLength; ?> bytes (expected: 65)<br>
            <strong>Format:</strong> base64url P-256 VAPID key<br>
            <strong>Configuration source:</strong> <?php echo htmlspecialchars($configurationSource); ?><br>
            <strong>Private key:</strong> configured (value is never displayed)<br>
        </div>

        <h3>Requirements for Valid VAPID Key</h3>
        <ul>
            <li>Uncompressed P-256 public point must decode to 65 bytes and begin with 0x04.</li>
            <li>Private scalar must decode to 32 bytes.</li>
            <li>Both keys must be the matching pair generated for this application.</li>
            <li>Keys must be base64url encoded and remain stable after deployment.</li>
        </ul>

        <h3>To Generate New Keys</h3>
        <pre>php -r 'require "vendor/autoload.php"; print_r(\\Minishlink\\WebPush\\VAPID::createVapidKeys());'</pre>

        <a href="admin-panel.php" class="back">← Back to Admin Panel</a>
    </div>
</body>
</html>
