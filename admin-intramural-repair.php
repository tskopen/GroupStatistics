<?php
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$db = getDb();
$message = '';
$error = '';

// Ensure the legacy sport_id column exists and perform the normal safe
// case-insensitive backfill first.
$stmt = $db->query('PRAGMA table_info(intramural_games)');
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('sport_id', $columns, true)) {
    $db->exec('ALTER TABLE intramural_games ADD COLUMN sport_id INTEGER');
}
$db->exec("UPDATE intramural_games SET sport_id = (SELECT s.id FROM intramural_sports s WHERE LOWER(TRIM(s.sport_name)) = LOWER(TRIM(intramural_games.sport)) ORDER BY s.id LIMIT 1) WHERE (sport_id IS NULL OR sport_id = 0) AND sport IS NOT NULL AND TRIM(sport) <> ''");

// Repair an orphaned game by explicitly assigning its immutable sport ID.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_sport') {
    $gameId = (int) ($_POST['game_id'] ?? 0);
    $sportId = (int) ($_POST['sport_id'] ?? 0);

    if (!$gameId || !$sportId) {
        $error = 'A valid game and sport are required.';
    } else {
        $stmt = $db->prepare('SELECT id FROM intramural_sports WHERE id = ?');
        $stmt->execute([$sportId]);
        if (!$stmt->fetchColumn()) {
            $error = 'Selected sport does not exist.';
        } else {
            $stmt = $db->prepare('UPDATE intramural_games SET sport_id = ? WHERE id = ?');
            $stmt->execute([$sportId, $gameId]);
            $message = $stmt->rowCount() ? 'Sport assigned. The game is now linked to its permanent sport ID and can be deleted normally.' : 'No game was updated.';
        }
    }
}

$sports = $db->query('SELECT id, sport_name, emoji FROM intramural_sports ORDER BY sport_name')->fetchAll();

// Show games whose sport_id is missing or points at a nonexistent sport.
$stmt = $db->query("
    SELECT g.id, g.sport_id, g.sport, g.team1_id, g.team2_id,
           g.team1_score, g.team2_score, g.points_team1, g.points_team2,
           g.game_date, t1.name AS team1_name, t2.name AS team2_name
    FROM intramural_games g
    LEFT JOIN intramural_sports s ON s.id = g.sport_id
    JOIN squadrons t1 ON t1.id = g.team1_id
    JOIN squadrons t2 ON t2.id = g.team2_id
    WHERE g.sport_id IS NULL OR g.sport_id = 0 OR s.id IS NULL
    ORDER BY g.game_date DESC, g.id DESC
");
$orphanedGames = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Intramural Data Repair</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;color:#222}.container{max-width:1100px;margin:auto}.panel{background:#fff;padding:20px;margin-bottom:20px;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.15)}h1,h2{color:#002147}.warning{background:#fff3cd;padding:12px;border-radius:4px;margin-bottom:20px}.success{background:#e8f5e9;color:#176b1a;padding:12px;border-radius:4px}.error{background:#ffebee;color:#a00020;padding:12px;border-radius:4px}.game{border:1px solid #ddd;border-radius:5px;padding:15px;margin:12px 0}.meta{color:#666;font-size:.9em}.assign{display:flex;gap:10px;align-items:center;margin-top:12px}.assign select{padding:8px;min-width:240px}.assign button{padding:8px 14px;background:#002147;color:white;border:0;border-radius:4px;cursor:pointer}.back{margin-top:20px}.back a{color:#002147}
</style>
</head>
<body>
<div class="container">
<h1>Intramural Data Repair</h1>
<div class="warning"><strong>Administrator tool:</strong> These games have no valid permanent sport relationship. Assign the correct sport before deleting or managing them.</div>
<?php if ($message): ?><div class="success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<div class="panel">
<h2>Orphaned Games (<?php echo count($orphanedGames); ?>)</h2>
<?php if (!$orphanedGames): ?>
<p>No orphaned intramural games were found.</p>
<?php else: ?>
<?php foreach ($orphanedGames as $game): ?>
<div class="game">
<strong>Game #<?php echo (int)$game['id']; ?></strong>
<div class="meta">Stored sport name: <?php echo htmlspecialchars((string)($game['sport'] ?? '')); ?> | Stored sport_id: <?php echo htmlspecialchars((string)($game['sport_id'] ?? 'NULL')); ?> | Date: <?php echo htmlspecialchars((string)$game['game_date']); ?></div>
<p><?php echo htmlspecialchars($game['team1_name']); ?> <?php echo (int)$game['team1_score']; ?> — <?php echo (int)$game['team2_score']; ?> <?php echo htmlspecialchars($game['team2_name']); ?></p>
<form class="assign" method="post">
<input type="hidden" name="action" value="assign_sport">
<input type="hidden" name="game_id" value="<?php echo (int)$game['id']; ?>">
<label for="sport_<?php echo (int)$game['id']; ?>">Assign permanent sport:</label>
<select id="sport_<?php echo (int)$game['id']; ?>" name="sport_id" required>
<option value="">-- Select Sport --</option>
<?php foreach ($sports as $sport): ?>
<option value="<?php echo (int)$sport['id']; ?>"><?php echo htmlspecialchars(($sport['emoji'] ? $sport['emoji'].' ' : '').$sport['sport_name']); ?></option>
<?php endforeach; ?>
</select>
<button type="submit">Repair Game</button>
</form>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<div class="back"><a href="admin-intramural-games.php">&larr; Back to Intramural Games</a></div>
</div>
</body>
</html>
