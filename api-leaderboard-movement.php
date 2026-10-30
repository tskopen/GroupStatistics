<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require __DIR__ . '/config.php';

$ranked = opaGetPublishedLeaderboard(getDb());
$result = [];
foreach ($ranked as $row) {
    $change = $row['rank_change'] !== null ? (int)$row['rank_change'] : null;
    $movement = $change === null ? 'new' : ($change > 0 ? 'up ' . $change : ($change < 0 ? 'down ' . abs($change) : 'same'));
    $result[] = [
        'squadron_id' => (int)$row['squadron_id'],
        'name' => $row['name'],
        'points' => (float)$row['opa'],
        'opa' => (float)$row['opa'],
        'current_rank' => (int)$row['rank'],
        'previous_rank' => $row['previous_rank'] !== null ? (int)$row['previous_rank'] : null,
        'movement' => $movement,
    ];
}
echo json_encode($result);
