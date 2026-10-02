<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$name = trim((string)($_GET['squadron'] ?? ''));
if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Squadron is required']);
    exit;
}

$db = getDb();
$stmt = $db->prepare('SELECT id,name FROM squadrons WHERE name = ? COLLATE NOCASE LIMIT 1');
$stmt->execute([$name]);
$squadron = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$squadron) {
    http_response_code(404);
    echo json_encode(['error' => 'Squadron not found']);
    exit;
}

$id = (int)$squadron['id'];
$categories = [];

$events = $db->prepare('SELECT id,event_type,event_name,value,points_awarded,timestamp FROM events WHERE squadron_id=? ORDER BY timestamp DESC,id DESC');
$events->execute([$id]);
foreach ($events->fetchAll(PDO::FETCH_ASSOC) as $event) {
    $points = (float)($event['value'] ?? $event['points_awarded'] ?? 0);
    $type = strtolower(trim((string)($event['event_type'] ?? 'other')));
    $category = match ($type) {
        'bracket' => 'Brackets',
        'intramural' => 'Intramurals',
        'academic', 'academics' => 'Academics',
        'sami', 'samis' => 'SAMIs',
        'pft' => 'PFT',
        default => 'Other Events',
    };
    if (!isset($categories[$category])) $categories[$category] = ['points'=>0.0,'items'=>[]];
    $categories[$category]['points'] += $points;
    $categories[$category]['items'][] = [
        'name' => $event['event_name'] ?: 'Event',
        'points' => $points,
        'timestamp' => $event['timestamp'],
    ];
}

$intramurals = $db->prepare('SELECT r.points_awarded,r.wins,r.losses,s.sport_name FROM intramural_wl_records r LEFT JOIN intramural_sports s ON s.id=r.sport_id WHERE r.squadron_id=? ORDER BY s.sport_name');
$intramurals->execute([$id]);
foreach ($intramurals->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $points = (float)($row['points_awarded'] ?? 0);
    if (!isset($categories['Intramurals'])) $categories['Intramurals'] = ['points'=>0.0,'items'=>[]];
    $categories['Intramurals']['points'] += $points;
    $categories['Intramurals']['items'][] = [
        'name' => $row['sport_name'] ?: 'Intramurals',
        'points' => $points,
        'record' => (int)$row['wins'] . '–' . (int)$row['losses'],
    ];
}

$total = 0.0;
foreach ($categories as &$category) {
    $category['points'] = round($category['points'], 2);
    $total += $category['points'];
}
unset($category);

uasort($categories, static function ($a, $b) {
    return $b['points'] <=> $a['points'];
});

echo json_encode([
    'squadron' => $squadron['name'],
    'total' => round($total, 2),
    'categories' => $categories,
], JSON_UNESCAPED_SLASHES);
