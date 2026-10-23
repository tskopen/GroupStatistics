<?php
/**
 * Load the stable Web Push application-server keys.
 *
 * Prefer environment variables for production secrets. A persistent JSON file
 * is supported for existing Railway deployments, but demo keys are never
 * generated as a fallback.
 */
function decodeVapidBase64Url(string $value): ?string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
        return null;
    }

    $base64 = strtr($value, '-_', '+/');
    $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
    $decoded = base64_decode($base64, true);

    return $decoded === false ? null : $decoded;
}

function loadVapidConfiguration(): array
{
    $envPublic = getenv('VAPID_PUBLIC_KEY');
    $envPrivate = getenv('VAPID_PRIVATE_KEY');
    $hasEnvPublic = is_string($envPublic) && trim($envPublic) !== '';
    $hasEnvPrivate = is_string($envPrivate) && trim($envPrivate) !== '';

    if ($hasEnvPublic !== $hasEnvPrivate) {
        throw new RuntimeException('Both VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY must be configured together.');
    }

    if ($hasEnvPublic && $hasEnvPrivate) {
        $keys = [
            'publicKey' => trim($envPublic),
            'privateKey' => trim($envPrivate),
        ];
    } else {
        $dataDir = getenv('DATA_DIR') ?: (defined('DATA_DIR') ? constant('DATA_DIR') : '/data');
        $vapidFile = rtrim($dataDir, '/') . '/vapid-keys.json';
        if (!is_file($vapidFile) || !is_readable($vapidFile)) {
            throw new RuntimeException('VAPID keys are not configured. Set the VAPID environment variables.');
        }

        $contents = file_get_contents($vapidFile);
        $keys = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($keys)) {
            throw new RuntimeException('The persisted VAPID key file is not valid JSON.');
        }
    }

    $publicKey = $keys['publicKey'] ?? null;
    $privateKey = $keys['privateKey'] ?? null;
    if (!is_string($publicKey) || !is_string($privateKey)) {
        throw new RuntimeException('The VAPID public/private key pair is incomplete.');
    }

    $publicKey = trim($publicKey);
    $privateKey = trim($privateKey);
    $decodedPublic = decodeVapidBase64Url($publicKey);
    $decodedPrivate = decodeVapidBase64Url($privateKey);

    // This public key was previously bundled with a public demo private key.
    // Reject it so a deployment cannot silently continue using a known pair.
    $publishedDemoPublicKey = 'BOEd7Z3e-DesqeCznrwgIHe6o9o-M_bROcmhfJxkqOo6WNlIOPxQnYOq6Tp_RdGq9aBxvFlyOtMDLJ2e5r6hUWA';
    if (hash_equals($publishedDemoPublicKey, $publicKey)) {
        throw new RuntimeException('The configured VAPID key is a published demo key. Configure a fresh key pair before enabling push.');
    }

    if (
        $decodedPublic === null ||
        strlen($decodedPublic) !== 65 ||
        $decodedPublic[0] !== "\x04" ||
        $decodedPrivate === null ||
        strlen($decodedPrivate) !== 32
    ) {
        throw new RuntimeException('The VAPID keys have an invalid Web Push key format.');
    }

    // Derive the P-256 public point from the private scalar and compare it
    // with the configured public key. This catches mismatched values before
    // browsers create subscriptions the server cannot authenticate.
    if (!function_exists('openssl_pkey_get_private') || !function_exists('openssl_pkey_get_details')) {
        throw new RuntimeException('The OpenSSL extension is required for Web Push key validation.');
    }

    $privateDer = chr(0x30) . chr(0x31) . chr(0x02) . chr(0x01) . chr(0x01)
        . chr(0x04) . chr(0x20) . $decodedPrivate
        . chr(0xA0) . chr(0x0A) . chr(0x06) . chr(0x08)
        . chr(0x2A) . chr(0x86) . chr(0x48) . chr(0xCE)
        . chr(0x3D) . chr(0x03) . chr(0x01) . chr(0x07);
    $privatePem = "-----BEGIN EC PRIVATE KEY-----\n"
        . chunk_split(base64_encode($privateDer), 64, "\n")
        . "-----END EC PRIVATE KEY-----\n";
    $privateKeyObject = @openssl_pkey_get_private($privatePem);
    if ($privateKeyObject === false) {
        throw new RuntimeException('The configured VAPID private key is not a valid P-256 key.');
    }

    $privateDetails = @openssl_pkey_get_details($privateKeyObject);
    if (
        !is_array($privateDetails) ||
        ($privateDetails['ec']['curve_name'] ?? '') !== 'prime256v1' ||
        !is_string($privateDetails['ec']['x'] ?? null) ||
        !is_string($privateDetails['ec']['y'] ?? null)
    ) {
        throw new RuntimeException('The configured VAPID private key is not a valid P-256 key.');
    }

    $derivedPublic = chr(0x04) . $privateDetails['ec']['x'] . $privateDetails['ec']['y'];
    if (!hash_equals($decodedPublic, $derivedPublic)) {
        throw new RuntimeException('VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY do not belong to the same key pair.');
    }

    $subject = getenv('VAPID_SUBJECT');
    if (!is_string($subject)) {
        $subject = '';
    }
    $subject = trim($subject);
    $isMailto = (bool) preg_match('/^mailto:[^@\s]+@[^@\s]+$/i', $subject);
    $subjectParts = parse_url($subject);
    $isHttpsUrl = is_array($subjectParts)
        && strtolower((string)($subjectParts['scheme'] ?? '')) === 'https'
        && !empty($subjectParts['host']);

    if (!$isMailto && !$isHttpsUrl) {
        throw new RuntimeException('Set VAPID_SUBJECT to a real mailto: contact address or HTTPS URL.');
    }

    return [
        'publicKey' => $publicKey,
        'privateKey' => $privateKey,
        'subject' => $subject,
    ];
}
