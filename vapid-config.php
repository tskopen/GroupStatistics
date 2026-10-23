<?php
/**
 * Shared Web Push VAPID configuration.
 *
 * Prefer Railway variables. A persistent DATA_DIR/vapid-keys.json file is
 * supported for deployments that already provision keys that way. Never
 * silently create demo keys: clients must subscribe with the same public key
 * used by the server's private key.
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

/**
 * @return array{publicKey:string,privateKey:string,subject:string}|null
 */
function loadVapidKeys(): ?array
{
    $environmentPublic = getenv('VAPID_PUBLIC_KEY');
    $environmentPrivate = getenv('VAPID_PRIVATE_KEY');
    $hasEnvironmentPublic = is_string($environmentPublic) && trim($environmentPublic) !== '';
    $hasEnvironmentPrivate = is_string($environmentPrivate) && trim($environmentPrivate) !== '';

    if ($hasEnvironmentPublic || $hasEnvironmentPrivate) {
        if (!$hasEnvironmentPublic || !$hasEnvironmentPrivate) {
            error_log('[push] VAPID configuration is incomplete: set both VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY.');
            return null;
        }
        $publicKey = trim($environmentPublic);
        $privateKey = trim($environmentPrivate);
    } else {
        $dataDir = getenv('DATA_DIR') ?: (defined('DATA_DIR') ? DATA_DIR : '/data');
        $keyFile = rtrim($dataDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vapid-keys.json';
        if (!is_readable($keyFile)) {
            error_log('[push] VAPID keys are not configured; set VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY or provision DATA_DIR/vapid-keys.json.');
            return null;
        }

        $decoded = json_decode((string) file_get_contents($keyFile), true);
        if (!is_array($decoded)) {
            error_log('[push] VAPID key file is not valid JSON.');
            return null;
        }
        $publicKey = is_string($decoded['publicKey'] ?? null) ? trim($decoded['publicKey']) : '';
        $privateKey = is_string($decoded['privateKey'] ?? null) ? trim($decoded['privateKey']) : '';
    }

    $publicBytes = decodeVapidBase64Url($publicKey);
    $privateBytes = decodeVapidBase64Url($privateKey);
    if ($publicBytes === null || strlen($publicBytes) !== 65 || $publicBytes[0] !== "\x04" ||
        $privateBytes === null || strlen($privateBytes) !== 32) {
        error_log('[push] VAPID keys have invalid base64url encoding or key length.');
        return null;
    }

    $subject = trim((string) (getenv('VAPID_SUBJECT') ?: 'https://groupstatistics-production.up.railway.app/'));
    $isMailto = str_starts_with($subject, 'mailto:') && filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL);
    $subjectUrl = filter_var($subject, FILTER_VALIDATE_URL);
    if (!$isMailto && (!$subjectUrl || strtolower((string) parse_url($subject, PHP_URL_SCHEME)) !== 'https')) {
        error_log('[push] VAPID_SUBJECT must be a mailto: address or an HTTPS URL.');
        return null;
    }

    return [
        'publicKey' => $publicKey,
        'privateKey' => $privateKey,
        'subject' => $subject,
    ];
}
