<?php
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$db = getDb();
$success = '';
$error = '';

$stmt = $db->prepare("SELECT * FROM squadrons ORDER BY id");
$stmt->execute();
$squadrons = $stmt->fetchAll();

$stmt = $db->prepare("SELECT * FROM intramural_sports ORDER BY sport_name ASC");
$stmt->execute();
$sports = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_game') {
        $sportId = (int) ($_POST['sport_id'] ?? 0);
        $team1Id = (int) ($_POST['team1_id'] ?? 0);
        $team2Id = (int) ($_POST['team2_id'] ?? 0);
        $team1Score = (int) ($_POST['team1_score'] ?? 0);
        $team2Score = (int) ($_POST['team2_score'] ?? 0);
        $gameDate = $_POST['game_date'] ?? date('Y-m-d');

        if (!$sportId || !$team1Id || !$team2Id || $team1Id === $team2Id) {
            $error = 'Invalid sport or teams selected.';
        } else {
            try {
                $db->beginTransaction();

                $winnerId = null;
                if ($team1Score > $team2Score) {
                    $winnerId = $team1Id;
                } elseif ($team2Score > $team1Score) {
                    $winnerId = $team2Id;
                }

                $sport = null;
                foreach ($sports as $s) {
                    if ($s['id'] === $sportId) {
                        $sport = $s;
                        break;
                    }
                }

                if (!$sport) {
                    throw new Exception('Sport not found.');
                }

                $pointsTeam1 = ($team1Score > $team2Score) ? $sport['points_win'] : $sport['points_loss'];
                $pointsTeam2 = ($team2Score > $team1Score) ? $sport['points_win'] : $sport['points_loss'];

                $stmt = $db->prepare("
                    INSERT INTO intramural_games (sport_id, team1_id, team2_id, team1_score, team2_score, winner_id, points_team1, points_team2, game_date, timestamp, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $sportId,
                    $team1Id,
                    $team2Id,
                    $team1Score,
                    $team2Score,
                    $winnerId,
                    $pointsTeam1,
                    $pointsTeam2,
                    $gameDate,
                    date('c'),
                    date('c'),
                ]);

                $stmtCheck = $db->prepare("SELECT id FROM intramural_wl_records WHERE squadron_id = ? AND sport_id = ?");
                $stmtCheck->execute([$team1Id, $sportId]);
                $recordExists = $stmtCheck->fetch();

                if ($recordExists) {
                    $stmtUpdate = $db->prepare("
                        UPDATE intramural_wl_records 
                        SET wins = wins + ?, losses = losses + ?, points_awarded = points_awarded + ?, updated_at = ?
                        WHERE squadron_id = ? AND sport_id = ?
                    ");
                    $team1Wins = ($team1Score > $team2Score) ? 1 : 0;
                    $team1Losses = ($team1Score < $team2Score) ? 1 : 0;
                    $stmtUpdate->execute([$team1Wins, $team1Losses, $pointsTeam1, date('c'), $team1Id, $sportId]);
                } else {
                    $stmtInsert = $db->prepare("
                        INSERT INTO intramural_wl_records (squadron_id, sport_id, wins, losses, points_awarded, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $team1Wins = ($team1Score > $team2Score) ? 1 : 0;
                    $team1Losses = ($team1Score < $team2Score) ? 1 : 0;
                    $stmtInsert->execute([$team1Id, $sportId, $team1Wins, $team1Losses, $pointsTeam1, date('c')]);
                }

                $stmtCheck = $db->prepare("SELECT id FROM intramural_wl_records WHERE squadron_id = ? AND sport_id = ?");
                $stmtCheck->execute([$team2Id, $sportId]);
                $recordExists = $stmtCheck->fetch();

                if ($recordExists) {
                    $stmtUpdate = $db->prepare("
                        UPDATE intramural_wl_records 
                        SET wins = wins + ?, losses = losses + ?, points_awarded = points_awarded + ?, updated_at = ?
                        WHERE squadron_id = ? AND sport_id = ?
                    ");
                    $team2Wins = ($team2Score > $team1Score) ? 1 : 0;
                    $team2Losses = ($team2Score < $team1Score) ? 1 : 0;
                    $stmtUpdate->execute([$team2Wins, $team2Losses, $pointsTeam2, date('c'), $team2Id, $sportId]);
                } else {
                    $stmtInsert = $db->prepare("
                        INSERT INTO intramural_wl_records (squadron_id, sport_id, wins, losses, points_awarded, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $team2Wins = ($team2Score > $team1Score) ? 1 : 0;
                    $team2Losses = ($team2Score < $team1Score) ? 1 : 0;
                    $stmtInsert->execute([$team2Id, $sportId, $team2Wins, $team2Losses, $pointsTeam2, date('c')]);
                }

                $db->commit();
                $success = 'Game recorded successfully.';
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Error recording game: ' . $e->getMessage();
            }
        }
    }
}

$stmt = $db->prepare("
    SELECT g.*, s.sport_name, s.emoji, 
           t1.name AS team1_name, t1.icon_filename AS team1_icon,
           t2.name AS team2_name, t2.icon_filename AS team2_icon
    FROM intramural_games g
    JOIN intramural_sports s ON g.sport_id = s.id
    JOIN squadrons t1 ON g.team1_id = t1.id
    JOIN squadrons t2 ON g.team2_id = t2.id
    ORDER BY g.game_date DESC, g.created_at DESC
    LIMIT 50
");
$stmt->execute();
$recentGames = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Intramural Games - Squadron Tracker</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    .container { max-width: 1000px; margin: 0 auto; }
    h1 { color: #002147; text-align: center; }
    h2 { color: #002147; border-bottom: 2px solid #002147; padding-bottom: 8px; margin-top: 30px; }
    .panel { background: #fff; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.15); padding: 20px; margin-bottom: 20px; }
    label { display: block; margin-top: 12px; font-weight: bold; }
    input[type="text"], input[type="number"], input[type="date"], select { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; border: 1px solid #ddd; border-radius: 4px; }
    button { margin-top: 18px; padding: 10px 16px; background: #002147; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    button:hover { background: #003366; }
    .success { color: #1a7a1a; background: #e8f5e9; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
    .error { color: #b00020; background: #ffebee; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
    .row { display: flex; gap: 20px; }
    .col { flex: 1; }
    .game-card { background: #f9f9f9; padding: 15px; margin-bottom: 12px; border-left: 4px solid #002147; border-radius: 4px; }
    .game-card h4 { margin: 0 0 10px; color: #002147; }
    .game-matchup { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 0; }
    .team { display: flex; align-items: center; gap: 10px; flex: 1; }
    .team-icon { width: 30px; height: 30px; border-radius: 3px; object-fit: cover; }
    .score { font-size: 1.2em; font-weight: bold; color: #002147; min-width: 50px; text-align: center; }
    .nav-link { display: block; text-align: center; margin-top: 20px; font-size: 0.9em; }
    .nav-link a { color: #002147; text-decoration: none; }
</style>
</head>
<body>
    <div class="container">
        <h1>🎮 Intramural Games</h1>
        <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        
        <div class="panel">
            <h2>Record Game</h2>
            <form method="post" action="admin-intramural-games.php">
                <input type="hidden" name="action" value="add_game">
                
                <div class="row">
                    <div class="col">
                        <label for="sport_id">Sport</label>
                        <select id="sport_id" name="sport_id" required>
                            <option value="">-- Select Sport --</option>
                            <?php foreach ($sports as $sport): ?>
                                <option value="<?php echo htmlspecialchars((string) $sport['id']); ?>">
                                    <?php if (!empty($sport['emoji'])): ?><?php echo htmlspecialchars($sport['emoji']); ?> <?php endif; ?>
                                    <?php echo htmlspecialchars($sport['sport_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <label for="game_date">Game Date</label>
                        <input type="date" id="game_date" name="game_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col">
                        <label for="team1_id">Team 1</label>
                        <select id="team1_id" name="team1_id" required>
                            <option value="">-- Select Squadron --</option>
                            <?php foreach ($squadrons as $sq): ?>
                                <option value="<?php echo htmlspecialchars((string) $sq['id']); ?>">
                                    <?php echo htmlspecialchars($sq['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <label for="team1_score">Team 1 Score</label>
                        <input type="number" id="team1_score" name="team1_score" value="0" required>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col">
                        <label for="team2_id">Team 2</label>
                        <select id="team2_id" name="team2_id" required>
                            <option value="">-- Select Squadron --</option>
                            <?php foreach ($squadrons as $sq): ?>
                                <option value="<?php echo htmlspecialchars((string) $sq['id']); ?>">
                                    <?php echo htmlspecialchars($sq['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <label for="team2_score">Team 2 Score</label>
                        <input type="number" id="team2_score" name="team2_score" value="0" required>
                    </div>
                </div>
                
                <button type="submit">Record Game</button>
            </form>
        </div>
        
        <div class="panel">
            <h2>Recent Games</h2>
            <?php if (empty($recentGames)): ?>
                <p>No games recorded yet.</p>
            <?php endif; ?>
            <?php foreach ($recentGames as $game): ?>
                <div class="game-card">
                    <h4><?php if (!empty($game['emoji'])): ?><?php echo htmlspecialchars($game['emoji']); ?> <?php endif; ?><?php echo htmlspecialchars($game['sport_name']); ?> - <?php echo htmlspecialchars(date('M j, Y', strtotime($game['game_date']))); ?></h4>
                    <div class="game-matchup">
                        <div class="team">
                            <?php if (!empty($game['team1_icon'])): ?>
                                <img src="<?php echo htmlspecialchars(iconUrl($game['team1_icon'])); ?>" alt="icon" class="team-icon">
                            <?php else: ?>
                                <div class="team-icon" style="background: #ccc;"></div>
                            <?php endif; ?>
                            <span><?php echo htmlspecialchars($game['team1_name']); ?></span>
                        </div>
                        <div class="score"><?php echo htmlspecialchars((string) $game['team1_score']); ?></div>
                        <div style="color: #999;">vs</div>
                        <div class="score"><?php echo htmlspecialchars((string) $game['team2_score']); ?></div>
                        <div class="team" style="flex-direction: row-reverse;">
                            <?php if (!empty($game['team2_icon'])): ?>
                                <img src="<?php echo htmlspecialchars(iconUrl($game['team2_icon'])); ?>" alt="icon" class="team-icon">
                            <?php else: ?>
                                <div class="team-icon" style="background: #ccc;"></div>
                            <?php endif; ?>
                            <span><?php echo htmlspecialchars($game['team2_name']); ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="nav-link">
            <a href="admin-panel.php">&larr; Back to Admin Panel</a>
        </div>
    </div>
</body>
</html>
