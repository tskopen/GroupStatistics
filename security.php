<?php
/** Shared request-security helpers. */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}

function csrf_input(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function isAdminEndpoint(): bool {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return str_starts_with($script, 'admin-') || str_starts_with($script, 'admin_') || $script === 'admin.php';
}

function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isAdminEndpoint()) return;
    $expected = $_SESSION['_csrf'] ?? '';
    $provided = $_POST['_csrf'] ?? '';
    if ($expected === '' || $provided === '' || !hash_equals($expected, (string)$provided)) {
        http_response_code(419);
        exit('Invalid or expired form token. Please reload the page and try again.');
    }
}

function require_csrf(): void { verify_csrf(); }

/**
 * Automatically inject the token into normal HTML POST forms. This lets the
 * existing admin UI gain CSRF protection without duplicating token markup in
 * every template. JavaScript/API requests must send the same token explicitly.
 */
ob_start(static function (string $html): string {
    if ($html === '' || stripos($html, '<form') === false) return $html;
    $token = csrf_input();
    return preg_replace_callback('/<form\b([^>]*)>/i', static function ($m) use ($token) {
        $attrs = $m[1];
        if (!preg_match('/\bmethod\s*=\s*["\']?post\b/i', $attrs)) return $m[0];
        if (stripos($m[0], 'name="_csrf"') !== false) return $m[0];
        return $m[0] . $token;
    }, $html) ?? $html;
});
verify_csrf();

function validateUploadedImage(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [true, null];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return [false, 'Upload failed.'];
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) return [false, 'Image must be 5 MB or smaller.'];
    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) return [false, 'Invalid upload.'];
    $info = @getimagesize($tmp);
    if ($info === false) return [false, 'Uploaded file is not a valid image.'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) return [false, 'Only PNG, JPEG, GIF, and WebP images are allowed.'];
    if (($info[0] ?? 0) > 4096 || ($info[1] ?? 0) > 4096) return [false, 'Image dimensions may not exceed 4096×4096.'];
    return [true, $allowed[$mime]];
}

function safeImageFilename(string $extension): string {
    return bin2hex(random_bytes(16)) . '.' . strtolower($extension);
}
