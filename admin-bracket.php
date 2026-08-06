<?php
session_start();
require __DIR__ . '/config.php';
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}<?php<?php
session_start();
require __DIR__ . '/config.php';
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$squadrons = readJson(DATA_DIR . '/squadrons.json');
$brackets = readJson(DATA_DIR . '/brackets.json');

// Build map
$squadronMap = [];
foreach ($squadrons as $s) $squadronMap[$s['id']] = $s;

$selectedBracketId = $_GET['bracket_id'] ?? null;
$currentBracket = null;

// Find current bracket
if ($selectedBracketId) {
    foreach ($brackets as &$b) {
        if ($b['id'] === $selectedBracketId) {
            $currentBracket = &$b;
            break;
        }
    }
}

// Create new tournament
if ($_POST && isset($_POST['create_tournament'])) {
    $name = trim($_POST['tournament_name'] ?? '');
    if ($name) {
        $newTourney = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name,
            'created_date' => date('c'),
            'rounds' => [],
            'champion_id' => null
        ];
        $brackets[] = $newTourney;
        writeJson(DATA_DIR . '/brackets.json', $brackets);
        header('Location: admin-bracket.php?bracket_id=' . $newTourney['id']);
        exit;
    }
}

// Add matchup
if ($_POST && isset($_POST['add_matchup']) && $currentBracket) {
    $team1 = (int)($_POST['team1_id'] ?? 0);
    $team2 = (int)($_POST['team2_id'] ?? 0);
    $round = (int)($_POST['round_num'] ?? 1);
    
    if ($team1 && $team2 && $team1 !== $team2) {
        if (!isset($currentBracket['rounds'][$round - 1])) {
            $currentBracket['rounds'][$round - 1] = ['round_num' => $round, 'matchups' => []];
        }
        
        $currentBracket['rounds'][$round - 1]['matchups'][] = [
            'id' => bin2hex(random_bytes(8)),
            'team1_id' => $team1,
            'team2_id' => $team2,
            'team1_score' => null,
            'team2_score' => null,
            'winner_id' => null
        ];
        
        // Update file
        foreach ($brackets as &$b) {
            if ($b['id'] === $currentBracket['id']) {
                $b = $currentBracket;
                break;
            }
        }
        writeJson(DATA_DIR . '/brackets.json', $brackets);
        header('Location: admin-bracket.php?bracket_id=' . $selectedBracketId);
        exit;
    }
}

// Record result
if ($_POST && isset($_POST['record_result']) && $currentBracket) {
    $roundNum = (int)($_POST['round_num'] ?? 0);
    $matchupId = $_POST['matchup_id'] ?? '';
    $winnerId = (int)($_POST['winner_id'] ?? 0);
    $score1 = (int)($_POST['team1_score'] ?? 0);
    $score2 = (int)($_POST['team2_score'] ?? 0);
    
    if ($roundNum > 0 && $matchupId && $winnerId) {
        if (isset($currentBracket['rounds'][$roundNum - 1])) {
            $round = &$currentBracket['rounds'][$roundNum - 1];
            foreach ($round['matchups'] as &$matchup) {
                if ($matchup['id'] === $matchupId) {
                    $matchup['team1_score'] = $score1;
                    $matchup['team2_score'] = $score2;
                    $matchup['winner_id'] = $winnerId;
                    
                    // Auto-advance to next round
                    $nextRoundNum = $roundNum + 1;
                    if ($matchupId && $winnerId) {
                        // Log this event to scores
                        $scores = readJson(DATA_DIR . '/scores.json');
                        $loser = $matchup['team1_id'] === $winnerId ? $matchup['team2_id'] : $matchup['team1_id'];
                        $scores[] = [
                            'squadron_id' => $winnerId,
                            'event_type' => 'bracket',
                            'tournament_name' => $currentBracket['name'],
                            'opponent_id' => $loser,
                            'team1_score' => $matchup['team1_id'] === $winnerId ? $score1 : $score2,
                            'team2_score' => $matchup['team2_id'] === $winnerId ? $score2 : $score1,
                            'winner_id' => $winnerId,
                            'timestamp' => date('c')
                        ];
                        writeJson(DATA_DIR . '/scores.json', $scores);
                    }
                    
                    break;
                }
            }
        }
        
        // Update file
        foreach ($brackets as &$b) {
            if ($b['id'] === $currentBracket['id']) {
                $b = $currentBracket;
                break;
            }
        }
        writeJson(DATA_DIR . '/brackets.json', $brackets);
        header('Location: admin-bracket.php?bracket_id=' . $selectedBracketId);
        exit;
    }
}

