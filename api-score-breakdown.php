<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require __DIR__ . '/config.php';

$db = getDb();

$squadronId = isset($_GET['squadron_id']) ? (int) $_GET['squadron_id'] : 0;

if (!$squadronId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid squadron_id parameter.']);
    exit;
}

$stmt = $db->prepare('SELECT * FROM squadrons WHERE id = ?');
$stmt->execute([$squadronId]);
$squadron = $stmt->fetch();

if (!$squadron) {
    http_response_code(404);
    echo json_encode(['error' => 'Squadron not found.']);
    exit;
}

// Event categories: samis, pft, other, bracket (intramural events are
// tracked separately via intramural_wl_records, not the events table).
$eventTypes = ['samis', 'pft', 'other', 'bracket'];

$placeholders = implode(',', array_fill(0, count($eventTypes), '?'));

$stmt = $db->prepare("
    SELECT event_type, COALESCE(SUM(COALESCE(value, points_awarded, 0)), 0) as total
    FROM events
    WHERE squadron_id = ? AND event_type IN ($placeholders)
    GROUP BY event_type
");
$stmt->execute(array_merge([$squadronId], $eventTypes));
$eventTotalsByType = [];
foreach ($stmt->fetchAll() as $row) {
    $eventTotalsByType[$row['event_type']] = (float) $row['total'];
}

$stmt = $db->prepare("
    SELECT event_type, event_name, COALESCE(value, points_awarded, 0) AS points_awarded, timestamp
    FROM events
    WHERE squadron_id = ? AND event_type IN ($placeholders)
    ORDER BY timestamp DESC
");
$stmt->execute(array_merge([$squadronId], $eventTypes));
$eventRows = $stmt->fetchAll();

$eventItems = [];
$eventsTotal = 0;
foreach ($eventRows as $row) {
    $points = (float) $row['points_awarded'];
    $eventsTotal += $points;
    $eventItems[] = [
        'name' => $row['event_name'] ?? ucfirst($row['event_type']),
        'type' => $row['event_type'],
        'points' => $points,
        'date' => $row['timestamp'],
    ];
}

// Intramural totals/items, joined against sports for a readable name.
$stmt = $db->prepare('
    SELECT r.points_awarded, r.wins, r.losses, r.updated_at, s.sport_name
    FROM intramural_wl_records r
    LEFT JOIN intramural_sports s ON r.sport_id = s.id
    WHERE r.squadron_id = ?
    ORDER BY r.updated_at DESC
');
$stmt->execute([$squadronId]);
$intramuralRows = $stmt->fetchAll();

$intramuralItems = [];
$intramuralsTotal = 0;
foreach ($intramuralRows as $row) {
    $points = (float) $row['points_awarded'];
    $intramuralsTotal += $points;
    $intramuralItems[] = [
        'name' => ($row['sport_name'] ?? 'Intramural') . ' (' . (int) $row['wins'] . 'W-' . (int) $row['losses'] . 'L)',
        'type' => 'intramural',
        'points' => $points,
        'date' => $row['updated_at'],
    ];
}

$totalPoints = $eventsTotal + $intramuralsTotal;

echo json_encode([
    'squadron_id' => $squadron['id'],
    'squadron_name' => $squadron['name'],
    'total_points' => $totalPoints,
    'breakdown' => [
        'events' => [
            'total' => $eventsTotal,
            'by_type' => $eventTotalsByType,
            'items' => $eventItems,
        ],
        'intramurals' => [
            'total' => $intramuralsTotal,
            'items' => $intramuralItems,
        ],
    ],
]);
