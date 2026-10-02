<?php
require_once __DIR__ . '/notifications-helper.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'POST required']);
    exit;
}

$data=json_decode(file_get_contents('php://input'),true);
$endpoint=$data['endpoint']??'';
$keys=$data['keys']??[];
$auth=$keys['auth']??'';
$p256dh=$keys['p256dh']??'';
$squadrons=$data['squadrons']??[];
if (!is_array($squadrons)) $squadrons=[];
$squadrons=array_values(array_filter(array_map('intval',$squadrons),fn($id)=>$id>0));

if (!is_string($endpoint) || !is_string($auth) || !is_string($p256dh) || !addSubscription($endpoint,$auth,$p256dh,$squadrons)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Invalid push subscription']);
    exit;
}

echo json_encode(['success'=>true]);
