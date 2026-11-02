<?php
// Flight vs Flight feature regression tests.
$flightDb = getDb();
$foreignKeysBefore = (int)$flightDb->query('PRAGMA foreign_keys')->fetchColumn();
$flightDb->exec('PRAGMA foreign_keys=ON');
$now = date('c');
$start = date('c', time() - 3600);
$end = date('c', time() + 86400);
$eventCountBeforeFlightTests = (int)$flightDb->query('SELECT COUNT(*) FROM events')->fetchColumn();
$publicationCountBeforeFlightTests = (int)$flightDb->query('SELECT COUNT(*) FROM scoring_publications')->fetchColumn();

$insertFlight = $flightDb->prepare('INSERT INTO flights(squadron_id,name,display_order,is_active,created_at,updated_at) VALUES(?,?,?,1,?,?)');
$insertFlight->execute([1, 'Alpha Flight Test', 1, $now, $now]);
$alphaFlightId = (int)$flightDb->lastInsertId();
$insertFlight->execute([1, 'Bravo Flight Test', 2, $now, $now]);
$bravoFlightId = (int)$flightDb->lastInsertId();
$insertFlight->execute([2, 'Squadron 2 Flight Test', 1, $now, $now]);
$otherSquadronFlightId = (int)$flightDb->lastInsertId();

$insertCompetition = $flightDb->prepare('INSERT INTO flight_competitions(squadron_id,title,description,starts_at,ends_at,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
$insertCompetition->execute([1, 'Published Test Competition', 'Published parent competition', $start, $end, 'published', $now, $now]);
$publishedCompetitionId = (int)$flightDb->lastInsertId();
$insertCompetition->execute([1, 'Draft Test Competition', 'Hidden parent competition', $start, $end, 'draft', $now, $now]);
$draftCompetitionId = (int)$flightDb->lastInsertId();

$insertResult = $flightDb->prepare('INSERT INTO flight_competition_results(competition_id,flight_id,squadron_id,title,description,points,awarded_at,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
$insertResult->execute([$publishedCompetitionId, $alphaFlightId, 1, 'Alpha result', 'Published points', 10, $now, 'published', $now, $now]);
$insertResult->execute([$publishedCompetitionId, $alphaFlightId, 1, 'Alpha award', 'Additional recognition', 5, $now, 'published', $now, $now]);
$insertResult->execute([$publishedCompetitionId, $bravoFlightId, 1, 'Bravo result', 'Published negative adjustment', -2, $now, 'published', $now, $now]);
$insertResult->execute([$publishedCompetitionId, $alphaFlightId, 1, 'Unpublished award', 'Must stay hidden', 100, $now, 'draft', $now, $now]);
$insertResult->execute([$draftCompetitionId, $alphaFlightId, 1, 'Draft competition result', 'Must stay hidden with its competition', 500, $now, 'published', $now, $now]);

$boardStmt = $flightDb->prepare(
    "SELECT f.id,f.squadron_id,COALESCE(SUM(CASE WHEN c.status='published' AND r.status='published' THEN r.points ELSE 0 END),0) AS total_points
     FROM flights f
     LEFT JOIN flight_competitions c ON c.squadron_id=f.squadron_id
     LEFT JOIN flight_competition_results r ON r.competition_id=c.id AND r.flight_id=f.id AND r.squadron_id=f.squadron_id
     WHERE f.squadron_id=? AND f.is_active=1
     GROUP BY f.id"
);
$boardStmt->execute([1]);
$boardRows = $boardStmt->fetchAll(PDO::FETCH_ASSOC);
$totals = [];
foreach ($boardRows as $row) $totals[(int)$row['id']] = (float)$row['total_points'];
check(isset($totals[$alphaFlightId]) && abs($totals[$alphaFlightId] - 15.0) < 0.000001,
    'flight leaderboard totals include only published results from published competitions');
check(isset($totals[$bravoFlightId]) && abs($totals[$bravoFlightId] + 2.0) < 0.000001,
    'flight leaderboard preserves manually assigned negative point adjustments');
check(!isset($totals[$otherSquadronFlightId]),
    'squadron flight leaderboard never includes flights from another squadron');

$crossSquadronRejected = false;
try {
    $insertResult->execute([$publishedCompetitionId, $otherSquadronFlightId, 1, 'Cross squadron attempt', 'Must be rejected', 99, $now, 'published', $now, $now]);
} catch (PDOException $e) {
    $crossSquadronRejected = true;
}
check($crossSquadronRejected,
    'composite foreign keys prevent a competition from assigning results to another squadron flight');

check((int)$flightDb->query('SELECT COUNT(*) FROM events')->fetchColumn() === $eventCountBeforeFlightTests
    && (int)$flightDb->query('SELECT COUNT(*) FROM scoring_publications')->fetchColumn() === $publicationCountBeforeFlightTests,
    'creating and scoring flight competitions does not write to event or OPA ledgers');

$directorySource = file_get_contents(__DIR__ . '/../squadron-pages.php');
$squadronSource = file_get_contents(__DIR__ . '/../squadron-page.php');
$adminFlightSource = file_get_contents(__DIR__ . '/../admin-flight-competition.php');
$meetGroupSource = file_get_contents(__DIR__ . '/../meet-the-group.php');
$adminPanelFlightSource = file_get_contents(__DIR__ . '/../admin-panel.php');
$homepageFlightSource = file_get_contents(__DIR__ . '/../index.php');

check($directorySource !== false
    && str_contains($directorySource, 'FROM squadrons s')
    && str_contains($directorySource, 'squadron-page.php?squadron_id='),
    'squadron directory lists the existing squadrons and links to isolated squadron pages');
check($squadronSource !== false
    && str_contains($squadronSource, "c.status='published'")
    && str_contains($squadronSource, "r.status='published'")
    && str_contains($squadronSource, 'WHERE f.squadron_id=?'),
    'public squadron pages display only their squadron flights and published competition results');
check($adminFlightSource !== false
    && str_contains($adminFlightSource, "if (empty(\$_SESSION['admin']))")
    && str_contains($adminFlightSource, "require_csrf();")
    && str_contains($adminFlightSource, 'delete_result')
    && str_contains($adminFlightSource, 'toggle_result'),
    'flight competition management is admin-only and supports protected result publication and deletion');
check($meetGroupSource !== false && str_contains($meetGroupSource, 'squadron-pages.php')
    && $adminPanelFlightSource !== false && str_contains($adminPanelFlightSource, 'admin-flight-competition.php'),
    'squadron directory is linked from Meet the Group and flight management is linked from the admin panel');
check($homepageFlightSource !== false && !str_contains($homepageFlightSource, 'Flight vs Flight')
    && !str_contains($homepageFlightSource, 'squadron-pages.php'),
    'Flight vs Flight remains out of the homepage and general group event board');

$flightDb->exec('PRAGMA foreign_keys=' . ($foreignKeysBeforeFlightTests ? 'ON' : 'OFF'));
