<?php

header('Cache-Control: no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();

require_once __DIR__ . '/bracket-bootstrap.php';
$db = getDb();

// A small, public-facing preview makes the announcement board easy to discover.
// This is a separate table from scoring events and never changes competition totals.
$announcementNow = (new DateTimeImmutable('now', new DateTimeZone('America/Denver')))->format('Y-m-d H:i:s');
$homepageAnnouncementsStmt = $db->prepare(
    "SELECT id, title, start_at, end_at, location FROM group_announcements
     WHERE status = 'published' AND start_at IS NOT NULL AND start_at >= ?
     ORDER BY start_at ASC, id ASC
     LIMIT 1"
);
$homepageAnnouncementsStmt->execute([$announcementNow]);
$homepageAnnouncements = $homepageAnnouncementsStmt->fetchAll(PDO::FETCH_ASSOC);

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
$themeSquadron = !empty($theme['selected_squadron_id'])
    ? ($squadronMap[(int)$theme['selected_squadron_id']] ?? null)
    : null;
$groupIconSettings = readJson(DATA_DIR . '/group-icon.json');
$groupIconFilename = $groupIconSettings['filename'] ?? null;

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
$rankingRows = opaGetPublishedLeaderboard($db);
$ranked = [];
foreach ($rankingRows as $row) {
    $squadron = $squadronMap[(int)$row['squadron_id']] ?? null;
    if (!$squadron) continue;
    $ranked[] = [
        'squadron' => $squadron,
        'total' => $row['total'],
        'rank' => (int)$row['rank'],
    ];
}

// Brackets are loaded from the relational bracket tables. The existing
// tournament-card markup below is retained, so old homepage presentation
// continues to work while SQLite remains the source of truth.
$bracketsByTournament = [];
$bracketRows = $db->query("SELECT id,name,created_date,updated_at,champion_id,bracket_type FROM brackets ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($bracketRows as $bracketRow) {
    $bracket = bracketLoad($db, (string)$bracketRow['id']);
    if (!$bracket) continue;
    $tournament = [
        'tournament_name' => $bracket['name'] ?? 'Bracket',
        'bracket_type' => $bracket['bracket_type'] ?? 'multi_round',
        'champion_id' => $bracket['champion_id'] ?? null,
        'matches' => [],
        'latest_timestamp' => $bracket['updated_at'] ?? $bracket['created_date'] ?? 0,
    ];
    foreach (($bracket['rounds'] ?? []) as $round) {
        foreach (($round['matchups'] ?? []) as $matchup) {
            $tournament['matches'][] = [
                'round_name' => $round['name'] ?? 'Round',
                'matchup_id' => $matchup['id'] ?? null,
                'team1_source_matchup_id' => $matchup['team1_source_matchup_id'] ?? null,
                'team2_source_matchup_id' => $matchup['team2_source_matchup_id'] ?? null,
                'match_number' => $matchup['match_number'] ?? null,
                'squadron_id' => $matchup['team1_id'] ?? null,
                'opponent_id' => $matchup['team2_id'] ?? null,
                'team1_score' => $matchup['team1_score'] ?? '-',
                'team2_score' => $matchup['team2_score'] ?? '-',
                'winner_id' => $matchup['winner_id'] ?? null,
                'status' => $matchup['status'] ?? 'pending',
                'value' => $matchup['points'] ?? 0,
            ];
        }
    }
    if (!empty($tournament['matches'])) $bracketsByTournament[] = $tournament;
}

// Regular events still come from scores.json, newest first.
$regularEvents = array_values(array_filter(
    $scores,
    fn($event) => !in_array($event['event_type'] ?? 'other', ['bracket', 'cadet_of_month'], true)
));

// Group SAMI events by event_name into a single card per round.
$samisByEvent = [];
$samisGroups = [];
foreach ($regularEvents as $event) {
    if (($event['event_type'] ?? '') !== 'samis') {
        continue;
    }
    $eventName = $event['event_name'] ?? 'Samis';
    if (!isset($samisGroups[$eventName])) {
        $samisGroups[$eventName] = [
            'event_name' => $eventName,
            'results' => [],
            'latest_timestamp' => 0,
        ];
    }
    $samisGroups[$eventName]['results'][] = [
        'squadron_id' => $event['squadron_id'] ?? null,
        'value' => $event['value'] ?? null,
        'timestamp' => $event['timestamp'] ?? null,
    ];
    $ts = strtotime($event['timestamp'] ?? '') ?: 0;
    if ($ts > $samisGroups[$eventName]['latest_timestamp']) {
        $samisGroups[$eventName]['latest_timestamp'] = $ts;
    }
}
$samisByEvent = array_values($samisGroups);

// Group PFT events by event_name into a single card per PFT round
$pftByEvent = [];
$pftGroups = [];
foreach ($regularEvents as $event) {
    if (($event['event_type'] ?? '') !== 'pft') {
        continue;
    }
    $eventName = $event['event_name'] ?? 'PFT';
    if (!isset($pftGroups[$eventName])) {
        $pftGroups[$eventName] = [
            'event_name' => $eventName,
            'event_type' => 'pft',
            'results' => [],
            'latest_timestamp' => 0,
        ];
    }
    $pftGroups[$eventName]['results'][] = [
        'squadron_id' => $event['squadron_id'] ?? null,
        'value' => $event['value'] ?? null,
        'timestamp' => $event['timestamp'] ?? null,
    ];
    $ts = strtotime($event['timestamp'] ?? '') ?: 0;
    if ($ts > $pftGroups[$eventName]['latest_timestamp']) {
        $pftGroups[$eventName]['latest_timestamp'] = $ts;
    }
}
$pftByEvent = array_values($pftGroups);

// Group other events by event_name into a single card per event
$otherByEvent = [];
$otherGroups = [];
foreach ($regularEvents as $event) {
    if (($event['event_type'] ?? '') !== 'other') {
        continue;
    }
    $eventName = $event['event_name'] ?? 'Other Event';
    if (!isset($otherGroups[$eventName])) {
        $otherGroups[$eventName] = [
            'event_name' => $eventName,
            'event_type' => 'other',
            'results' => [],
            'latest_timestamp' => 0,
        ];
    }
    $otherGroups[$eventName]['results'][] = [
        'squadron_id' => $event['squadron_id'] ?? null,
        'value' => $event['value'] ?? null,
        'timestamp' => $event['timestamp'] ?? null,
    ];
    $ts = strtotime($event['timestamp'] ?? '') ?: 0;
    if ($ts > $otherGroups[$eventName]['latest_timestamp']) {
        $otherGroups[$eventName]['latest_timestamp'] = $ts;
    }
}
$otherByEvent = array_values($otherGroups);

// Rank squadrons within each grouped event card by points awarded, highest first.
$sortEventResults = function (&$eventGroups) {
    foreach ($eventGroups as &$eventGroup) {
        usort($eventGroup['results'], function ($a, $b) {
            return (float) ($b['value'] ?? 0) <=> (float) ($a['value'] ?? 0);
        });
    }
    unset($eventGroup);
};
$sortEventResults($samisByEvent);
$sortEventResults($pftByEvent);
$sortEventResults($otherByEvent);
unset($sortEventResults);

// Non-SAMI/PFT/other regular events stay in regularEvents (no change to their display).
$regularEvents = array_values(array_filter(
    $regularEvents,
    fn($event) => !in_array($event['event_type'] ?? '', ['pft', 'other', 'samis'])
));

// Sort tournaments by most recent first
usort(
    $bracketsByTournament,
    fn($a, $b) => ($b['latest_timestamp'] ?? 0) <=> ($a['latest_timestamp'] ?? 0)
);

// Sort samis groups by most recent first
usort(
    $samisByEvent,
    fn($a, $b) => ($b['latest_timestamp'] ?? 0) <=> ($a['latest_timestamp'] ?? 0)
);

// Sort PFT groups by most recent first
usort(
    $pftByEvent,
    fn($a, $b) => ($b['latest_timestamp'] ?? 0) <=> ($a['latest_timestamp'] ?? 0)
);

// Sort other groups by most recent first
usort(
    $otherByEvent,
    fn($a, $b) => ($b['latest_timestamp'] ?? 0) <=> ($a['latest_timestamp'] ?? 0)
);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>USAFA Group 1 Squadron Tracker</title>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="<?php echo htmlspecialchars($theme['primary_color'] ?? '#002147', ENT_QUOTES, 'UTF-8'); ?>">
<meta name="description" content="Track USAFA Group 1 squadron competition scores and tournament brackets">

<!-- iOS PWA support -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Squadron Tracker">
<link rel="apple-touch-icon" href="assets/pwa-icon-192.png">
<style>
    :root {
        --primary-color: <?php echo htmlspecialchars($theme['primary_color']); ?>;
        --secondary-color: <?php echo htmlspecialchars($theme['secondary_color']); ?>;
        --accent-color: <?php echo htmlspecialchars($theme['accent_color']); ?>;
        --background-color: <?php echo htmlspecialchars($theme['background_color']); ?>;
        --text-color: <?php echo htmlspecialchars($theme['text_color']); ?>;
        --surface-color: color-mix(in srgb, var(--background-color), white 92%);
        --border-color: color-mix(in srgb, var(--primary-color), var(--background-color) 78%);
        --muted-color: color-mix(in srgb, var(--text-color), var(--background-color) 42%);
        --soft-accent-color: color-mix(in srgb, var(--accent-color), white 88%);
        --soft-primary-color: color-mix(in srgb, var(--primary-color), white 92%);
    }
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; background: var(--background-color); margin: 0; padding: 0; color: var(--text-color); }
    .container { width: calc(100% - 40px); max-width: 1200px; margin: 0 auto; }
    @media (max-width:520px) { body { padding:0; } .container { width:calc(100% - 24px); } }
    h2 { color: var(--primary-color); border-bottom: 2px solid var(--primary-color); padding-bottom: 8px; margin-top: 30px; }
    
    .rankings-table { width: 100%; border-collapse: collapse; background: var(--surface-color); box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 30px; }
    .rankings-table th { background: var(--primary-color); color: #fff; padding: 12px; text-align: left; }
    .rankings-table td { padding: 12px; border-bottom: 1px solid #ddd; }
    .rankings-table tr:nth-child(2) { background: var(--soft-accent-color); font-weight: bold; }
    .rankings-table tr:hover { background: var(--soft-primary-color); }
    .icon { width: 40px; height: 40px; border-radius: 4px; object-fit: cover; margin-right: 10px; vertical-align: middle; }
    .icon-placeholder { width: 40px; height: 40px; border-radius: 4px; background: var(--border-color); display: inline-block; margin-right: 10px; vertical-align: middle; }

    /* Leaderboard movement indicators */
    .movement-up { color: #1a7a1a; font-weight: bold; }
    .movement-down { color: #b00020; font-weight: bold; }
    .movement-same { color: #999; font-weight: bold; }
    .movement-new { color: #003366; font-weight: bold; background: #e3f2fd; padding: 2px 6px; border-radius: 3px; font-size: 0.85em; }
    .opa-history { margin-top: 4px; color: var(--muted-color); font-size: .72rem; font-weight: 500; line-height: 1.35; white-space: normal; }
    .movement-cell { min-width: 132px; line-height: 1.25; }
    .movement-opa { display: block; white-space: nowrap; }
    .movement-rank-meta { display: block; margin-top: 4px; color: var(--muted-color); font-size: .72rem; font-weight: 500; }

    .details-btn { padding: 6px 12px; background: var(--primary-color); color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85em; }
    .details-btn:hover { background: var(--secondary-color); }

    /* Score breakdown modal */
    .breakdown-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 20px; }
    .breakdown-modal { background: var(--surface-color); border-radius: 10px; max-width: 1080px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 26px; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
    .breakdown-modal-close { position: absolute; top: 12px; right: 15px; background: none; border: none; font-size: 1.5em; cursor: pointer; color: #666; z-index: 2; }
    .breakdown-modal-close:hover { color: #000; }
    .breakdown-section { margin-top: 18px; }
    .breakdown-section h4 { margin: 0 0 10px; color: var(--primary-color); }
    .breakdown-total { margin-top: 10px; font-weight: bold; text-align: right; }
    .breakdown-comparison-controls { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 12px; margin: 16px 0; padding: 14px; border: 1px solid var(--border-color,#d8dee8); border-radius: 8px; }
    .breakdown-control { display: flex; flex-direction: column; gap: 6px; font-size: .88em; font-weight: 700; }
    .breakdown-control select { min-width: 0; width: 100%; padding: 9px 10px; border: 1px solid var(--border-color,#cbd5e1); border-radius: 6px; background: var(--surface-color,#fff); color: var(--text-color,#222); }
    .breakdown-summary-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 12px; }
    .breakdown-publication-card { border: 1px solid var(--border-color,#d8dee8); border-radius: 8px; padding: 14px; min-width: 0; }
    .breakdown-publication-card h5 { color: var(--primary-color); margin: 0 0 8px; }
    .breakdown-publication-card .breakdown-opa { font-size: 1.55em; font-weight: 800; margin: 4px 0; }
    .breakdown-publication-card .breakdown-meta { display: block; color: var(--muted-color,#667085); font-size: .82em; margin-top: 5px; overflow-wrap: anywhere; }
    .breakdown-change-card { background: var(--soft-primary-color,#f4f7fb); }
    .breakdown-category-list { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 12px; }
    .breakdown-category-card { border: 1px solid var(--border-color,#d8dee8); border-radius: 8px; padding: 14px; min-width: 0; }
    .breakdown-category-card h5 { margin: 0 0 10px; color: var(--primary-color); font-size: 1rem; }
    .breakdown-category-columns { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 10px; }
    .breakdown-category-period { font-size: .78em; font-weight: 800; color: var(--primary-color); margin-bottom: 7px; }
    .breakdown-category-value { margin: 4px 0; font-size: .86em; overflow-wrap: anywhere; }
    .breakdown-category-source { color: var(--muted-color,#667085); font-size: .76em; margin-top: 8px; line-height: 1.45; overflow-wrap: anywhere; }
    .breakdown-category-change { background: var(--soft-primary-color,#f7f9fc); border-radius: 6px; padding: 8px; }
    .breakdown-positive { color: #18713b; font-weight: 800; }
    .breakdown-negative { color: #a51f35; font-weight: 800; }
    .breakdown-neutral { color: var(--muted-color,#667085); font-weight: 700; }
    .breakdown-explanation { color: var(--muted-color,#667085); font-size: .84em; line-height: 1.5; margin: 8px 0; }
    .breakdown-quick-table-wrap { overflow-x: auto; border: 1px solid var(--border-color,#d8dee8); border-radius: 7px; }
    .breakdown-quick-table { width: 100%; min-width: 520px; border-collapse: collapse; font-size: .86em; }
    .breakdown-quick-table th, .breakdown-quick-table td { padding: 9px 11px; text-align: left; border-bottom: 1px solid var(--border-color,#e5e7eb); }
    .breakdown-quick-table thead th { background: var(--soft-primary-color,#f4f7fb); color: var(--primary-color); white-space: nowrap; }
    .breakdown-quick-table tbody tr:last-child th, .breakdown-quick-table tbody tr:last-child td { border-bottom: 0; }
    @media(max-width:720px) {
        .breakdown-modal { padding: 20px 14px; max-height: 94vh; }
        .breakdown-summary-grid { grid-template-columns: 1fr; }
        .breakdown-category-list { grid-template-columns: 1fr; }
        .breakdown-category-columns { grid-template-columns: repeat(2,minmax(0,1fr)); }
        .breakdown-category-change { grid-column: 1 / -1; }
        .breakdown-comparison-controls { grid-template-columns: 1fr; }
    }
    
    /* Event Cards Grid */
    .events-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    @media (max-width: 768px) { .events-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 480px) { .events-grid { grid-template-columns: 1fr; } }
    
    .event-card { background: var(--surface-color); border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
    .event-card:hover { transform: translateY(-4px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    
    .event-header { background: var(--secondary-color); color: #fff; padding: 12px; font-weight: bold; font-size: 0.9em; text-align: center; }
    .event-body { padding: 15px; }
    
    /* Tournament card — one card per tournament; rounds live inside it. */
    .events-grid { align-items: start; }
    .events-grid > * { align-self: start; height: max-content; }
    .tournament-card { background:var(--surface-color); border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.1); overflow:hidden; grid-column:span 2; align-self:start; height:max-content; }
    @media (max-width:768px) { .tournament-card { grid-column:1 / -1; } }
    .tournament-header { background:var(--primary-color); color:#fff; padding:16px; font-weight:bold; font-size:1.3em; text-align:center; }
    .tournament-body { padding:15px; }
    .bracket-round-section { margin:0 0 16px; }
    .bracket-round-section:last-child { margin-bottom:0; }
    .bracket-round-title { margin:0 0 8px; padding:7px 10px; border-bottom:2px solid #e1e5ea; color:var(--primary-color); font-size:.82em; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .tournament-match { display:grid; grid-template-columns:minmax(0,1fr) minmax(110px,auto) minmax(0,1fr); align-items:center; gap:12px; padding:12px; margin-bottom:10px; border-radius:6px; background:#f9f9f9; }
    .tournament-match:last-child { margin-bottom:0; }
    .match-team { min-width:0; display:flex; align-items:center; gap:10px; }
    .match-team.team-right { flex-direction:row-reverse; text-align:right; }
    .match-team-icon { width:45px; height:45px; border-radius:4px; object-fit:cover; flex-shrink:0; }
    .match-team-name { font-weight:bold; font-size:.95em; overflow-wrap:anywhere; }
    .match-team-source { display:block; font-size:.72em; color:#777; font-weight:600; margin-top:2px; }
    .match-score-block { display:grid; grid-template-columns:auto auto auto; grid-template-rows:auto auto; align-items:center; justify-items:center; gap:2px 8px; min-width:110px; font-size:1.3em; font-weight:bold; color:#002147; padding:0 8px; }
    .match-vs-label { font-weight:bold; color:#999; font-size:.9em; }
    .match-points { grid-column:1 / -1; font-size:.75em; color:#666; margin-top:2px; text-align:center; white-space:nowrap; }
    .match-winner { background:var(--accent-color); }
    .match-winner-check { color:#28a745; font-weight:bold; margin-left:6px; }
    .bracket-meta { font-size:.8em; color:#68727e; text-align:center; margin:-5px 0 12px; }
    @media (max-width:600px) {
        .tournament-match { grid-template-columns:minmax(0,1fr) auto minmax(0,1fr); gap:6px; }
        .match-score-block { min-width:88px; padding:0 3px; gap:2px 5px; }
        .match-team-icon { width:38px; height:38px; }
    }    
    /* SAMI card (all squadron results for one SAMI round grouped together) */
    .sami-card { background: var(--surface-color); border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; grid-column: span 2; }
    @media (max-width: 768px) { .sami-card { grid-column: 1 / -1; } }
    .sami-header { background: var(--secondary-color); color: #fff; padding: 16px; font-weight: bold; font-size: 1.3em; text-align: center; }
    .sami-body { padding: 15px; }
    
    .sami-result { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px; margin-bottom: 10px; border-radius: 6px; background: #f9f9f9; }
    .sami-result:last-child { margin-bottom: 0; }
    .sami-result-icon { width: 45px; height: 45px; border-radius: 4px; object-fit: cover; flex-shrink: 0; }
    .sami-result-info { flex: 1; display: flex; align-items: center; gap: 10px; }
    .sami-result-name { font-weight: bold; font-size: 0.95em; text-align: left; }
    .sami-result-score { font-weight: bold; font-size: 1.3em; color: var(--accent-color); text-align: right; }
    
    .sami-timestamp { font-size: 0.75em; color: #666; margin-top: 10px; text-align: center; }
    
    /* Regular event card */
    .regular-event { text-align: center; }
    .regular-event-icon { width: 60px; height: 60px; margin: 0 auto 10px; border-radius: 4px; object-fit: cover; }
    .regular-event-name { font-weight: bold; margin: 5px 0; }
    .regular-event-type { display: inline-block; background: var(--soft-accent-color); color: var(--primary-color); padding: 4px 8px; border-radius: 3px; font-size: 0.75em; margin-bottom: 8px; }
    .regular-event-score { font-size: 1.5em; font-weight: bold; color: var(--accent-color); }
    
    .announcement-preview { margin: 0 auto 26px; padding: 18px; background: var(--surface-color); border: 1px solid var(--border-color); border-left: 5px solid var(--accent-color); border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
    .announcement-preview-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
    .announcement-preview h2 { margin:0; border:0; padding:0; font-size:1.35rem; }
     .announcement-countdown { display:inline-block; margin:12px 0 4px; padding:12px 16px; border-radius:9px; background:var(--soft-primary-color); border:1px solid var(--border-color); color:var(--primary-color); font-size:clamp(1.25rem, 3vw, 1.75rem); font-weight:800; font-variant-numeric:tabular-nums; letter-spacing:.02em; }
     .announcement-countdown.is-started { background:var(--soft-accent-color); border-color:var(--accent-color); color:var(--primary-color); }
    .announcement-preview-link { display:inline-block; color:#fff; background:var(--primary-color); border-radius:6px; padding:8px 12px; text-decoration:none; font-weight:bold; font-size:.88rem; }
    .announcement-preview-list { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr)); gap:10px; }
    .announcement-preview-item { padding:12px; background:var(--soft-primary-color); border-radius:7px; border:1px solid var(--border-color); min-width:0; }
    .announcement-preview-item strong { display:block; color:var(--primary-color); overflow-wrap:anywhere; }
    .announcement-preview-item small { display:block; color:#596579; margin-top:4px; }
    .events-toggle-wrap { text-align: center; margin: -10px 0 30px; }
    .events-toggle-button { padding: 10px 18px; border: 0; border-radius: 6px; background: var(--primary-color); color: #fff; font-weight: bold; cursor: pointer; }
    .events-toggle-button:hover, .events-toggle-button:focus-visible { background: var(--secondary-color); }
    .homepage-hotbar {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 10px;
        margin: 18px 0 22px;
        padding: 12px;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        background: var(--surface-color);
        box-shadow: 0 2px 8px rgba(0,0,0,.07);
    }
    .homepage-hotbar-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 42px;
        padding: 10px 16px;
        border: 1px solid var(--primary-color);
        border-radius: 7px;
        background: var(--surface-color);
        color: var(--primary-color);
        font-weight: 700;
        text-decoration: none;
        text-align: center;
        transition: background .15s ease, color .15s ease, transform .15s ease;
    }
    .homepage-hotbar-button:hover, .homepage-hotbar-button:focus-visible {
        background: var(--soft-primary-color);
        text-decoration: none;
        transform: translateY(-1px);
    }
    .homepage-hotbar-button.homepage-hotbar-featured {
        background: var(--primary-color);
        color: #fff;
    }
    .homepage-hotbar-button.homepage-hotbar-featured:hover,
    .homepage-hotbar-button.homepage-hotbar-featured:focus-visible {
        background: var(--secondary-color);
        color: #fff;
    }
    @media (max-width: 620px) {
        .homepage-hotbar { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); }
        .homepage-hotbar-button { padding: 10px 8px; font-size: .9rem; }
    }
    .footer { text-align: center; margin-top: 40px; }
    .footer a { color: var(--primary-color); text-decoration: none; margin: 0 15px; }
    .footer a:hover { text-decoration: underline; }
</style>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
<div class="container">
    <nav class="homepage-hotbar" aria-label="Quick links">
        <a class="homepage-hotbar-button homepage-hotbar-featured" href="cadet-of-month.php">⭐ View Cadet of the Month</a>
        <a class="homepage-hotbar-button" href="announcements.php">📣 Group Announcements</a>
        <a class="homepage-hotbar-button" href="awards.php">🏅 Challenge Award</a>
        <a class="homepage-hotbar-button" href="notification-preferences.php">🔔 Notifications</a>
    </nav>
    <section class="announcement-preview" aria-labelledby="announcements-preview-title">
        <div class="announcement-preview-head">
            <h2 id="announcements-preview-title">📣 Next Group Event</h2>
            <a class="announcement-preview-link" href="announcements.php">View all announcements →</a>
        </div>
        <?php if ($homepageAnnouncements): ?>
        <div class="announcement-preview-list">
            <?php foreach ($homepageAnnouncements as $announcement): ?>
            <article class="announcement-preview-item">
                <strong><?php echo htmlspecialchars($announcement['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></strong>
                <small><?php echo !empty($announcement['start_at']) ? '🗓 ' . htmlspecialchars((new DateTimeImmutable($announcement['start_at'], new DateTimeZone('America/Denver')))->format('M j, Y · g:i A T'), ENT_QUOTES, 'UTF-8') : 'Date to be announced'; ?></small>
                <?php if (!empty($announcement['start_at'])): ?>
                <div class="announcement-countdown" data-countdown-target="<?php echo (int)(new DateTimeImmutable($announcement['start_at'], new DateTimeZone('America/Denver')))->getTimestamp(); ?>" aria-live="off">Countdown loading…</div>
                <?php endif; ?>
                <?php if (!empty($announcement['location'])): ?><small>📍 <?php echo htmlspecialchars($announcement['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></small><?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="margin:0;color:#64748b;">No upcoming dated events. Visit the announcement board for all group announcements.</p>
        <?php endif; ?>
    </section>
    
    <h2>Overall Rankings</h2>
    <table class="rankings-table" id="rankings-table">
        <tr>
            <th>Rank</th>
            <th>Squadron</th>
            <th>Overall OPA</th>
            <th>OPA Change</th>
            <th>Details</th>
        </tr>
        <?php $rank = 1; foreach ($ranked as $entry): $s = $entry['squadron']; ?>
        <tr data-squadron-id="<?php echo htmlspecialchars((string) $s['id']); ?>">
            <td>#<?php echo (int)($entry['rank'] ?? $rank); ?></td>
            <td>
                <?php if (!empty($s['icon_filename'])): ?>
                    <img src="<?php echo htmlspecialchars(iconUrl($s['icon_filename'])); ?>" alt="icon" class="icon">
                <?php else: ?>
                    <span class="icon-placeholder"></span>
                <?php endif; ?>
                <?php echo htmlspecialchars($s['name']); ?>
            </td>
            <td class="opa-value-cell">
                <strong><?php echo number_format((float)$entry['total'], 2); ?>%</strong>
                <div class="opa-history" data-opa-history="<?php echo htmlspecialchars((string) $s['id']); ?>" aria-label="Recent OPA history"></div>
            </td>
            <td class="movement-cell" data-squadron-id="<?php echo htmlspecialchars((string) $s['id']); ?>">—</td>
            <td>
                <button type="button" class="details-btn" data-squadron-id="<?php echo htmlspecialchars((string) $s['id']); ?>" data-squadron-name="<?php echo htmlspecialchars($s['name']); ?>">Details</button>
            </td>
        </tr>
        <?php $rank++; endforeach; ?>
        <?php if (!$ranked): ?><tr><td colspan="5" style="text-align:center;padding:22px;color:#667085;">Official OPA scores have not been published yet.</td></tr><?php endif; ?>
    </table>
    <p style="text-align:right;margin-top:-18px;"><a href="score-history.php" style="color:var(--primary-color);font-weight:bold;">View publication history →</a></p>

    <!-- Score breakdown modal -->
    <div id="breakdown-modal" class="breakdown-modal-overlay" style="display:none;">
        <div class="breakdown-modal">
            <button type="button" class="breakdown-modal-close" id="breakdown-modal-close">&times;</button>
            <h3 id="breakdown-modal-title">Score Breakdown</h3>
            <div id="breakdown-modal-body">Loading…</div>
        </div>
    </div>
    
    
<?php
    // Check if any intramural records exist
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM intramural_wl_records");
    $stmt->execute();
    $result = $stmt->fetch();
    $hasIntramurals = $result['cnt'] > 0;
    ?>
    
    <?php if ($hasIntramurals): ?>
    <h2>🏅 Intramural Standings</h2>
    <p style="text-align: center; margin-bottom: 20px;">
        <a href="intramural-standings.php" style="color: var(--primary-color); text-decoration: none; font-weight: bold;">View Full Intramural Standings →</a>
    </p>
    <?php endif; ?>

    <?php if ($bracketsByTournament || $samisByEvent || $pftByEvent || $otherByEvent || $regularEvents): ?>
    <h2>🔥 Recent Events &amp; Results</h2>
    <div class="events-grid">
        <?php /* Tournaments are shown first (most recent competitions), followed by
                 regular events. Both lists are pre-sorted newest-first above, so within
                 each section the most recent activity always appears first. */ ?>
        <?php foreach ($bracketsByTournament as $tournament): ?>
        <div class="tournament-card" style="order: <?php echo -((int) (strtotime($tournament['latest_timestamp'] ?? '') ?: 0)); ?>;">
            <div class="tournament-header">🏆 <?php echo htmlspecialchars($tournament['tournament_name']); ?></div>
            <div class="tournament-body">
                <?php if (($tournament['bracket_type'] ?? 'single_round') === 'multi_round'): ?>
                    <div class="bracket-meta">Multi-Round Elimination · winners advance automatically</div>
                <?php endif; ?>
                <?php
                $roundGroups = [];
                $matchLabels = [];
                foreach ($tournament['matches'] as $match) {
                    $roundKey = $match['round_name'] ?? 'Round';
                    $roundGroups[$roundKey][] = $match;
                    if (!empty($match['matchup_id'])) {
                        $matchLabels[$match['matchup_id']] = $roundKey . ' Match ' . (int)($match['match_number'] ?? 0);
                    }
                }
                foreach ($roundGroups as $roundName => $roundMatches):
                ?>
                    <section class="bracket-round-section">
                        <?php if (($tournament['bracket_type'] ?? 'single_round') === 'multi_round'): ?>
                            <h3 class="bracket-round-title"><?php echo htmlspecialchars($roundName); ?></h3>
                        <?php endif; ?>
                        <?php foreach ($roundMatches as $match):
                            $t1 = $squadronMap[$match['squadron_id']] ?? null;
                            $t2 = $squadronMap[$match['opponent_id']] ?? null;
                            $winnerId = $match['winner_id'] ?? null;
                            $t1IsWinner = $winnerId && $winnerId === $match['squadron_id'];
                            $t2IsWinner = $winnerId && $winnerId === $match['opponent_id'];
                            $pointsAwarded = $match['value'] ?? 0;
                            $t1Source = $match['team1_source_matchup_id'] ?? null;
                            $t2Source = $match['team2_source_matchup_id'] ?? null;
                            $t1Label = $t1['name'] ?? null;
                            $t2Label = $t2['name'] ?? null;
                        ?>
                        <div class="tournament-match">
                            <div class="match-team <?php echo $t1IsWinner ? 'match-winner' : ''; ?>">
                                <?php if ($t1 && !empty($t1['icon_filename'])): ?>
                                    <img src="<?php echo htmlspecialchars(iconUrl($t1['icon_filename'])); ?>" alt="icon" class="match-team-icon">
                                <?php else: ?>
                                    <div class="match-team-icon" style="background:#ccc;"></div>
                                <?php endif; ?>
                                <span class="match-team-name">
                                    <?php echo htmlspecialchars($t1Label ?? ($t1Source ? 'TBD' : 'TBD')); ?>
                                    <?php if ($t1Source && $match['status'] !== 'completed'): ?><span class="match-team-source">Winner of <?php echo htmlspecialchars($matchLabels[$t1Source] ?? 'Match'); ?></span><?php endif; ?>
                                    <?php if ($t1IsWinner): ?><span class="match-winner-check">✓</span><?php endif; ?>
                                </span>
                            </div>
                            <div class="match-score-block">
                                <span><?php echo htmlspecialchars((string)($match['team1_score'] ?? '-')); ?></span>
                                <span class="match-vs-label">—</span>
                                <span><?php echo htmlspecialchars((string)($match['team2_score'] ?? '-')); ?></span>
                                <?php if ($pointsAwarded): ?><span class="match-points">+<?php echo htmlspecialchars((string)$pointsAwarded); ?> pts</span><?php endif; ?>
                            </div>
                            <div class="match-team team-right <?php echo $t2IsWinner ? 'match-winner' : ''; ?>">
                                <?php if ($t2 && !empty($t2['icon_filename'])): ?>
                                    <img src="<?php echo htmlspecialchars(iconUrl($t2['icon_filename'])); ?>" alt="icon" class="match-team-icon">
                                <?php else: ?>
                                    <div class="match-team-icon" style="background:#ccc;"></div>
                                <?php endif; ?>
                                <span class="match-team-name">
                                    <?php if ($t2IsWinner): ?><span class="match-winner-check">✓</span><?php endif; ?>
                                    <?php echo htmlspecialchars($t2Label ?? 'TBD'); ?>
                                    <?php if ($t2Source && $match['status'] !== 'completed'): ?><span class="match-team-source">Winner of <?php echo htmlspecialchars($matchLabels[$t2Source] ?? 'Match'); ?></span><?php endif; ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php foreach ($samisByEvent as $sami): ?>
        <!-- SAMI events (all squadrons for one round, newest first) -->
        <div class="sami-card" style="order: <?php echo -((int) ($sami['latest_timestamp'] ?? 0)); ?>;">
            <div class="sami-header">🏅 <?php echo htmlspecialchars($sami['event_name']); ?></div>
            <div class="sami-body">
                <?php foreach ($sami['results'] as $result):
                    $squad = $squadronMap[$result['squadron_id']] ?? null;
                ?>
                <div class="sami-result">
                    <div class="sami-result-info">
                        <?php if ($squad && !empty($squad['icon_filename'])): ?>
                            <img src="<?php echo htmlspecialchars(iconUrl($squad['icon_filename'])); ?>" alt="icon" class="sami-result-icon">
                        <?php else: ?>
                            <div class="sami-result-icon" style="background:#ccc;"></div>
                        <?php endif; ?>
                        <span class="sami-result-name"><?php echo htmlspecialchars($squad['name'] ?? 'Unknown'); ?></span>
                    </div>
                    <div class="sami-result-score"><?php echo htmlspecialchars((string)($result['value'] ?? 'N/A')); ?></div>
                </div>
                <?php endforeach; ?>
                <?php if ($sami['latest_timestamp']): ?>
                <div class="sami-timestamp">Recorded <?php echo htmlspecialchars(date('M j, Y g:i A', $sami['latest_timestamp'])); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php foreach ($pftByEvent as $pft): ?>
        <!-- PFT events (all squadrons for one round, newest first) -->
        <div class="sami-card" style="order: <?php echo -((int) ($pft['latest_timestamp'] ?? 0)); ?>;">
            <div class="sami-header">💪 <?php echo htmlspecialchars($pft['event_name']); ?></div>
            <div class="sami-body">
                <?php foreach ($pft['results'] as $result):
                    $squad = $squadronMap[$result['squadron_id']] ?? null;
                ?>
                <div class="sami-result">
                    <div class="sami-result-info">
                        <?php if ($squad && !empty($squad['icon_filename'])): ?>
                            <img src="<?php echo htmlspecialchars(iconUrl($squad['icon_filename'])); ?>" alt="icon" class="sami-result-icon">
                        <?php else: ?>
                            <div class="sami-result-icon" style="background:#ccc;"></div>
                        <?php endif; ?>
                        <span class="sami-result-name"><?php echo htmlspecialchars($squad['name'] ?? 'Unknown'); ?></span>
                    </div>
                    <div class="sami-result-score"><?php echo htmlspecialchars((string)($result['value'] ?? 'N/A')); ?></div>
                </div>
                <?php endforeach; ?>
                <?php if ($pft['latest_timestamp']): ?>
                <div class="sami-timestamp">Recorded <?php echo htmlspecialchars(date('M j, Y g:i A', $pft['latest_timestamp'])); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php foreach ($otherByEvent as $other): ?>
        <!-- Other events (all squadrons for one event, newest first) -->
        <div class="sami-card" style="order: <?php echo -((int) ($other['latest_timestamp'] ?? 0)); ?>;">
            <div class="sami-header">📌 <?php echo htmlspecialchars($other['event_name']); ?></div>
            <div class="sami-body">
                <?php foreach ($other['results'] as $result):
                    $squad = $squadronMap[$result['squadron_id']] ?? null;
                ?>
                <div class="sami-result">
                    <div class="sami-result-info">
                        <?php if ($squad && !empty($squad['icon_filename'])): ?>
                            <img src="<?php echo htmlspecialchars(iconUrl($squad['icon_filename'])); ?>" alt="icon" class="sami-result-icon">
                        <?php else: ?>
                            <div class="sami-result-icon" style="background:#ccc;"></div>
                        <?php endif; ?>
                        <span class="sami-result-name"><?php echo htmlspecialchars($squad['name'] ?? 'Unknown'); ?></span>
                    </div>
                    <div class="sami-result-score"><?php echo htmlspecialchars((string)($result['value'] ?? 'N/A')); ?></div>
                </div>
                <?php endforeach; ?>
                <?php if ($other['latest_timestamp']): ?>
                <div class="sami-timestamp">Recorded <?php echo htmlspecialchars(date('M j, Y g:i A', $other['latest_timestamp'])); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php foreach ($regularEvents as $event): ?>
        <?php $eventCardTimestamp = strtotime($event['timestamp'] ?? '') ?: 0; ?>
        <!-- Regular events (newest first) -->
        <div class="event-card" style="order: <?php echo -$eventCardTimestamp; ?>;">
            <div class="event-header"><?php echo strtoupper($event['event_type'] ?? 'Event'); ?></div>
            <div class="event-body regular-event">
                <?php $squad = $squadronMap[$event['squadron_id']] ?? null; ?>
                <?php if ($squad && !empty($squad['icon_filename'])): ?>
                    <img src="<?php echo htmlspecialchars(iconUrl($squad['icon_filename'])); ?>" alt="icon" class="regular-event-icon">
                <?php else: ?>
                    <div class="regular-event-icon" style="background:#ccc;"></div>
                <?php endif; ?>
                <div class="regular-event-name"><?php echo htmlspecialchars($squad['name'] ?? 'Unknown'); ?></div>
                <div class="regular-event-type"><?php echo ucfirst($event['event_type'] ?? 'Other'); ?></div>
                <div class="regular-event-score"><?php echo $event['value'] ?? 'N/A'; ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="events-toggle-wrap" id="events-toggle-wrap" hidden>
        <button type="button" class="events-toggle-button" id="events-toggle-button" aria-expanded="false">Show all events</button>
    </div>
    <?php else: ?>
    <p style="text-align: center; color: #999; margin: 20px 0;">No recent events to display yet.</p>
    <?php endif; ?>
    
    <div class="footer">
        <a href="admin-login.php">Admin Login</a>
        <?php if (!empty($theme['active_preset'])): ?>
            <p style="color: #999; font-size: 0.75em; margin-top: 10px;">Theme: <?php echo htmlspecialchars($theme['active_preset']); ?></p>
        <?php endif; ?>
    </div>
</div>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.js').then(function (registration) {
            // Clear the app icon badge whenever the tracker is opened,
            // since the user has now seen the latest scores.
            navigator.serviceWorker.ready.then(function (reg) {
                reg.active && reg.active.postMessage({ type: 'UPDATE_BADGE', count: 0 });
            });
        }).catch(function (err) {
            console.error('Service worker registration failed:', err);
        });
    });
}

// Leaderboard movement indicators: fetch on page load and populate
// the "Change" column for every squadron row.
(function () {
    function renderMovement(cell, entry) {
        if (!entry || entry.opa_change === null || entry.opa_change === undefined) {
            cell.textContent = entry ? 'First publication' : '—';
            cell.className = 'movement-cell movement-same';
            return;
        }

        var opaChange = Number(entry.opa_change);
        if (!Number.isFinite(opaChange)) {
            cell.textContent = '—';
            cell.className = 'movement-cell movement-same';
            return;
        }

        var opaClass = opaChange > 0 ? 'movement-up' : (opaChange < 0 ? 'movement-down' : 'movement-same');
        var opaPrefix = opaChange > 0 ? '↑ +' : (opaChange < 0 ? '↓ ' : '');
        var rankChange = entry.rank_change === null || entry.rank_change === undefined ? null : Number(entry.rank_change);
        var rankText = 'No rank change';
        if (rankChange !== null && Number.isFinite(rankChange)) {
            rankText = rankChange > 0
                ? '↑ ' + rankChange + (rankChange === 1 ? ' place gained' : ' places gained')
                : (rankChange < 0
                    ? '↓ ' + Math.abs(rankChange) + (rankChange === -1 ? ' place lost' : ' places lost')
                    : 'No rank change');
        }

        cell.className = 'movement-cell';
        cell.innerHTML = '<span class="movement-opa ' + opaClass + '">' +
            opaPrefix + opaChange.toFixed(2) + ' pp</span>' +
            '<small class="movement-rank-meta">' + rankText + '</small>';
    }

    function renderOpaHistory(element, history) {
        if (!element) return;
        if (!Array.isArray(history) || !history.length) {
            element.textContent = '';
            return;
        }
        element.textContent = history.map(function (publication) {
            return publication.label + ' ' + Number(publication.opa).toFixed(1) + '%';
        }).join(' → ');
    }

    fetch('api-leaderboard-movement.php')
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!Array.isArray(data)) {
                return;
            }
            var bySquadronId = {};
            data.forEach(function (entry) {
                bySquadronId[entry.squadron_id] = entry;
            });
            document.querySelectorAll('.movement-cell').forEach(function (cell) {
                var sid = cell.getAttribute('data-squadron-id');
                var entry = bySquadronId[sid];
                renderMovement(cell, entry);
                var historyCell = document.querySelector('.opa-history[data-opa-history="' + sid + '"]');
                renderOpaHistory(historyCell, entry ? entry.history : []);
            });
        })
        .catch(function (err) {
            console.error('Failed to load leaderboard movement:', err);
        });

    // OPA breakdown: compare any two immutable publications rather than only
    // showing the latest publication against its immediate predecessor.
    var modal = document.getElementById('breakdown-modal');
    var modalBody = document.getElementById('breakdown-modal-body');
    var modalTitle = document.getElementById('breakdown-modal-title');
    var modalClose = document.getElementById('breakdown-modal-close');
    var activeSquadronId = null;
    var activeSquadronName = '';

    function closeModal() {
        modal.style.display = 'none';
    }

    if (modalClose) modalClose.addEventListener('click', closeModal);
    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character];
        });
    }

    function numberValue(value, digits) {
        if (value === null || value === undefined || value === '') return '—';
        var number = Number(value);
        return Number.isFinite(number) ? number.toFixed(digits === undefined ? 2 : digits) : '—';
    }

    function signedValue(value, digits, suffix) {
        if (value === null || value === undefined || value === '') return '—';
        var number = Number(value);
        if (!Number.isFinite(number)) return '—';
        var prefix = number > 0 ? '+' : '';
        return prefix + number.toFixed(digits === undefined ? 2 : digits) + (suffix || '');
    }

    function movementClass(value) {
        if (value === null || value === undefined || Number(value) === 0) return 'breakdown-neutral';
        return Number(value) > 0 ? 'breakdown-positive' : 'breakdown-negative';
    }

    function rankMovement(value) {
        if (value === null || value === undefined) return 'Not comparable';
        var number = Number(value);
        if (number > 0) return '+' + number + (number === 1 ? ' place gained' : ' places gained');
        if (number < 0) return number + (number === -1 ? ' place lost' : ' places lost');
        return 'No change';
    }

    function publicationDate(publication) {
        var raw = String(publication.published_at || '');
        var parsed = new Date(raw.replace(' ', 'T'));
        return Number.isNaN(parsed.getTime()) ? raw : parsed.toLocaleDateString(undefined, {
            year: 'numeric', month: 'short', day: 'numeric'
        });
    }

    function publicationOptionLabel(publication) {
        return publication.label + ' · ' + publicationDate(publication) + ' · #' + publication.id;
    }

    function renderPublicationCard(title, publication) {
        return '<div class="breakdown-publication-card">' +
            '<h5>' + escapeHtml(title + ' · ' + publication.label) + '</h5>' +
            '<div class="breakdown-opa">' + numberValue(publication.opa) + '%</div>' +
            '<div>Overall rank: <strong>#' + escapeHtml(publication.overall_rank) + '</strong></div>' +
            '<span class="breakdown-meta">Published ' + escapeHtml(publicationDate(publication)) + '</span>' +
            (publication.description ? '<span class="breakdown-meta breakdown-description">' + escapeHtml(publication.description) + '</span>' : '') +
            '<span class="breakdown-meta">Entry method: ' + escapeHtml(publication.data_entry_method || '—') + '</span>' +
            (publication.source_filename ? '<span class="breakdown-meta">Workbook: ' + escapeHtml(publication.source_filename) + '</span>' : '') +
            '</div>';
    }

    function renderCategoryCard(category, publicationA, publicationB) {
        var a = category.a;
        var b = category.b;
        var change = category.change || {};
        function periodValues(value) {
            if (!value) return '<div class="breakdown-category-value">No category data</div>';
            var html = '<div class="breakdown-category-value">Group 1 rank: <strong>#' + escapeHtml(value.rank) + '</strong></div>' +
                '<div class="breakdown-category-value">Percentage score: <strong>' + numberValue(value.score) + '%</strong></div>' +
                '<div class="breakdown-category-value">Category points: <strong>' + numberValue(value.points, 0) + '</strong></div>';
            var source = [];
            if (value.source_rank !== null && value.source_rank !== undefined) source.push('Original source rank: #' + value.source_rank);
            if (value.source_measurement !== null && value.source_measurement !== '') source.push('Raw measurement: ' + value.source_measurement);
            if (source.length) html += '<div class="breakdown-category-source">' + source.map(escapeHtml).join('<br>') + '</div>';
            return html;
        }
        var rankDelta = change.rank_movement;
        var changeHtml = '<div class="breakdown-category-value">Rank movement: <strong class="' + movementClass(rankDelta) + '">' + escapeHtml(rankMovement(rankDelta)) + '</strong></div>' +
            '<div class="breakdown-category-value">Score change: <strong class="' + movementClass(change.score) + '">' + escapeHtml(signedValue(change.score, 2, ' pp')) + '</strong></div>' +
            '<div class="breakdown-category-value">Points change: <strong class="' + movementClass(change.points) + '">' + escapeHtml(signedValue(change.points, 0, ' pts')) + '</strong></div>';
        if (!a || !b) changeHtml = '<div class="breakdown-category-value">This category is present in only one selected publication.</div>';
        return '<article class="breakdown-category-card">' +
            '<h5>' + escapeHtml(category.name) + '</h5>' +
            '<div class="breakdown-category-columns">' +
            '<div><div class="breakdown-category-period">' + escapeHtml(publicationA.label) + '</div>' + periodValues(a) + '</div>' +
            '<div><div class="breakdown-category-period">' + escapeHtml(publicationB.label) + '</div>' + periodValues(b) + '</div>' +
            '<div class="breakdown-category-change"><div class="breakdown-category-period">Change (B − A)</div>' + changeHtml + '</div>' +
            '</div></article>';
    }

    function renderQuickCategoryChanges(categories, publicationA, publicationB) {
        if (!categories || !categories.length) return '';
        var rows = categories.map(function (category) {
            var change = category.change || {};
            var rankDelta = change.rank_movement;
            return '<tr>' +
                '<th scope="row">' + escapeHtml(category.name) + '</th>' +
                '<td class="' + movementClass(rankDelta) + '">' + escapeHtml(rankMovement(rankDelta)) + '</td>' +
                '<td class="' + movementClass(change.score) + '">' + escapeHtml(signedValue(change.score, 2, ' pp')) + '</td>' +
                '<td class="' + movementClass(change.points) + '">' + escapeHtml(signedValue(change.points, 0, ' pts')) + '</td>' +
                '</tr>';
        }).join('');
        return '<div class="breakdown-section breakdown-quick-change">' +
            '<h4>Quick Changes by Category</h4>' +
            '<p class="breakdown-explanation">Direct comparison: ' + escapeHtml(publicationA.label) +
            ' → ' + escapeHtml(publicationB.label) + '. Positive rank movement means places gained.</p>' +
            '<div class="breakdown-quick-table-wrap"><table class="breakdown-quick-table">' +
            '<thead><tr><th>Category</th><th>Rank movement</th><th>Score change</th><th>Points change</th></tr></thead>' +
            '<tbody>' + rows + '</tbody></table></div></div>';
    }

    function renderBreakdown(data) {
        var publications = data.publications || [];
        var a = data.publication_a;
        var b = data.publication_b;
        var overallChange = data.overall_change || {};
        var optionsA = publications.map(function (publication) {
            return '<option value="' + escapeHtml(publication.id) + '"' +
                (Number(publication.id) === Number(a.id) ? ' selected' : '') + '>' +
                escapeHtml(publicationOptionLabel(publication)) + '</option>';
        }).join('');
        var optionsB = publications.map(function (publication) {
            return '<option value="' + escapeHtml(publication.id) + '"' +
                (Number(publication.id) === Number(b.id) ? ' selected' : '') + '>' +
                escapeHtml(publicationOptionLabel(publication)) + '</option>';
        }).join('');

        var html = '<div class="breakdown-comparison-controls">' +
            '<label class="breakdown-control">Compare from (A)' +
            '<select id="breakdown-publication-a">' + optionsA + '</select></label>' +
            '<label class="breakdown-control">Compare to (B)' +
            '<select id="breakdown-publication-b">' + optionsB + '</select></label>' +
            '</div>' +
            '<p class="breakdown-explanation">OPA is the arithmetic average of the category percentage scores shown below. Changes are calculated directly between the two selected publications, not against an automatically chosen previous period. Positive rank movement means the squadron improved its position. Score and OPA changes are shown in percentage points.</p>' +
            '<div class="breakdown-summary-grid">' +
            renderPublicationCard('Publication A', a) +
            renderPublicationCard('Publication B', b) +
            '<div class="breakdown-publication-card breakdown-change-card">' +
            '<h5>Change (B − A)</h5>' +
            '<div class="breakdown-opa ' + movementClass(overallChange.opa) + '">' + escapeHtml(signedValue(overallChange.opa, 2, ' pp')) + '</div>' +
            '<div>OPA change</div>' +
            '<div style="margin-top:8px">Overall rank: <strong class="' + movementClass(overallChange.rank_movement) + '">' + escapeHtml(rankMovement(overallChange.rank_movement)) + '</strong></div>' +
            '</div></div>';

        html += renderQuickCategoryChanges(data.categories || [], a, b);

        html += '<div class="breakdown-section"><h4>OPA Category Breakdown</h4>' +
            '<p class="breakdown-explanation">' + escapeHtml(data.category_count) + ' category entries are included in the selected publication comparison. Category points are calculated as 11 minus Group 1 rank (1st = 10 points, 10th = 1 point); OPA itself averages percentage scores, not category points.</p>' +
            '<div class="breakdown-category-list">';
        if (data.categories && data.categories.length) {
            data.categories.forEach(function (category) {
                html += renderCategoryCard(category, a, b);
            });
        } else {
            html += '<p>No category-level records were stored for these publications.</p>';
        }
        html += '</div></div>' +
            '<p style="margin-top:16px"><a href="score-history.php">View full publication history →</a></p>';

        modalBody.innerHTML = html;

        function updateComparison() {
            var selectA = document.getElementById('breakdown-publication-a');
            var selectB = document.getElementById('breakdown-publication-b');
            if (selectA && selectB) loadBreakdown(selectA.value, selectB.value);
        }
        var selectA = document.getElementById('breakdown-publication-a');
        var selectB = document.getElementById('breakdown-publication-b');
        if (selectA) selectA.addEventListener('change', updateComparison);
        if (selectB) selectB.addEventListener('change', updateComparison);
    }

    function loadBreakdown(publicationAId, publicationBId) {
        if (activeSquadronId === null) return;
        modalBody.textContent = 'Loading selected publication comparison…';
        var url = 'api-score-breakdown.php?squadron_id=' + encodeURIComponent(activeSquadronId);
        if (publicationAId) url += '&publication_a=' + encodeURIComponent(publicationAId);
        if (publicationBId) url += '&publication_b=' + encodeURIComponent(publicationBId);
        fetch(url)
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || data.error) throw new Error(data.error || 'Failed to load publication comparison.');
                    return data;
                });
            })
            .then(renderBreakdown)
            .catch(function (error) {
                modalBody.textContent = error.message || 'Failed to load OPA breakdown.';
                console.error('Failed to load OPA publication comparison:', error);
            });
    }

    document.querySelectorAll('.details-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            activeSquadronId = button.getAttribute('data-squadron-id');
            activeSquadronName = button.getAttribute('data-squadron-name') || ('Squadron ' + activeSquadronId);
            modalTitle.textContent = activeSquadronName + ' — OPA Score Breakdown';
            modalBody.textContent = 'Loading published OPA data…';
            modal.style.display = 'flex';
            loadBreakdown(null, null);
        });
    });
})();

</script>

<script>
// Show the six most recent event cards by default, based on their existing visual timestamp order.
(function () {
    var grid = document.querySelector('.events-grid');
    var toggleWrap = document.getElementById('events-toggle-wrap');
    var toggleButton = document.getElementById('events-toggle-button');
    if (!grid || !toggleWrap || !toggleButton) return;

    var cards = Array.prototype.slice.call(
        grid.querySelectorAll('.tournament-card, .sami-card, .event-card')
    );
    cards.sort(function (a, b) {
        return (parseFloat(a.style.order) || 0) - (parseFloat(b.style.order) || 0);
    });

    if (cards.length <= 6) return;

    var expanded = false;
    function renderEvents() {
        cards.forEach(function (card, index) {
            card.hidden = !expanded && index >= 6;
        });
        toggleButton.textContent = expanded ? 'Show fewer' : 'Show all events';
        toggleButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggleWrap.hidden = false;
    }

    toggleButton.addEventListener('click', function () {
        expanded = !expanded;
        renderEvents();
    });

    renderEvents();
})();
</script>
<script>
// Keep the homepage's next-event countdown synchronized to the second.
(function () {
    var countdowns = Array.prototype.slice.call(document.querySelectorAll('[data-countdown-target]'));
    function updateCountdowns() {
        var now = Math.floor(Date.now() / 1000);
        countdowns.forEach(function (element) {
            var target = parseInt(element.getAttribute('data-countdown-target'), 10);
            if (!Number.isFinite(target)) return;
            var remaining = target - now;
            if (remaining <= 0) {
                element.textContent = 'Event has started';
                element.classList.add('is-started');
                return;
            }
            var days = Math.floor(remaining / 86400);
            var hours = Math.floor((remaining % 86400) / 3600);
            var minutes = Math.floor((remaining % 3600) / 60);
            var seconds = remaining % 60;
            element.textContent = 'Starts in ' + (days ? days + 'd ' : '') +
                [hours, minutes, seconds].map(function (part) {
                    return String(part).padStart(2, '0');
                }).join(':');
        });
    }
    updateCountdowns();
    if (countdowns.length) window.setInterval(updateCountdowns, 1000);
})();
</script>
</body>
</html>