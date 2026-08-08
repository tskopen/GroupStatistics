<?php
header('Content-Type: application/json');
require __DIR__ . '/config.php';

$squadrons = readJson(DATA_DIR . '/squadrons.json');
echo json_encode(['squadrons' => $squadrons]);
