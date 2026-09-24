<?php

header('Cache-Control: no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();

$db = getDb();

// Fetch squadrons
$stmt = $db->prepare("SELECT * FROM squadrons ORDER BY id");
$stmt->execute();
$squadrons = $stmt->fetchAll();

// Fetch scores (all events)
$stmt = $db->prepare("SELECT * FROM events ORDER BY timestamp DESC");
$stmt->execute();
$scores = $stmt->fetchAll();

if (empty($scores)) {
    try {
        $countStmt = $db->prepare('SELECT COUNT(*) as cnt FROM events');
        $countStmt->execute();
        $eventCount = $countStmt->fetch()['cnt'] ?? 0;
        error_log("index.php: events query returned 0 rows, COUNT(*) FROM events = {$eventCount}, DB_PATH=" . DB_PATH);
    } catch (Exception $e) {
        error_log('index.php: failed to diagnose empty events result: ' . $e->getMessage());
    }
}

// Fetch brackets
$stmt = $db->prepare("SELECT * FROM brackets ORDER BY updated_at DESC");
$stmt->execute();
$bracketsData = $stmt->fetchAll();
$brackets = array_map(function($b) {
    $b['rounds'] = json_decode($b['rounds'], true) ?? [];
    return $b;
}, $bracketsData);

// Build squadron map
$squadronMap = [];
foreach ($squadrons as $s) {
    $squadronMap[$s['id']] = $s;
}

// Calculate rankings using the same central logic as the rest of the
// app (see getSquadronRankings() in config.php) so the homepage never
// diverges from the API/leaderboard-movement calculations.
$squadronRankings = getSquadronRankings();

$ranked = [];
foreach ($squadronRankings as $row) {
    $squadron = $squadronMap[$row['squadron_id']] ?? null;
    if ($squadron === null) {
        continue;
    }
    $ranked[] = ['squadron' => $squadron, 'total' => $row['total']];
}

/*
 * Bracket match scores must come from brackets.json.
 * scores.json only contains the point-award event records.
 */
$bracketsByTournament = [];

foreach ($brackets as $bracket) {
    $tournament = [
        'tournament_name' => $bracket['name'] ?? 'Bracket',
        'matches' => [],
        'latest_timestamp' => $bracket['updated_at'] ?? $bracket['created_at'] ?? 0,
    ];

    foreach (($bracket['rounds'] ?? []) as $round) {
        foreach (($round['matchups'] ?? []) as $matchup) {
            $tournament['matches'][] = [
                'squadron_id' => $matchup['team1_id'] ?? null,
                'opponent_id' => $matchup['team2_id'] ?? null,
                'team1_score' => $matchup['team1_score'] ?? '-',
                'team2_score' => $matchup['team2_score'] ?? '-',
                'winner_id' => $matchup['winner_id'] ?? null,
                'value' => $matchup['points'] ?? 0,
            ];
        }
    }

    if (!empty($tournament['matches'])) {
        $bracketsByTournament[] = $tournament;
    }
}

// Regular events still come from scores.json, newest first.
$regularEvents = array_values(array_filter(
    $scores,
    fn($event) => ($event['event_type'] ?? 'other') !== 'bracket'
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
<meta name="theme-color" content="#002147">
<meta name="description" content="Track USAFA Group 1 squadron competition scores and tournament brackets">

<!-- iOS PWA support -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Squadron Tracker">
<link rel="apple-touch-icon" href="pwa-icon.php?size=192">
<style>
    :root {
        --primary-color: <?php echo htmlspecialchars($theme['primary_color']); ?>;
        --secondary-color: <?php echo htmlspecialchars($theme['secondary_color']); ?>;
        --accent-color: <?php echo htmlspecialchars($theme['accent_color']); ?>;
        --background-color: <?php echo htmlspecialchars($theme['background_color']); ?>;
        --text-color: <?php echo htmlspecialchars($theme['text_color']); ?>;
    }
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; background: var(--background-color); margin: 0; padding: 20px; color: var(--text-color); }
    .container { max-width: 1200px; margin: 0 auto; }
    h1 { text-align: center; color: var(--primary-color); margin-bottom: 30px; }
    h2 { color: var(--primary-color); border-bottom: 2px solid var(--primary-color); padding-bottom: 8px; margin-top: 30px; }
    
    .rankings-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 30px; }
    .rankings-table th { background: var(--primary-color); color: #fff; padding: 12px; text-align: left; }
    .rankings-table td { padding: 12px; border-bottom: 1px solid #ddd; }
    .rankings-table tr:nth-child(2) { background: #fff8dc; font-weight: bold; }
    .rankings-table tr:hover { background: #f9f9f9; }
    .icon { width: 40px; height: 40px; border-radius: 4px; object-fit: cover; margin-right: 10px; vertical-align: middle; }
    .icon-placeholder { width: 40px; height: 40px; border-radius: 4px; background: #ccc; display: inline-block; margin-right: 10px; vertical-align: middle; }

    /* Leaderboard movement indicators */
    .movement-up { color: #1a7a1a; font-weight: bold; }
    .movement-down { color: #b00020; font-weight: bold; }
    .movement-same { color: #999; font-weight: bold; }
    .movement-new { color: #003366; font-weight: bold; background: #e3f2fd; padding: 2px 6px; border-radius: 3px; font-size: 0.85em; }

    .details-btn { padding: 6px 12px; background: var(--primary-color); color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 0.85em; }
    .details-btn:hover { background: var(--secondary-color); }

    /* Score breakdown modal */
    .breakdown-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 20px; }
    .breakdown-modal { background: #fff; border-radius: 8px; max-width: 500px; width: 100%; max-height: 80vh; overflow-y: auto; padding: 25px; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
    .breakdown-modal-close { position: absolute; top: 12px; right: 15px; background: none; border: none; font-size: 1.5em; cursor: pointer; color: #666; }
    .breakdown-modal-close:hover { color: #000; }
    .breakdown-section { margin-top: 15px; }
    .breakdown-section h4 { margin-bottom: 8px; color: var(--primary-color); }
    .breakdown-item { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #eee; font-size: 0.9em; }
    .breakdown-item-points { font-weight: bold; color: #28a745; }
    .breakdown-total { margin-top: 10px; font-weight: bold; text-align: right; }
    
    /* Event Cards Grid */
    .events-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    @media (max-width: 768px) { .events-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 480px) { .events-grid { grid-template-columns: 1fr; } }
    
    .event-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
    .event-card:hover { transform: translateY(-4px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    
    .event-header { background: var(--secondary-color); color: #fff; padding: 12px; font-weight: bold; font-size: 0.9em; text-align: center; }
    .event-body { padding: 15px; }
    
    /* Tournament card */
    .tournament-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; grid-column: span 2; }
    @media (max-width: 768px) { .tournament-card { grid-column: 1 / -1; } }
    .tournament-header { background: var(--primary-color); color: #fff; padding: 16px; font-weight: bold; font-size: 1.3em; text-align: center; }
    .tournament-body { padding: 15px; }
    
    .tournament-match { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px; margin-bottom: 10px; border-radius: 6px; background: #f9f9f9; }
    .tournament-match:last-child { margin-bottom: 0; }
    
    .match-team { flex: 1; display: flex; align-items: center; gap: 10px; }
    .match-team.team-right { flex-direction: row-reverse; text-align: right; }
    .match-team-icon { width: 45px; height: 45px; border-radius: 4px; object-fit: cover; flex-shrink: 0; }
    .match-team-name { font-weight: bold; font-size: 0.95em; }
    
    .match-score-block { display: flex; align-items: center; gap: 8px; font-size: 1.3em; font-weight: bold; color: #002147; padding: 0 15px; }
    .match-vs-label { font-weight: bold; color: #999; font-size: 0.9em; }
    .match-points { font-size: 0.75em; color: #666; margin-top: 4px; text-align: center; }
    
    .match-winner { background: var(--accent-color); }
    .match-winner-check { color: #28a745; font-weight: bold; margin-left: 6px; }
    
    /* SAMI card (all squadron results for one SAMI round grouped together) */
    .sami-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; grid-column: span 2; }
    @media (max-width: 768px) { .sami-card { grid-column: 1 / -1; } }
    .sami-header { background: var(--secondary-color); color: #fff; padding: 16px; font-weight: bold; font-size: 1.3em; text-align: center; }
    .sami-body { padding: 15px; }
    
    .sami-result { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px; margin-bottom: 10px; border-radius: 6px; background: #f9f9f9; }
    .sami-result:last-child { margin-bottom: 0; }
    .sami-result-icon { width: 45px; height: 45px; border-radius: 4px; object-fit: cover; flex-shrink: 0; }
    .sami-result-info { flex: 1; display: flex; align-items: center; gap: 10px; }
    .sami-result-name { font-weight: bold; font-size: 0.95em; text-align: left; }
    .sami-result-score { font-weight: bold; font-size: 1.3em; color: #28a745; text-align: right; }
    
    .sami-timestamp { font-size: 0.75em; color: #666; margin-top: 10px; text-align: center; }
    
    /* Regular event card */
    .regular-event { text-align: center; }
    .regular-event-icon { width: 60px; height: 60px; margin: 0 auto 10px; border-radius: 4px; object-fit: cover; }
    .regular-event-name { font-weight: bold; margin: 5px 0; }
    .regular-event-type { display: inline-block; background: #e3f2fd; color: #003366; padding: 4px 8px; border-radius: 3px; font-size: 0.75em; margin-bottom: 8px; }
    .regular-event-score { font-size: 1.5em; font-weight: bold; color: #28a745; }
    
    .footer { text-align: center; margin-top: 40px; }
    .footer a { color: #002147; text-decoration: none; margin: 0 15px; }
    .footer a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="container">
    <h1>⚔️ USAFA Group 1 Squadron Tracker</h1>
    
    <h2>Overall Rankings</h2>
    <table class="rankings-table" id="rankings-table">
        <tr>
            <th>Rank</th>
            <th>Squadron</th>
            <th>Total Score</th>
            <th>Change</th>
            <th>Details</th>
        </tr>
        <?php $rank = 1; foreach ($ranked as $entry): $s = $entry['squadron']; ?>
        <tr data-squadron-id="<?php echo htmlspecialchars((string) $s['id']); ?>">
            <td>#<?php echo $rank; ?></td>
            <td>
                <?php if (!empty($s['icon_filename'])): ?>
                    <img src="<?php echo htmlspecialchars(iconUrl($s['icon_filename'])); ?>" alt="icon" class="icon">
                <?php else: ?>
                    <span class="icon-placeholder"></span>
                <?php endif; ?>
                <?php echo htmlspecialchars($s['name']); ?>
            </td>
            <td><?php echo $entry['total']; ?></td>
            <td class="movement-cell" data-squadron-id="<?php echo htmlspecialchars((string) $s['id']); ?>">—</td>
            <td>
                <button type="button" class="details-btn" data-squadron-id="<?php echo htmlspecialchars((string) $s['id']); ?>" data-squadron-name="<?php echo htmlspecialchars($s['name']); ?>">Details</button>
            </td>
        </tr>
        <?php $rank++; endforeach; ?>
    </table>

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
        <!-- Tournaments (newest first) -->
        <div class="tournament-card">
            <div class="tournament-header">🏆 <?php echo htmlspecialchars($tournament['tournament_name']); ?></div>
            <div class="tournament-body">
                <?php foreach ($tournament['matches'] as $match):
                    $t1 = $squadronMap[$match['squadron_id']] ?? null;
                    $t2 = $squadronMap[$match['opponent_id']] ?? null;
                    $winnerId = $match['winner_id'] ?? null;
                    $t1IsWinner = $winnerId && $winnerId === $match['squadron_id'];
                    $t2IsWinner = $winnerId && $winnerId === $match['opponent_id'];
                    $pointsAwarded = $match['value'] ?? 0;
                ?>
                <div class="tournament-match">
                    <div class="match-team <?php echo $t1IsWinner ? 'match-winner' : ''; ?>">
                        <?php if ($t1 && !empty($t1['icon_filename'])): ?>
                            <img src="<?php echo htmlspecialchars(iconUrl($t1['icon_filename'])); ?>" alt="icon" class="match-team-icon">
                        <?php else: ?>
                            <div class="match-team-icon" style="background:#ccc;"></div>
                        <?php endif; ?>
                        <span class="match-team-name">
                            <?php echo htmlspecialchars($t1['name'] ?? 'TBD'); ?>
                            <?php if ($t1IsWinner): ?><span class="match-winner-check">✓</span><?php endif; ?>
                        </span>
                    </div>

                    <div class="match-score-block">
                        <span><?php echo htmlspecialchars((string)($match['team1_score'] ?? '-')); ?></span>
                        <span class="match-vs-label">—</span>
                        <span><?php echo htmlspecialchars((string)($match['team2_score'] ?? '-')); ?></span>
                    </div>

                    <div class="match-team team-right <?php echo $t2IsWinner ? 'match-winner' : ''; ?>">
                        <?php if ($t2 && !empty($t2['icon_filename'])): ?>
                            <img src="<?php echo htmlspecialchars(iconUrl($t2['icon_filename'])); ?>" alt="icon" class="match-team-icon">
                        <?php else: ?>
                            <div class="match-team-icon" style="background:#ccc;"></div>
                        <?php endif; ?>
                        <span class="match-team-name">
                            <?php if ($t2IsWinner): ?><span class="match-winner-check">✓</span><?php endif; ?>
                            <?php echo htmlspecialchars($t2['name'] ?? 'TBD'); ?>
                        </span>
                    </div>
                </div>
                <div class="match-points">+<?php echo $pointsAwarded; ?> pts</div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php foreach ($samisByEvent as $sami): ?>
        <!-- SAMI events (all squadrons for one round, newest first) -->
        <div class="sami-card">
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
        <div class="sami-card">
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
        <div class="sami-card">
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
        <!-- Regular events (newest first) -->
        <div class="event-card">
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
    <?php else: ?>
    <p style="text-align: center; color: #999; margin: 20px 0;">No recent events to display yet.</p>
    <?php endif; ?>
    
    <div class="footer">
        <a href="admin-login.php">Admin Login</a>
        <a href="notification-preferences.php" style="margin-left:10px;">🔔 Notifications</a>
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
        if (!entry) {
            cell.textContent = '—';
            cell.className = 'movement-cell movement-same';
            return;
        }

        var movement = entry.movement || 'same';

        if (movement === 'new') {
            cell.textContent = 'NEW';
            cell.className = 'movement-cell movement-new';
        } else if (movement.indexOf('up') === 0) {
            var upAmount = movement.split(' ')[1] || '';
            cell.textContent = '↑ +' + upAmount;
            cell.className = 'movement-cell movement-up';
        } else if (movement.indexOf('down') === 0) {
            var downAmount = movement.split(' ')[1] || '';
            cell.textContent = '↓ -' + downAmount;
            cell.className = 'movement-cell movement-down';
        } else {
            cell.textContent = '—';
            cell.className = 'movement-cell movement-same';
        }
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
                renderMovement(cell, bySquadronId[sid]);
            });
        })
        .catch(function (err) {
            console.error('Failed to load leaderboard movement:', err);
        });

    // Score breakdown modal.
    var modal = document.getElementById('breakdown-modal');
    var modalBody = document.getElementById('breakdown-modal-body');
    var modalTitle = document.getElementById('breakdown-modal-title');
    var modalClose = document.getElementById('breakdown-modal-close');

    function closeModal() {
        modal.style.display = 'none';
    }

    if (modalClose) {
        modalClose.addEventListener('click', closeModal);
    }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });
    }

    function renderBreakdownItem(item) {
        var date = item.date ? new Date(item.date).toLocaleDateString() : '';
        return '<div class="breakdown-item">' +
            '<span>' + item.name + (date ? ' <small style="color:#999;">(' + date + ')</small>' : '') + '</span>' +
            '<span class="breakdown-item-points">' + item.points + '</span>' +
            '</div>';
    }

    document.querySelectorAll('.details-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var sid = btn.getAttribute('data-squadron-id');
            var name = btn.getAttribute('data-squadron-name');

            modalTitle.textContent = name + ' — Score Breakdown';
            modalBody.innerHTML = 'Loading…';
            modal.style.display = 'flex';

            fetch('api-score-breakdown.php?squadron_id=' + encodeURIComponent(sid))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.error) {
                        modalBody.innerHTML = '<p style="color:#b00020;">' + data.error + '</p>';
                        return;
                    }

                    var html = '';
                    var events = data.breakdown.events;
                    var intramurals = data.breakdown.intramurals;

                    html += '<div class="breakdown-section"><h4>🔥 Events (' + events.total + ' pts)</h4>';
                    if (events.items.length) {
                        events.items.forEach(function (item) {
                            html += renderBreakdownItem(item);
                        });
                    } else {
                        html += '<p style="color:#999;">No events recorded.</p>';
                    }
                    html += '</div>';

                    html += '<div class="breakdown-section"><h4>🏅 Intramurals (' + intramurals.total + ' pts)</h4>';
                    if (intramurals.items.length) {
                        intramurals.items.forEach(function (item) {
                            html += renderBreakdownItem(item);
                        });
                    } else {
                        html += '<p style="color:#999;">No intramural results recorded.</p>';
                    }
                    html += '</div>';

                    html += '<div class="breakdown-total">Total: ' + data.total_points + ' pts</div>';

                    modalBody.innerHTML = html;
                })
                .catch(function (err) {
                    modalBody.innerHTML = '<p style="color:#b00020;">Failed to load breakdown.</p>';
                    console.error('Failed to load score breakdown:', err);
                });
        });
    });
})();
</script>
</body>
</html>
