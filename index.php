<?php

header('Cache-Control: no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();

require_once __DIR__ . '/bracket-bootstrap.php';
$db = getDb();
bracketBootstrap($db);
bracketEnsureTables($db);

// Fetch squadrons
$stmt = $db->prepare("SELECT * FROM squadrons ORDER BY id");
$stmt->execute();
$squadrons = $stmt->fetchAll();
$squadronMap = [];
foreach ($squadrons as $squadron) {
    $squadronMap[(int)$squadron['id']] = $squadron;
}

// Fetch scores (all events)
$stmt = $db->prepare("SELECT * FROM events ORDER BY timestamp DESC");
$stmt->execute();
$scores = $stmt->fetchAll();

foreach ($scores as &$scoreRow) {
    $scoreRow['event_type'] = normalizeEventType($scoreRow['event_type'] ?? 'other');
}
unset($scoreRow);

// Restore the leaderboard data model used by the PR #61 homepage.
// Keep the SQLite scoring helper as the single source of truth.
$rankingRows = getSquadronRankings();
$ranked = [];
foreach ($rankingRows as $row) {
    $squadron = $squadronMap[(int)$row['squadron_id']] ?? null;