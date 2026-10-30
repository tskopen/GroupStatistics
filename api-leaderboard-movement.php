<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require __DIR__ . '/config.php';

$db = getDb();
$ranked = opaGetPublishedLeaderboard($db);
$historyBySquadron = opaGetRecentOpaHistoryBySquadron($db, 4);
$result = [];
foreach ($ranked as $row) {
    $squadronId = (int)$row['squadron_id'];
    $change = $row['rank_change'] !== null ? (int)$row['rank_change'] : null;
    $movement = $change === null ? 'new' : ($change > 0 ? 'up ' . $change : ($change < 0 ? 'down ' . abs($change) : 'same'));
    $result[] = [
        'squadron_id' => $squadronId,
        'name' => $row['name'],
        'points' => (float)$row['opa'],
        'opa' => (float)$row['opa'],
        'opa_change' => $row['opa_change'] !== null ? (float)$row['opa_change'] : null,
        'current_rank' => (int)$row['rank'],
        'previous_rank' => $row['previous_rank'] !== null ? (int)$row['previous_rank'] : null,
        'rank_change' => $change,
        'movement' => $movement,
        'history' => $historyBySquadron[$squadronId] ?? [],
    ];
}
echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
