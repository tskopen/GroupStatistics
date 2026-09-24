<?php
header('Content-Type: application/json');
require __DIR__ . '/config.php';

$db = getDb();

// Current rankings (shared logic with index.php / recordLeaderboardSnapshot()).
$ranked = getSquadronRankings();

// Most recent snapshot per squadron.
$stmt = $db->prepare('
    SELECT squadron_id, rank
    FROM leaderboard_snapshots ls
    WHERE snapshot_date = (
        SELECT snapshot_date FROM leaderboard_snapshots
        WHERE squadron_id = ls.squadron_id
        ORDER BY snapshot_date DESC
        LIMIT 1
    )
');
$stmt->execute();
$previousRanks = [];
foreach ($stmt->fetchAll() as $row) {
    $previousRanks[$row['squadron_id']] = (int) $row['rank'];
}

$result = [];
foreach ($ranked as $row) {
    $squadronId = $row['squadron_id'];
    $currentRank = $row['rank'];

    if (!array_key_exists($squadronId, $previousRanks)) {
        $previousRank = null;
        $movement = 'new';
    } else {
        $previousRank = $previousRanks[$squadronId];
        $diff = $previousRank - $currentRank;
        if ($diff > 0) {
            $movement = 'up ' . $diff;
        } elseif ($diff < 0) {
            $movement = 'down ' . abs($diff);
        } else {
            $movement = 'same';
        }
    }

    $result[] = [
        'squadron_id' => $squadronId,
        'name' => $row['name'],
        'points' => $row['total'],
        'current_rank' => $currentRank,
        'previous_rank' => $previousRank,
        'movement' => $movement,
    ];
}

echo json_encode($result);
