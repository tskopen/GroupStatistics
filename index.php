<?php
require __DIR__ . '/config.php';

$squadrons = readJson(DATA_DIR . '/squadrons.json');
$scores = readJson(DATA_DIR . '/scores.json');
$brackets = readJson(DATA_DIR . '/brackets.json');

// Build squadron map
$squadronMap = [];
foreach ($squadrons as $s) {
    $squadronMap[$s['id']] = $s;
}

// Calculate total scores (exclude bracket events from total)
$totals = array_fill_keys(array_keys($squadronMap), 0);
foreach ($scores as $score) {
    $sid = $score['squadron_id'] ?? null;
    $value = isset($score['value']) ? (float)$score['value'] : 0;
    // Only count non-bracket events for total score
    if ($sid && isset($totals[$sid]) && ($score['event_type'] ?? 'other') !== 'bracket') {
        $totals[$sid] += $value;
    }
}

// Build rankings
$ranked = [];
foreach ($squadrons as $s) {
    $ranked[] = ['squadron' => $s, 'total' => $totals[$s['id']]];
}
usort($ranked, fn($a, $b) => $b['total'] <=> $a['total']);

// Get recent events (most recent first, max 15)
$recentEvents = array_reverse($scores);
$recentEvents = array_slice($recentEvents, 0, 15);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>USAFA Group 1 Squadron Tracker</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    .container { max-width: 1200px; margin: 0 auto; }
    h1 { text-align: center; color: #002147; margin-bottom: 30px; }
    h2 { color: #003366; border-bottom: 2px solid #003366; padding-bottom: 8px; margin-top: 30px; }
    
    .rankings-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 30px; }
    .rankings-table th { background: #002147; color: #fff; padding: 12px; text-align: left; }
    .rankings-table td { padding: 12px; border-bottom: 1px solid #ddd; }
    .rankings-table tr:nth-child(2) { background: #fff8dc; font-weight: bold; }
    .rankings-table tr:hover { background: #f9f9f9; }
    .icon { width: 40px; height: 40px; border-radius: 4px; object-fit: cover; margin-right: 10px; vertical-align: middle; }
    .icon-placeholder { width: 40px; height: 40px; border-radius: 4px; background: #ccc; display: inline-block; margin-right: 10px; vertical-align: middle; }
    
    /* Event Cards Grid */
    .events-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    @media (max-width: 768px) { .events-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 480px) { .events-grid { grid-template-columns: 1fr; } }
    
    .event-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
    .event-card:hover { transform: translateY(-4px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    
    .event-header { background: #003366; color: #fff; padding: 12px; font-weight: bold; font-size: 0.9em; text-align: center; }
    .event-body { padding: 15px; }
    
    /* Bracket event card */
    .bracket-vs { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 10px 0; }
    .bracket-team { flex: 1; text-align: center; }
    .bracket-team-icon { width: 50px; height: 50px; margin: 0 auto 5px; border-radius: 4px; object-fit: cover; }
    .bracket-team-name { font-weight: bold; font-size: 0.85em; }
    .bracket-score { font-size: 1.2em; font-weight: bold; color: #002147; }
    .bracket-vs-label { font-weight: bold; color: #999; }
    .bracket-winner { background: #ffd700; color: #000; padding: 2px 6px; border-radius: 3px; font-size: 0.75em; font-weight: bold; }
    
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
    <table class="rankings-table">
        <tr>
            <th>Rank</th>
            <th>Squadron</th>
            <th>Total Score</th>
        </tr>
        <?php $rank = 1; foreach ($ranked as $entry): $s = $entry['squadron']; ?>
        <tr>
            <td>#<?php echo $rank; ?></td>
            <td>
                <?php if ($s['icon']): ?>
                    <img src="<?php echo htmlspecialchars(iconUrl($s['icon'])); ?>" alt="icon" class="icon">
                <?php else: ?>
                    <span class="icon-placeholder"></span>
                <?php endif; ?>
                <?php echo htmlspecialchars($s['name']); ?>
            </td>
            <td><?php echo $entry['total']; ?></td>
        </tr>
        <?php $rank++; endforeach; ?>
    </table>
    
    <?php if ($recentEvents): ?>
    <h2>🔥 Recent Events</h2>
    <div class="events-grid">
        <?php foreach ($recentEvents as $event): 
            if ($event['event_type'] === 'bracket'): 
                $t1 = $squadronMap[$event['squadron_id']] ?? null;
                $t2 = $squadronMap[$event['opponent_id']] ?? null;
                $isWinner = $event['winner_id'] === $event['squadron_id'];
        ?>
        <!-- Bracket Event Card -->
        <div class="event-card">
            <div class="event-header"><?php echo htmlspecialchars($event['tournament_name'] ?? 'Bracket'); ?></div>
            <div class="event-body">
                <div class="bracket-vs">
                    <div class="bracket-team">
                        <?php if ($t1 && $t1['icon']): ?>
                            <img src="<?php echo htmlspecialchars(iconUrl($t1['icon'])); ?>" alt="icon" class="bracket-team-icon">
                        <?php else: ?>
                            <div class="bracket-team-icon" style="background:#ccc;"></div>
                        <?php endif; ?>
                        <div class="bracket-team-name"><?php echo htmlspecialchars($t1['name'] ?? 'TBD'); ?></div>
                        <div class="bracket-score"><?php echo $event['team1_score'] ?? '-'; ?></div>
                    </div>
                    <div class="bracket-vs-label">VS</div>
                    <div class="bracket-team">
                        <?php if ($t2 && $t2['icon']): ?>
                            <img src="<?php echo htmlspecialchars(iconUrl($t2['icon'])); ?>" alt="icon" class="bracket-team-icon">
                        <?php else: ?>
                            <div class="bracket-team-icon" style="background:#ccc;"></div>
                        <?php endif; ?>
                        <div class="bracket-team-name"><?php echo htmlspecialchars($t2['name'] ?? 'TBD'); ?></div>
                        <div class="bracket-score"><?php echo $event['team2_score'] ?? '-'; ?></div>
                    </div>
                </div>
                <?php if ($event['winner_id']): ?>
                <div style="margin-top: 10px;">
                    <span class="bracket-winner">🏆 <?php echo htmlspecialchars($squadronMap[$event['winner_id']]['name'] ?? 'Winner'); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Regular Event Card -->
        <div class="event-card">
            <div class="event-header"><?php echo strtoupper($event['event_type'] ?? 'Event'); ?></div>
            <div class="event-body regular-event">
                <?php $squad = $squadronMap[$event['squadron_id']] ?? null; ?>
                <?php if ($squad && $squad['icon']): ?>
                    <img src="<?php echo htmlspecialchars(iconUrl($squad['icon'])); ?>" alt="icon" class="regular-event-icon">
                <?php else: ?>
                    <div class="regular-event-icon" style="background:#ccc;"></div>
                <?php endif; ?>
                <div class="regular-event-name"><?php echo htmlspecialchars($squad['name'] ?? 'Unknown'); ?></div>
                <div class="regular-event-type"><?php echo ucfirst($event['event_type'] ?? 'Other'); ?></div>
                <div class="regular-event-score"><?php echo $event['value'] ?? 'N/A'; ?></div>
            </div>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <div class="footer">
        <a href="admin-login.php">Admin Login</a>
    </div>
</div>
</body>
</html>
