<?php
require __DIR__ . '/config.php';

$bracketId = $_GET['id'] ?? null;
$brackets = readJson(DATA_DIR . '/brackets.json');
$bracket = null;

foreach ($brackets as $b) {
    if ($b['id'] === $bracketId) {
        $bracket = $b;
        break;
    }
}

if (!$bracket) {
    header('HTTP/1.0 404 Not Found');
    exit('Bracket not found');
}

$squadronMap = [];
foreach ($bracket['participants'] as $p) {
    $squadronMap[$p['id']] = $p;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($bracket['name']); ?> - Bracket</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #0f0f1e; margin: 0; padding: 20px; color: #fff; }
        .container { max-width: 1600px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 40px; }
        .header h1 { margin: 0; font-size: 2.5em; color: #667eea; text-shadow: 0 0 20px rgba(102, 126, 234, 0.3); }
        .header p { margin: 5px 0 0 0; color: #aaa; }
        
        .bracket-container { position: relative; padding: 20px; background: #1a1a2e; border-radius: 12px; overflow-x: auto; }
        .bracket-wrapper { display: flex; gap: 40px; min-width: min-content; padding: 20px; }
        
        .round { display: flex; flex-direction: column; justify-content: space-around; gap: 20px; min-width: 250px; }
        .round-title { text-align: center; font-weight: bold; color: #667eea; margin-bottom: 10px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
        
        .matchup { background: #16213e; border: 2px solid #667eea; border-radius: 8px; padding: 15px; min-height: 140px; display: flex; flex-direction: column; justify-content: space-between; position: relative; transition: all 0.3s; }
        .matchup:hover { border-color: #764ba2; box-shadow: 0 0 20px rgba(102, 126, 234, 0.3); }
        .matchup.completed { border-color: #4caf50; }
        .matchup.live { border-color: #ff9800; animation: pulse 1.5s infinite; }
        
        @keyframes pulse { 0%, 100% { box-shadow: 0 0 10px rgba(255, 152, 0, 0.3); } 50% { box-shadow: 0 0 20px rgba(255, 152, 0, 0.6); } }
        
        .team { padding: 10px; margin: 5px 0; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; }
        .team.winner { background: #4caf50; font-weight: bold; }
        .team.loser { opacity: 0.5; }
        .team-name { display: flex; align-items: center; gap: 8px; flex: 1; }
        .team-icon { width: 28px; height: 28px; border-radius: 3px; background: #667eea; object-fit: cover; }
        .team-score { font-size: 1.2em; font-weight: bold; min-width: 30px; text-align: right; }
        
        .bye { color: #999; text-align: center; padding: 10px; font-style: italic; }
        
        .status-badge { position: absolute; top: 5px; right: 5px; font-size: 11px; padding: 3px 8px; border-radius: 3px; font-weight: bold; text-transform: uppercase; }
        .status-upcoming { background: #666; color: #fff; }
        .status-live { background: #ff9800; color: #fff; animation: pulse-badge 1s infinite; }
        .status-finished { background: #4caf50; color: #fff; }
        
        @keyframes pulse-badge { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
        
        .champion { text-align: center; margin-top: 40px; padding: 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; }
        .champion h2 { margin: 0 0 10px 0; font-size: 1.5em; }
        .champion-team { display: flex; align-items: center; justify-content: center; gap: 15px; font-size: 1.3em; font-weight: bold; }
        .champion-icon { width: 60px; height: 60px; border-radius: 6px; background: rgba(255,255,255,0.1); object-fit: cover; }
        
        .nav { text-align: center; margin-top: 40px; }
        .nav a { color: #667eea; text-decoration: none; margin: 0 15px; }
        .nav a:hover { text-decoration: underline; }
        
        @media (max-width: 768px) {
            .bracket-wrapper { gap: 20px; }
            .round { min-width: 200px; }
            .header h1 { font-size: 1.5em; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🏆 <?php echo htmlspecialchars($bracket['name']); ?></h1>
        <p>Status: <?php echo $bracket['status'] === 'completed' ? '✓ Completed' : 'In Progress'; ?></p>
    </div>
    
    <div class="bracket-container">
        <div class="bracket-wrapper">
            <?php foreach ($bracket['rounds'] as $round): ?>
            <div class="round">
                <div class="round-title"><?php echo htmlspecialchars($round['round_name']); ?></div>
                <?php foreach ($round['matchups'] as $matchup): 
                    $status = $matchup['status'] ?? 'upcoming';
                    if ($winner) {
                        $status = 'finished';
                    }
                ?>
                <div class="matchup <?php echo $status === 'finished' ? 'completed' : ($status === 'live' ? 'live' : ''); ?>">
                    <span class="status-badge status-<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status); ?></span>
                    <?php if (empty($matchup['team2_id'])): ?>
                        <div class="team winner">
                            <span class="team-name">
                                <?php if ($t1 && !empty($t1['icon'])): ?>
                                    <img src="<?php echo htmlspecialchars(iconUrl($t1['icon'])); ?>" class="team-icon" alt="icon">
                                <?php endif; ?>
                                <?php echo htmlspecialchars($t1['name'] ?? 'TBD'); ?>
                            </span>
                        </div>
                        <div class="bye">BYE</div>
                    <?php else: ?>
                        <div class="team <?php echo $winner && $winner === $matchup['team1_id'] ? 'winner' : ($winner ? 'loser' : ''); ?>">
                            <span class="team-name">
                                <?php if ($t1 && !empty($t1['icon'])): ?>
                                    <img src="<?php echo htmlspecialchars(iconUrl($t1['icon'])); ?>" class="team-icon" alt="icon">
                                <?php endif; ?>
                                <?php echo htmlspecialchars($t1['name'] ?? 'TBD'); ?>
                            </span>
                            <span class="team-score"><?php echo $matchup['team1_score'] ?? '-'; ?></span>
                        </div>
                        <div class="team <?php echo $winner && $winner === $matchup['team2_id'] ? 'winner' : ($winner ? 'loser' : ''); ?>">
                            <span class="team-name">
                                <?php if ($t2 && !empty($t2['icon'])): ?>
                                    <img src="<?php echo htmlspecialchars(iconUrl($t2['icon'])); ?>" class="team-icon" alt="icon">
                                <?php endif; ?>
                                <?php echo htmlspecialchars($t2['name'] ?? 'TBD'); ?>
                            </span>
                            <span class="team-score"><?php echo $matchup['team2_score'] ?? '-'; ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (($bracket['status'] ?? '') === 'completed' && !empty($bracket['champion_id'])): ?>
    <?php $champion = $squadronMap[$bracket['champion_id']] ?? null; ?>
    <div class="champion">
        <h2>🏆 Champion</h2>
        <div class="champion-team">
            <?php if ($champion && !empty($champion['icon'])): ?>
                <img src="<?php echo htmlspecialchars(iconUrl($champion['icon'])); ?>" class="champion-icon" alt="icon">
            <?php endif; ?>
            <span><?php echo htmlspecialchars($champion['name'] ?? 'Unknown'); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="nav">
        <a href="index.php">← Back to Rankings</a>
    </div>
</div>
</body>
</html>
