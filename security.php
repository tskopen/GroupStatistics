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

function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $expected = $_SESSION['_csrf'] ?? '';
    $provided = $_POST['_csrf'] ?? '';
    if ($expected === '' || $provided === '' || !hash_equals($expected, (string)$provided)) {
        http_response_code(419);
        exit('Invalid or expired form token. Please reload the page and try again.');
    }
}

function require_csrf(): void { verify_csrf(); }

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