// Delete tournament
if (isset($_GET['delete']) && $_GET['delete'] === 'true') {
    $brackets = array_filter($brackets, fn($b) => $b['id'] !== $selectedBracketId);
    writeJson(DATA_DIR . '/brackets.json', array_values($brackets));
    header('Location: admin-bracket.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>NCAA Bracket Manager</title>
<style>
    body { font-family: Arial; background: #f4f4f4; margin: 0; padding: 20px; }
    .container { max-width: 1000px; margin: 0 auto; }
    h1 { color: #002147; }
    h2 { color: #003366; margin-top: 30px; }
    .box { background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
    select, input[type="text"], input[type="number"], button { padding: 10px; border: 1px solid #ddd; border-radius: 3px; margin: 5px 0; }
    button { background: #002147; color: #fff; cursor: pointer; font-weight: bold; }
    button:hover { background: #003366; }
    .matchup { background: #f5f5f5; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #003366; }
    .matchup-form { display: grid; grid-template-columns: 1fr 1fr 100px 100px 80px; gap: 10px; align-items: end; }
    .team-row { padding: 8px; background: #fff; border-radius: 3px; margin: 5px 0; display: flex; justify-content: space-between; }
    .winner { background: #d4edda !important; font-weight: bold; color: #155724; }
    .nav { text-align: center; margin-top: 30px; }
    .nav a { color: #002147; text-decoration: none; margin: 0 15px; }
</style>
</head>
<body>
<div class="container">
    <h1>🏆 NCAA Bracket Manager</h1>
    
    <div class="box">
        <h2>Select or Create Tournament</h2>
        <div style="margin-bottom: 20px;">
            <select onchange="if(this.value) window.location = 'admin-bracket.php?bracket_id=' + this.value">
                <option value="">-- Select Tournament --</option>
                <?php foreach ($brackets as $b): ?>
                <option value="<?php echo $b['id']; ?>" <?php echo $selectedBracketId === $b['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($b['name']); ?> (<?php echo count($b['rounds']); ?> rounds)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <h3>Create New Tournament</h3>
        <form method="POST" style="display: flex; gap: 10px;">
            <input type="text" name="tournament_name" placeholder="Tournament name" required>
            <button type="submit" name="create_tournament">Create Tournament</button>
        </form>
    </div>
    
    <?php if ($currentBracket): ?>
    <div class="box">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2><?php echo htmlspecialchars($currentBracket['name']); ?></h2>
            <a href="?bracket_id=<?php echo $currentBracket['id']; ?>&delete=true" onclick="return confirm('Delete this tournament?')">🗑️ Delete</a>
        </div>
        
        <h3>Add New Matchup</h3>
        <form method="POST" class="matchup-form">
            <select name="team1_id" required>
                <option value="">Team 1</option>
                <?php foreach ($squadrons as $s): ?>
                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="team2_id" required>
                <option value="">Team 2</option>
                <?php foreach ($squadrons as $s): ?>
                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="round_num" value="1" min="1" required>
            <span>Round</span>
            <button type="submit" name="add_matchup">Add</button>
        </form>
        
        <?php if ($currentBracket['rounds']): ?>
            <?php foreach ($currentBracket['rounds'] as $round): ?>
            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-radius: 6px;">
                <h3>Round <?php echo $round['round_num']; ?></h3>
                <?php foreach ($round['matchups'] as $matchup): 
                    $t1 = $squadronMap[$matchup['team1_id']] ?? null;
                    $t2 = $squadronMap[$matchup['team2_id']] ?? null;
                    $winner = $matchup['winner_id'];
                ?>
                <div class="matchup">
                    <p style="margin-top: 0; font-weight: bold;">Match <?php echo substr($matchup['id'], 0, 6); ?></p>
                    <form method="POST" style="display: grid; grid-template-columns: 1fr 80px 1fr 80px auto; gap: 10px; align-items: center;">
                        <input type="hidden" name="round_num" value="<?php echo $round['round_num']; ?>">
                        <input type="hidden" name="matchup_id" value="<?php echo $matchup['id']; ?>">
                        
                        <div class="team-row <?php echo $winner === $matchup['team1_id'] ? 'winner' : ''; ?>">
                            <?php echo htmlspecialchars($t1['name'] ?? 'TBD'); ?>
                        </div>
                        <input type="number" name="team1_score" value="<?php echo $matchup['team1_score'] ?? ''; ?>" placeholder="Score">
                        <div class="team-row <?php echo $winner === $matchup['team2_id'] ? 'winner' : ''; ?>">
                            <?php echo htmlspecialchars($t2['name'] ?? 'TBD'); ?>
                        </div>
                        <input type="number" name="team2_score" value="<?php echo $matchup['team2_score'] ?? ''; ?>" placeholder="Score">
                        
                        <?php if (!$winner): ?>
                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                            <label><input type="radio" name="winner_id" value="<?php echo $matchup['team1_id']; ?>"> <?php echo substr($t1['name'] ?? 'TBD', 0, 1); ?></label>
                            <label><input type="radio" name="winner_id" value="<?php echo $matchup['team2_id']; ?>"> <?php echo substr($t2['name'] ?? 'TBD', 0, 1); ?></label>
                            <button type="submit" name="record_result" style="padding: 8px 12px; font-size: 0.85em;">Set</button>
                        </div>
                        <?php else: ?>
                        <span>✓ Complete</span>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <div class="nav">
        <a href="admin-panel.php">← Back to Admin</a>
    </div>
</div>
</body>
</html>
session_start();
require __DIR__ . '/config.php';
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$squadrons = readJson(DATA_DIR . '/squadrons.json');
$brackets = readJson(DATA_DIR . '/brackets.json');

// Build map
$squadronMap = [];
foreach ($squadrons as $s) $squadronMap[$s['id']] = $s;

$selectedBracketId = $_GET['bracket_id'] ?? null;
$currentBracket = null;

// Find current bracket
if ($selectedBracketId) {
    foreach ($brackets as &$b) {
        if ($b['id'] === $selectedBracketId) {
            $currentBracket = &$b;
            break;
        }
    }
}

// Create new tournament
if ($_POST && isset($_POST['create_tournament'])) {
    $name = trim($_POST['tournament_name'] ?? '');
    if ($name) {
        $newTourney = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name,
            'created_date' => date('c'),
            'rounds' => [],
            'champion_id' => null
        ];
        $brackets[] = $newTourney;
        writeJson(DATA_DIR . '/brackets.json', $brackets);
        header('Location: admin-bracket.php?bracket_id=' . $newTourney['id']);
        exit;
    }
}

// Add matchup
if ($_POST && isset($_POST['add_matchup']) && $currentBracket) {
    $team1 = (int)($_POST['team1_id'] ?? 0);
    $team2 = (int)($_POST['team2_id'] ?? 0);
    $round = (int)($_POST['round_num'] ?? 1);
    
    if ($team1 && $team2 && $team1 !== $team2) {
        if (!isset($currentBracket['rounds'][$round - 1])) {
            $currentBracket['rounds'][$round - 1] = ['round_num' => $round, 'matchups' => []];
        }
        
        $currentBracket['rounds'][$round - 1]['matchups'][] = [
            'id' => bin2hex(random_bytes(8)),
            'team1_id' => $team1,
            'team2_id' => $team2,
            'team1_score' => null,
            'team2_score' => null,
            'winner_id' => null
        ];
        
        // Update file
        foreach ($brackets as &$b) {
            if ($b['id'] === $currentBracket['id']) {
                $b = $currentBracket;
                break;
            }
        }
        writeJson(DATA_DIR . '/brackets.json', $brackets);
        header('Location: admin-bracket.php?bracket_id=' . $selectedBracketId);
        exit;
    }
}

// Record result
if ($_POST && isset($_POST['record_result']) && $currentBracket) {
    $roundNum = (int)($_POST['round_num'] ?? 0);
    $matchupId = $_POST['matchup_id'] ?? '';
    $winnerId = (int)($_POST['winner_id'] ?? 0);
    $score1 = (int)($_POST['team1_score'] ?? 0);
    $score2 = (int)($_POST['team2_score'] ?? 0);
    
    if ($roundNum > 0 && $matchupId && $winnerId) {
        if (isset($currentBracket['rounds'][$roundNum - 1])) {
            $round = &$currentBracket['rounds'][$roundNum - 1];
            foreach ($round['matchups'] as &$matchup) {
                if ($matchup['id'] === $matchupId) {
                    $matchup['team1_score'] = $score1;
                    $matchup['team2_score'] = $score2;
                    $matchup['winner_id'] = $winnerId;
                    
                    // Auto-advance to next round
                    $nextRoundNum = $roundNum + 1;
                    if ($matchupId && $winnerId) {
                        // Log this event to scores
                        $scores = readJson(DATA_DIR . '/scores.json');
                        $loser = $matchup['team1_id'] === $winnerId ? $matchup['team2_id'] : $matchup['team1_id'];
                        $scores[] = [
                            'squadron_id' => $winnerId,
                            'event_type' => 'bracket',
                            'tournament_name' => $currentBracket['name'],
                            'opponent_id' => $loser,
                            'team1_score' => $matchup['team1_id'] === $winnerId ? $score1 : $score2,
                            'team2_score' => $matchup['team2_id'] === $winnerId ? $score2 : $score1,
                            'winner_id' => $winnerId,
                            'timestamp' => date('c')
                        ];
                        writeJson(DATA_DIR . '/scores.json', $scores);
                    }
                    
                    break;
                }
            }
        }
        
        // Update file
        foreach ($brackets as &$b) {
            if ($b['id'] === $currentBracket['id']) {
                $b = $currentBracket;
                break;
            }
        }
        writeJson(DATA_DIR . '/brackets.json', $brackets);
        header('Location: admin-bracket.php?bracket_id=' . $selectedBracketId);
        exit;
    }
}

// Delete tournament
if (isset($_GET['delete']) && $_GET['delete'] === 'true') {
    $brackets = array_filter($brackets, fn($b) => $b['id'] !== $selectedBracketId);
    writeJson(DATA_DIR . '/brackets.json', array_values($brackets));
    header('Location: admin-bracket.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>NCAA Bracket Manager</title>
<style>
    body { font-family: Arial; background: #f4f4f4; margin: 0; padding: 20px; }
    .container { max-width: 1000px; margin: 0 auto; }
    h1 { color: #002147; }
    h2 { color: #003366; margin-top: 30px; }
    .box { background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
    select, input[type="text"], input[type="number"], button { padding: 10px; border: 1px solid #ddd; border-radius: 3px; margin: 5px 0; }
    button { background: #002147; color: #fff; cursor: pointer; font-weight: bold; }
    button:hover { background: #003366; }
    .matchup { background: #f5f5f5; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #003366; }
    .matchup-form { display: grid; grid-template-columns: 1fr 1fr 100px 100px 80px; gap: 10px; align-items: end; }
    .team-row { padding: 8px; background: #fff; border-radius: 3px; margin: 5px 0; display: flex; justify-content: space-between; }
    .winner { background: #d4edda !important; font-weight: bold; color: #155724; }
    .nav { text-align: center; margin-top: 30px; }
    .nav a { color: #002147; text-decoration: none; margin: 0 15px; }
</style>
</head>
<body>
<div class="container">
    <h1>🏆 NCAA Bracket Manager</h1>
    
    <div class="box">
        <h2>Select or Create Tournament</h2>
        <div style="margin-bottom: 20px;">
            <select onchange="if(this.value) window.location = 'admin-bracket.php?bracket_id=' + this.value">
                <option value="">-- Select Tournament --</option>
                <?php foreach ($brackets as $b): ?>
                <option value="<?php echo $b['id']; ?>" <?php echo $selectedBracketId === $b['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($b['name']); ?> (<?php echo count($b['rounds']); ?> rounds)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <h3>Create New Tournament</h3>
        <form method="POST" style="display: flex; gap: 10px;">
            <input type="text" name="tournament_name" placeholder="Tournament name" required>
            <button type="submit" name="create_tournament">Create Tournament</button>
        </form>
    </div>
    
    <?php if ($currentBracket): ?>
    <div class="box">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h2><?php echo htmlspecialchars($currentBracket['name']); ?></h2>
            <a href="?bracket_id=<?php echo $currentBracket['id']; ?>&delete=true" onclick="return confirm('Delete this tournament?')">🗑️ Delete</a>
        </div>
        
        <h3>Add New Matchup</h3>
        <form method="POST" class="matchup-form">
            <select name="team1_id" required>
                <option value="">Team 1</option>
                <?php foreach ($squadrons as $s): ?>
                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="team2_id" required>
                <option value="">Team 2</option>
                <?php foreach ($squadrons as $s): ?>
                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="round_num" value="1" min="1" required>
            <span>Round</span>
            <button type="submit" name="add_matchup">Add</button>
        </form>
        
        <?php if ($currentBracket['rounds']): ?>
            <?php foreach ($currentBracket['rounds'] as $round): ?>
            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-radius: 6px;">
                <h3>Round <?php echo $round['round_num']; ?></h3>
                <?php foreach ($round['matchups'] as $matchup): 
                    $t1 = $squadronMap[$matchup['team1_id']] ?? null;
                    $t2 = $squadronMap[$matchup['team2_id']] ?? null;
                    $winner = $matchup['winner_id'];
                ?>
                <div class="matchup">
                    <p style="margin-top: 0; font-weight: bold;">Match <?php echo substr($matchup['id'], 0, 6); ?></p>
                    <form method="POST" style="display: grid; grid-template-columns: 1fr 80px 1fr 80px auto; gap: 10px; align-items: center;">
                        <input type="hidden" name="round_num" value="<?php echo $round['round_num']; ?>">
                        <input type="hidden" name="matchup_id" value="<?php echo $matchup['id']; ?>">
                        
                        <div class="team-row <?php echo $winner === $matchup['team1_id'] ? 'winner' : ''; ?>">
                            <?php echo htmlspecialchars($t1['name'] ?? 'TBD'); ?>
                        </div>
                        <input type="number" name="team1_score" value="<?php echo $matchup['team1_score'] ?? ''; ?>" placeholder="Score">
                        <div class="team-row <?php echo $winner === $matchup['team2_id'] ? 'winner' : ''; ?>">
                            <?php echo htmlspecialchars($t2['name'] ?? 'TBD'); ?>
                        </div>
                        <input type="number" name="team2_score" value="<?php echo $matchup['team2_score'] ?? ''; ?>" placeholder="Score">
                        
                        <?php if (!$winner): ?>
                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                            <label><input type="radio" name="winner_id" value="<?php echo $matchup['team1_id']; ?>"> <?php echo substr($t1['name'] ?? 'TBD', 0, 1); ?></label>
                            <label><input type="radio" name="winner_id" value="<?php echo $matchup['team2_id']; ?>"> <?php echo substr($t2['name'] ?? 'TBD', 0, 1); ?></label>
                            <button type="submit" name="record_result" style="padding: 8px 12px; font-size: 0.85em;">Set</button>
                        </div>
                        <?php else: ?>
                        <span>✓ Complete</span>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <div class="nav">
        <a href="admin-panel.php">← Back to Admin</a>
    </div>
</div>
</body>
</html>

$squadrons = readJson(DATA_DIR . '/squadrons.json');
$squadronMap = [];
foreach ($squadrons as $s) $squadronMap[$s['id']] = $s;

$brackets = readJson(DATA_DIR . '/brackets.json');
$bracket = null;
$selectedName = $_GET['bracket'] ?? null;

if ($selectedName) {
    foreach ($brackets as $b) {
        if ($b['name'] === $selectedName) {
            $bracket = $b;
            break;
        }
    }
}

// Create bracket if needed
if ($_POST && isset($_POST['create_bracket'])) {
    $name = $_POST['bracket_name'] ?? '';
    if ($name) {
        $newBracket = [
            'name' => $name,
            'created_at' => date('c'),
            'matches' => generateBracket($squadrons),
            'final_winner' => null
        ];
        $brackets[] = $newBracket;
        writeJson(DATA_DIR . '/brackets.json', $brackets);
        header('Location: admin-bracket.php?bracket=' . urlencode($name));
        exit;
    }
}

// Update winner
if ($_POST && isset($_POST['match_id']) && $bracket) {
    $matchId = $_POST['match_id'];
    $winnerId = $_POST['winner_id'] ?? null;
    
    foreach ($bracket['matches'] as &$m) {
        if ($m['id'] === $matchId) {
            $m['winner'] = $winnerId;
            break;
        }
    }
    
    // Update bracket in file
    foreach ($brackets as &$b) {
        if ($b['name'] === $bracket['name']) {
            $b = $bracket;
            break;
        }
    }
    writeJson(DATA_DIR . '/brackets.json', $brackets);
    header('Location: admin-bracket.php?bracket=' . urlencode($bracket['name']));
    exit;
}

function generateBracket($squadrons) {
    $matches = [];
    $count = count($squadrons);
    $id = 1;
    
    for ($i = 0; $i < $count; $i += 2) {
        $matches[] = [
            'id' => (string)$id,
            'round' => 1,
            'squad1_id' => $squadrons[$i]['id'] ?? null,
            'squad2_id' => $squadrons[$i+1]['id'] ?? null,
            'winner' => null
        ];
        $id++;
    }
    return $matches;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Manage Bracket</title>
<style>
    body { font-family: Arial; background: #f4f4f4; margin: 0; padding: 20px; }
    .container { max-width: 900px; margin: 0 auto; }
    .box { background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
    h1 { color: #002147; }
    h2 { color: #003366; }
    select, input, button { padding: 10px; border: 1px solid #ddd; border-radius: 3px; }
    button { background: #002147; color: #fff; cursor: pointer; font-weight: bold; }
    button:hover { background: #003366; }
    .create-form { display: flex; gap: 10px; }
    .create-form input { flex: 1; }
    .bracket { display: grid; gap: 20px; margin-top: 20px; }
    .match { background: #f5f5f5; padding: 15px; border-radius: 4px; }
    .team { padding: 8px 0; display: flex; justify-content: space-between; align-items: center; }
    .team-name { flex: 1; }
    .team-winner { color: #28a745; font-weight: bold; }
    input[type="radio"] { margin: 0 5px; }
    .nav { text-align: center; margin-top: 20px; }
    .nav a { color: #002147; text-decoration: none; margin: 0 15px; }
</style>
</head>
<body>
<div class="container">
    <h1>Bracket Tournament</h1>
    
    <div class="box">
        <h2>Select or Create Bracket</h2>
        <div class="create-form">
            <select onchange="if(this.value) window.location = 'admin-bracket.php?bracket=' + encodeURIComponent(this.value)">
                <option value="">-- Select Bracket --</option>
                <?php foreach ($brackets as $b): ?>
                <option value="<?php echo htmlspecialchars($b['name']); ?>" <?php echo $selectedName === $b['name'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($b['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <h3 style="margin-top: 20px;">Create New Bracket</h3>
        <form method="POST" class="create-form" style="flex-wrap: wrap;">
            <input type="text" name="bracket_name" placeholder="Bracket name (e.g., Spring 2024)" required>
            <button type="submit" name="create_bracket">Create</button>
        </form>
    </div>
    
    <?php if ($bracket): ?>
    <div class="box">
        <h2><?php echo htmlspecialchars($bracket['name']); ?></h2>
        
        <div class="bracket">
            <?php foreach ($bracket['matches'] as $match): 
                $s1 = $squadronMap[$match['squad1_id']] ?? null;
                $s2 = $squadronMap[$match['squad2_id']] ?? null;
            ?>
            <div class="match">
                <p style="margin: 0 0 10px 0; font-weight: bold;">Match <?php echo $match['id']; ?></p>
                <form method="POST">
                    <input type="hidden" name="match_id" value="<?php echo htmlspecialchars($match['id']); ?>">
                    
                    <div class="team">
                        <label class="team-name">
                            <input type="radio" name="winner_id" value="<?php echo $s1['id']; ?>" <?php echo $match['winner'] == $s1['id'] ? 'checked' : ''; ?>>
                            <?php echo htmlspecialchars($s1['name'] ?? 'TBD'); ?>
                        </label>
                        <?php if ($match['winner'] == $s1['id']): ?><span class="team-winner">✓</span><?php endif; ?>
                    </div>
                    
                    <div class="team">
                        <label class="team-name">
                            <input type="radio" name="winner_id" value="<?php echo $s2['id']; ?>" <?php echo $match['winner'] == $s2['id'] ? 'checked' : ''; ?>>
                            <?php echo htmlspecialchars($s2['name'] ?? 'TBD'); ?>
                        </label>
                        <?php if ($match['winner'] == $s2['id']): ?><span class="team-winner">✓</span><?php endif; ?>
                    </div>
                    
                    <button type="submit" style="margin-top: 10px;">Save Result</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="nav">
        <a href="admin-panel.php">← Back to Admin</a>
    </div>
</div>
</body>
</html>
