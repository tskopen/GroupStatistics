<?php
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();

$db = getDb();

$stmt = $db->prepare("SELECT * FROM intramural_sports ORDER BY sport_name ASC");
$stmt->execute();
$sports = $stmt->fetchAll();

$stmt = $db->prepare("SELECT * FROM squadrons ORDER BY id");
$stmt->execute();
$squadrons = $stmt->fetchAll();
$squadronMap = [];
foreach ($squadrons as $s) {
    $squadronMap[$s['id']] = $s;
}

$standings = [];
foreach ($sports as $sport) {
    $standings[$sport['id']] = [
        'sport_name' => $sport['sport_name'],
        'emoji' => $sport['emoji'],
        'records' => []
    ];
    
    foreach ($squadrons as $squad) {
        $stmt = $db->prepare("
            SELECT wins, losses, points_awarded 
            FROM intramural_wl_records 
            WHERE squadron_id = ? AND sport_id = ?
        ");
        $stmt->execute([$squad['id'], $sport['id']]);
        $record = $stmt->fetch();
        
        $standings[$sport['id']]['records'][$squad['id']] = [
            'squadron' => $squad,
            'wins' => $record['wins'] ?? 0,
            'losses' => $record['losses'] ?? 0,
            'points' => $record['points_awarded'] ?? 0,
        ];
    }
}

$totalIntramural = [];
foreach ($squadrons as $squad) {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(points_awarded), 0) as total
        FROM intramural_wl_records
        WHERE squadron_id = ?
    ");
    $stmt->execute([$squad['id']]);
    $result = $stmt->fetch();
    $totalIntramural[$squad['id']] = $result['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Intramural Standings - Squadron Tracker</title>
<style>
    :root {
        --primary-color: <?php echo htmlspecialchars($theme['primary_color']); ?>;
        --secondary-color: <?php echo htmlspecialchars($theme['secondary_color']); ?>;
        --background-color: <?php echo htmlspecialchars($theme['background_color']); ?>;
        --text-color: <?php echo htmlspecialchars($theme['text_color']); ?>;
    }
    body { font-family: Arial, sans-serif; background: var(--background-color); margin: 0; padding: 20px; color: var(--text-color); }
    .container { max-width: 1200px; margin: 0 auto; }
    h1 { color: var(--primary-color); text-align: center; margin-bottom: 30px; }
    h2 { color: var(--primary-color); border-bottom: 2px solid var(--primary-color); padding-bottom: 8px; margin-top: 30px; }
    h3 { color: var(--primary-color); margin-top: 20px; }
    
    .standings-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 30px; }
    .standings-table th { background: var(--primary-color); color: #fff; padding: 12px; text-align: left; font-size: 0.9em; }
    .standings-table td { padding: 12px; border-bottom: 1px solid #ddd; }
    .standings-table tr:hover { background: #f9f9f9; }
    .wl { font-family: monospace; text-align: center; }
    .points { text-align: center; font-weight: bold; color: #28a745; }
    
    .game-history { background: #f9f9f9; padding: 15px; margin-bottom: 12px; border-left: 4px solid var(--primary-color); border-radius: 4px; cursor: pointer; }
    .game-history h4 { margin: 0; color: var(--primary-color); }
    .game-list { display: none; margin-top: 10px; }
    .game-list.active { display: block; }
    .game-item { background: #fff; padding: 10px; margin-bottom: 8px; border-radius: 3px; font-size: 0.9em; }
    .matchup { display: flex; justify-content: space-between; align-items: center; }
    .date { color: #999; font-size: 0.8em; margin-top: 5px; }
    
    .nav-link { display: block; text-align: center; margin-top: 20px; font-size: 0.9em; }
    .nav-link a { color: var(--primary-color); text-decoration: none; }
    .nav-link a:hover { text-decoration: underline; }
</style>
</head>
<body>
    <div class="container">
        <h1>🏅 Intramural Standings</h1>
        
        <h2>Overall Intramural Points</h2>
        <table class="standings-table">
            <tr>
                <th>Squadron</th>
                <th style="text-align: right;">Total Points</th>
            </tr>
            <?php
            $sortedSquads = $squadrons;
            usort($sortedSquads, fn($a, $b) => ($totalIntramural[$b['id']] ?? 0) <=> ($totalIntramural[$a['id']] ?? 0));
            foreach ($sortedSquads as $squad):
                $pts = $totalIntramural[$squad['id']] ?? 0;
            ?>
            <tr>
                <td>
                    <?php if (!empty($squad['icon_filename'])): ?>
                        <img src="<?php echo htmlspecialchars(iconUrl($squad['icon_filename'])); ?>" alt="icon" style="width: 25px; height: 25px; border-radius: 3px; margin-right: 10px; vertical-align: middle;">
                    <?php endif; ?>
                    <?php echo htmlspecialchars($squad['name']); ?>
                </td>
                <td class="points"><?php echo htmlspecialchars((string) $pts); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <?php foreach ($sports as $sport): ?>
        <h2><?php if (!empty($sport['emoji'])): ?><?php echo htmlspecialchars($sport['emoji']); ?> <?php endif; ?><?php echo htmlspecialchars($sport['sport_name']); ?></h2>
        
        <table class="standings-table">
            <tr>
                <th>Squadron</th>
                <th class="wl">Record</th>
                <th class="points">Points</th>
            </tr>
            <?php
            $records = $standings[$sport['id']]['records'];
            usort($records, fn($a, $b) => 
                ($b['wins'] <=> $a['wins']) ?: 
                ($a['losses'] <=> $b['losses'])
            );
            foreach ($records as $record):
            ?>
            <tr>
                <td>
                    <?php if (!empty($record['squadron']['icon_filename'])): ?>
                        <img src="<?php echo htmlspecialchars(iconUrl($record['squadron']['icon_filename'])); ?>" alt="icon" style="width: 25px; height: 25px; border-radius: 3px; margin-right: 10px; vertical-align: middle;">
                    <?php endif; ?>
                    <?php echo htmlspecialchars($record['squadron']['name']); ?>
                </td>
                <td class="wl"><?php echo htmlspecialchars((string) $record['wins']); ?>-<?php echo htmlspecialchars((string) $record['losses']); ?></td>
                <td class="points"><?php echo htmlspecialchars((string) $record['points']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <h3>Game History - <?php echo htmlspecialchars($sport['sport_name']); ?></h3>
        <?php
        $stmt = $db->prepare("
            SELECT g.*, 
                   t1.name AS team1_name, t1.icon_filename AS team1_icon,
                   t2.name AS team2_name, t2.icon_filename AS team2_icon
            FROM intramural_games g
            JOIN squadrons t1 ON g.team1_id = t1.id
            JOIN squadrons t2 ON g.team2_id = t2.id
            WHERE g.sport_id = ?
            ORDER BY g.game_date DESC
        ");
        $stmt->execute([$sport['id']]);
        $games = $stmt->fetchAll();
        
        if (empty($games)):
        ?>
        <p style="color: #999;">No games recorded yet.</p>
        <?php else: ?>
        <?php foreach ($squadrons as $squad): ?>
            <?php
            $squadGames = array_filter($games, fn($g) => $g['team1_id'] == $squad['id'] || $g['team2_id'] == $squad['id']);
            if (empty($squadGames)) continue;
            ?>
            <div class="game-history" onclick="toggleHistory(this)">
                <h4><?php echo htmlspecialchars($squad['name']); ?></h4>
                <div class="game-list">
                    <?php foreach ($squadGames as $game): ?>
                    <div class="game-item">
                        <div class="matchup">
                            <span>
                                <?php echo htmlspecialchars($game['team1_name']); ?> 
                                <strong><?php echo htmlspecialchars((string) $game['team1_score']); ?></strong>
                                vs
                                <strong><?php echo htmlspecialchars((string) $game['team2_score']); ?></strong>
                                <?php echo htmlspecialchars($game['team2_name']); ?>
                            </span>
                            <?php if ($game['winner_id']): ?>
                                <span style="color: #28a745;">✓ <?php echo htmlspecialchars(($game['winner_id'] == $squad['id']) ? 'Won' : 'Lost'); ?></span>
                            <?php else: ?>
                                <span style="color: #999;">Tie</span>
                            <?php endif; ?>
                        </div>
                        <div class="date"><?php echo htmlspecialchars(date('M j, Y', strtotime($game['game_date']))); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php endforeach; ?>
        
        <div class="nav-link">
            <a href="index.php">&larr; Back to Home</a>
        </div>
    </div>
    
    <script>
        function toggleHistory(element) {
            const list = element.querySelector('.game-list');
            list.classList.toggle('active');
        }
    </script>
</body>
</html>
