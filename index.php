<?php
function readJson($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function writeJson($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$dataDir = '/data';
$squadrons = readJson($dataDir . '/squadrons.json');
$scores = readJson($dataDir . '/scores.json');
$brackets = readJson($dataDir . '/brackets.json');

// Build squadron map
$squadronMap = [];
foreach ($squadrons as $s) {
    $squadronMap[$s['id']] = $s;
}

// Calculate total scores
$totals = array_fill_keys(array_keys($squadronMap), 0);
foreach ($scores as $score) {
    $sid = $score['squadron_id'] ?? null;
    $value = isset($score['value']) ? (float)$score['value'] : 0;
    if ($sid && isset($totals[$sid])) $totals[$sid] += $value;
}

// Build rankings
$ranked = [];
foreach ($squadrons as $s) {
    $ranked[] = ['squadron' => $s, 'total' => $totals[$s['id']]];
}
usort($ranked, fn($a, $b) => $b['total'] <=> $a['total']);

// Group scores by event
$events = [];
foreach ($scores as $score) {
    $eventName = $score['event_name'] ?? 'Unknown';
    if (!isset($events[$eventName])) {
        $events[$eventName] = ['type' => $score['event_type'] ?? 'other', 'scores' => []];
    }
    $events[$eventName]['scores'][] = $score;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>USAFA Group 1 Squadron Tracker</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    .container { max-width: 900px; margin: 0 auto; }
    h1 { text-align: center; color: #002147; margin-bottom: 30px; }
    h2 { color: #003366; border-bottom: 2px solid #003366; padding-bottom: 8px; margin-top: 30px; }
    
    .rankings-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 30px; }
    .rankings-table th { background: #002147; color: #fff; padding: 12px; text-align: left; }
    .rankings-table td { padding: 12px; border-bottom: 1px solid #ddd; }
    .rankings-table tr:nth-child(2) { background: #fff8dc; font-weight: bold; }
    .rankings-table tr:hover { background: #f9f9f9; }
    .icon { width: 40px; height: 40px; border-radius: 4px; object-fit: cover; margin-right: 10px; vertical-align: middle; }
    .icon-placeholder { width: 40px; height: 40px; border-radius: 4px; background: #ccc; display: inline-block; margin-right: 10px; vertical-align: middle; }
    
    .events-section { margin-bottom: 30px; }
    .event-card { background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .event-title { font-size: 1.2em; font-weight: bold; color: #002147; margin: 0 0 15px 0; }
    .event-type { display: inline-block; background: #e3f2fd; color: #003366; padding: 4px 10px; border-radius: 3px; font-size: 0.85em; margin-left: 10px; }
    .event-table { width: 100%; border-collapse: collapse; }
    .event-table th { background: #f5f5f5; padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
    .event-table td { padding: 8px; border-bottom: 1px solid #eee; }
    .event-table tr:nth-child(even) { background: #fafafa; }
    .winner { background: #fff8dc !important; font-weight: bold; }
    
    .bracket-section { background: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .bracket-title { font-size: 1.1em; font-weight: bold; color: #002147; }
    .bracket-visual { display: grid; gap: 20px; margin-top: 20px; }
    .bracket-round { display: flex; flex-direction: column; justify-content: space-around; gap: 20px; }
    .bracket-match { background: #f5f5f5; padding: 12px; border-radius: 4px; border: 1px solid #ddd; }
    .bracket-team { padding: 6px 0; }
    .bracket-winner { color: #28a745; font-weight: bold; }
    
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
                    <img src="<?php echo htmlspecialchars($s['icon']); ?>" alt="icon" class="icon">
                <?php else: ?>
                    <span class="icon-placeholder"></span>
                <?php endif; ?>
                <?php echo htmlspecialchars($s['name']); ?>
            </td>
            <td><?php echo $entry['total']; ?></td>
        </tr>
        <?php $rank++; endforeach; ?>
    </table>
    
    <?php if ($events): ?>
    <h2>Event Results</h2>
    <div class="events-section">
        <?php foreach ($events as $eventName => $eventData): ?>
        <div class="event-card">
            <p class="event-title">
                <?php echo htmlspecialchars($eventName); ?>
                <span class="event-type"><?php echo strtoupper($eventData['type']); ?></span>
            </p>
            <table class="event-table">
                <tr>
                    <th>Squadron</th>
                    <th>Score</th>
                </tr>
                <?php 
                $eventScores = [];
                foreach ($eventData['scores'] as $score) {
                    $sid = $score['squadron_id'];
                    if (!isset($eventScores[$sid])) $eventScores[$sid] = 0;
                    $eventScores[$sid] += $score['value'];
                }
                arsort($eventScores);
                $maxScore = max($eventScores) ?? 0;
                foreach ($eventScores as $sid => $score): 
                    $isWinner = $score == $maxScore;
                ?>
                <tr class="<?php echo $isWinner ? 'winner' : ''; ?>">
                    <td>
                        <?php if (isset($squadronMap[$sid]['icon']) && $squadronMap[$sid]['icon']): ?>
                            <img src="<?php echo htmlspecialchars($squadronMap[$sid]['icon']); ?>" alt="icon" class="icon">
                        <?php else: ?>
                            <span class="icon-placeholder"></span>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($squadronMap[$sid]['name'] ?? 'Unknown'); ?>
                    </td>
                    <td><?php echo $score; ?><?php echo $isWinner ? ' 🏆' : ''; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <div class="footer">
        <a href="admin-login.php">Admin Login</a>
    </div>
</div>
</body>
</html>
