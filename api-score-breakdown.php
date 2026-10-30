<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require __DIR__ . '/config.php';

$squadronId = isset($_GET['squadron_id']) ? (int)$_GET['squadron_id'] : 0;
if ($squadronId < 1 || $squadronId > 10) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose a valid Group 1 squadron.']);
    exit;
}
$stmt = getDb()->prepare('SELECT id,name FROM squadrons WHERE id=? LIMIT 1');
$stmt->execute([$squadronId]);
$squadron = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$squadron) {
    http_response_code(404);
    echo json_encode(['error' => 'Squadron not found.']);
    exit;
}
$breakdown = opaGetSquadronBreakdown(getDb(), $squadronId);
if (!$breakdown) {
    echo json_encode(['error' => 'Official OPA scores have not been published yet.']);
    exit;
}
$current = $breakdown['current'];
$previous = $breakdown['previous'];
$categories = [];
foreach ($breakdown['categories'] as $row) {
    $categories[] = [
        'name' => $row['category_name'],
        'rank' => (int)$row['category_rank'],
        'score' => (float)$row['category_score'],
        'points' => (float)$row['category_points'],
        'previous_points' => $row['previous_rank'] !== null ? 11 - (int)$row['previous_rank'] : null,
        'points_change' => $row['previous_rank'] !== null ? (11 - (int)$row['category_rank']) - (11 - (int)$row['previous_rank']) : null,
        'source_rank' => $row['source_rank'] !== null ? (int)$row['source_rank'] : null,
        'previous_rank' => $row['previous_rank'] !== null ? (int)$row['previous_rank'] : null,
        'rank_change' => $row['rank_change'] !== null ? (int)$row['rank_change'] : null,
        'previous_score' => $row['previous_score'] !== null ? (float)$row['previous_score'] : null,
        'score_change' => $row['score_change'] !== null ? (float)$row['score_change'] : null,
    ];
}
echo json_encode([
    'squadron_id' => (int)$squadron['id'],
    'squadron_name' => $squadron['name'],
    'publication' => $breakdown['publication'],
    'current' => [
        'opa' => (float)$current['opa'],
        'overall_rank' => (int)$current['overall_rank'],
        'opa_change' => $current['opa_change'] !== null ? (float)$current['opa_change'] : null,
        'rank_change' => $current['rank_change'] !== null ? (int)$current['rank_change'] : null,
    ],
    'previous' => $previous ? [
        'opa' => (float)$previous['opa'],
        'overall_rank' => (int)$previous['overall_rank'],
    ] : null,
    'categories' => $categories,
    'history' => array_map(static fn($row) => [
        'publication_id' => (int)$row['id'],
        'published_at' => $row['published_at'],
        'opa' => (float)$row['opa'],
        'overall_rank' => (int)$row['overall_rank'],
    ], $breakdown['history']),
]);
