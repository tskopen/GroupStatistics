<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/notifications-helper.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$expectedToken = (string) ($_SESSION['_csrf'] ?? '');
$providedToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Session security token expired. Reload the page and try again.']);
    exit;
}

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$endpoint = $data['endpoint'] ?? '';
$keys = $data['keys'] ?? [];
$auth = is_array($keys) ? ($keys['auth'] ?? '') : '';
$p256dh = is_array($keys) ? ($keys['p256dh'] ?? '') : '';
$squadrons = $data['squadrons'] ?? [];
if (!is_array($squadrons)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid squadron preferences']);
    exit;
}

$allScores = array_key_exists('all_scores', $data)
    ? filter_var($data['all_scores'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
    : empty($squadrons);
if ($allScores === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'all_scores must be true or false']);
    exit;
}

if (!is_string($endpoint) || !is_string($auth) || !is_string($p256dh) ||
    !addSubscription($endpoint, $auth, $p256dh, normalizeNotificationSquadrons($squadrons), $allScores)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or unsupported push subscription']);
    exit;
}

echo json_encode(['success' => true]);
