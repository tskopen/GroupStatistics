<?php
session_start();
require __DIR__ . '/config.php';
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$squadrons = readJson(DATA_DIR . '/squadrons.json');
$squadronMap = [];
foreach ($squadrons as $s) $squadronMap[$s['id']] = $s;

$brackets = readJson(DATA_DIR . '/brackets.json');
$selectedBracketId = $_GET['bracket_id'] ?? null;
$currentBracket = null;

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

// Add matchup to bracket
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
            'winner_id' => null,
            'points' => null
        ];
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

// Record match result WITH points
if ($_POST && isset($_POST['record_result']) && $currentBracket) {
    $roundNum = (int)($_POST['round_num'] ?? 0);
    $matchupId = $_POST['matchup_id'] ?? '';
    $winnerId = (int)($_POST['winner_id'] ?? 0);
    $score1 = (int)($_POST['team1_score'] ?? 0);
    $score2 = (int)($_POST['team2_score'] ?? 0);
    $points = (int)($_POST['points'] ?? 0);
    
    if ($roundNum > 0 && $matchupId && $winnerId && $points >= 0) {
        if (isset($currentBracket['rounds'][$roundNum - 1])) {
            $round = &$currentBracket['rounds'][$roundNum - 1];
            foreach ($round['matchups'] as &$matchup) {
                if ($matchup['id'] === $matchupId) {
                    $matchup['team1_score'] = $score1;
                    $matchup['team2_score'] = $score2;
                    $matchup['winner_id'] = $winnerId;
                    $matchup['points'] = $points;
                    
                    // Save to scores.json with points
                    $scores = readJson(DATA_DIR . '/scores.json');
                    $loser = $matchup['team1_id'] === $winnerId ? $matchup['team2_id'] : $matchup['team1_id'];
                    $scores[] = [
                        'squadron_id' => $winnerId,
                        'event_type' => 'bracket',
                        'tournament_name' => $currentBracket['name'],
                        'value' => $points,
                        'opponent_id' => $loser,
                        'team1_score' => $matchup['team1_id'] === $winnerId ? $score1 : $score2,
                        'team2_score' => $matchup['team2_id'] === $winnerId ? $score2 : $score1,
                        'winner_id' => $winnerId,
                        'timestamp' => date('c')
                    ];
                    writeJson(DATA_DIR . '/scores.json', $scores);
                    break;
                }
            }
        }
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

// Delete bracket
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
    <title>Bracket Manager</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #002147; text-align: center; margin-bottom: 10px; }
        h2 { color: #003366; margin-top: 30px; border-bottom: 2px solid #003366; padding-bottom: 8px; }
        h3 { color: #004499; margin-top: 20px; }
        
        /* Layout */
        .box { background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        
        /* Form Elements */
        select, input[type="text"], input[type="number"] {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 14px;
            margin: 5px 0;
        }
        select { width: 100%; }
        
        button {
            padding: 10px 16px;
            background: #002147;
            color: #fff;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: background 0.2s;
        }
        button:hover { background: #003366; }
        button.secondary { background: #666; }
        button.secondary:hover { background: #444; }
        
        /* Tournament Selector */
        .tournament-selector { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .tournament-selector select { flex: 1; min-width: 200px; }
        
        /* Create Tournament Form */
        .create-tournament { background: #e3f2fd; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .create-tournament-form { display: flex; gap: 10px; flex-wrap: wrap; }
        .create-tournament-form input { flex: 1; min-width: 200px; }
        
        /* Bracket Tree */
        .bracket-tree { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        
        .round-column {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #003366;
        }
        
        .round-title {
            font-weight: bold;
            color: #003366;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #003366;
        }
        
        .matchup {
            background: #fff;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            border: 2px solid #ddd;
            transition: border-color 0.2s;
        }
        .matchup:last-child { margin-bottom: 0; }
        .matchup.completed { border-color: #28a745; background: #f0f8f5; }
        .matchup.pending { border-color: #ff9800; }
        
        .matchup-title {
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
            font-size: 13px;
        }
        
        .team-display {
            padding: 8px;
            background: #f5f5f5;
            border-radius: 3px;
            margin: 5px 0;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .team-display.winner {
            background: #d4edda;
            color: #155724;
            font-weight: bold;
        }
        
        /* Form within matchup */
        .matchup-form-section {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #ddd;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 8px;
        }
        .form-row.full { grid-template-columns: 1fr; }
        
        .form-row input { margin: 0; }
        
        .radio-group {
            display: flex;
            gap: 15px;
            margin: 8px 0;
            flex-wrap: wrap;
        }
        .radio-group label {
            display: flex;
            align-items: center;
            gap: 5px;
            margin: 0;
            cursor: pointer;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        .button-group button { flex: 1; }
        
        /* Add Matchup Form */
        .add-matchup-form {
            background: #f0f7ff;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .add-matchup-form h3 { margin-top: 0; }
        .add-matchup-fields {
            display: grid;
            grid-template-columns: 1fr 1fr 100px auto;
            gap: 10px;
            align-items: end;
        }
        @media (max-width: 768px) {
            .add-matchup-fields { grid-template-columns: 1fr; }
            .add-matchup-fields button { grid-column: 1 / -1; }
        }
        
        /* Footer */
        .nav { text-align: center; margin-top: 30px; }
        .nav a {
            display: inline-block;
            color: #002147;
            text-decoration: none;
            margin: 0 10px;
            padding: 8px 16px;
            border: 1px solid #002147;
            border-radius: 3px;
            transition: all 0.2s;
        }
        .nav a:hover { background: #002147; color: #fff; }
        
        .delete-button {
            background: #d32f2f;
            font-size: 12px;
            padding: 6px 10px;
            margin-top: 10px;
            width: 100%;
        }
        .delete-button:hover { background: #b71c1c; }
        
        .no-bracket {
            text-align: center;
            color: #666;
            padding: 40px 20px;
        }
        .no-bracket p { font-size: 16px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏆 Bracket Manager</h1>
        
        <!-- Tournament Selector -->
        <div class="box">
            <h2>Select Tournament</h2>
            <div class="tournament-selector">
                <select onchange="if(this.value) window.location='admin-bracket.php?bracket_id='+this.value">
                    <option value="">-- Select a Tournament --</option>
                    <?php foreach($brackets as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $selectedBracketId === $b['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Create New Tournament -->
        <div class="box">
            <h2>Create New Tournament</h2>
            <div class="create-tournament">
                <form method="POST" class="create-tournament-form">
                    <input type="text" name="tournament_name" placeholder="Tournament Name (e.g., Spring 2024 Bracket)" required>
                    <button type="submit" name="create_tournament">Create Tournament</button>
                </form>
            </div>
        </div>
        
        <?php if($currentBracket): ?>
        <div class="box">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;"><?php echo htmlspecialchars($currentBracket['name']); ?></h2>
                <button class="delete-button" onclick="if(confirm('Delete this entire tournament?')) window.location='?bracket_id=<?php echo $currentBracket['id']; ?>&delete=true'">Delete Tournament</button>
            </div>
            
            <!-- Add New Matchup -->
            <div class="add-matchup-form">
                <h3>Add New Matchup</h3>
                <form method="POST" class="add-matchup-fields">
                    <select name="team1_id" required>
                        <option value="">Team 1</option>
                        <?php foreach($squadrons as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="team2_id" required>
                        <option value="">Team 2</option>
                        <?php foreach($squadrons as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="round_num" value="1" min="1" placeholder="Round" required>
                    <button type="submit" name="add_matchup">Add</button>
                </form>
            </div>
            
            <!-- Bracket Tree Display -->
            <?php if($currentBracket['rounds']): ?>
                <h3>Tournament Rounds</h3>
                <div class="bracket-tree">
                    <?php foreach($currentBracket['rounds'] as $round): ?>
                    <div class="round-column">
                        <div class="round-title">Round <?php echo $round['round_num']; ?></div>
                        <?php foreach($round['matchups'] as $matchup): 
                            $t1 = $squadronMap[$matchup['team1_id']] ?? null;
                            $t2 = $squadronMap[$matchup['team2_id']] ?? null;
                            $winner = $matchup['winner_id'];
                            $isCompleted = $winner !== null;
                        ?>
                        <div class="matchup <?php echo $isCompleted ? 'completed' : 'pending'; ?>">
                            <div class="matchup-title">Match <?php echo substr($matchup['id'], 0, 6); ?></div>
                            
                            <!-- Completed Match -->
                            <?php if($isCompleted): ?>
                                <div class="team-display <?php echo $winner === $matchup['team1_id'] ? 'winner' : ''; ?>">
                                    <span><?php echo htmlspecialchars($t1['name'] ?? 'TBD'); ?></span>
                                    <span><strong><?php echo $matchup['team1_score']; ?></strong></span>
                                </div>
                                <div class="team-display <?php echo $winner === $matchup['team2_id'] ? 'winner' : ''; ?>">
                                    <span><?php echo htmlspecialchars($t2['name'] ?? 'TBD'); ?></span>
                                    <span><strong><?php echo $matchup['team2_score']; ?></strong></span>
                                </div>
                                <div style="margin-top: 8px; padding: 8px; background: #e8f5e9; border-radius: 3px; text-align: center; color: #2e7d32; font-weight: bold; font-size: 13px;">
                                    ✓ Points Awarded: <?php echo $matchup['points']; ?>
                                </div>
                            <!-- Pending Match - Form to record result -->
                            <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="round_num" value="<?php echo $round['round_num']; ?>">
                                    <input type="hidden" name="matchup_id" value="<?php echo $matchup['id']; ?>">
                                    
                                    <div class="team-display">
                                        <span><?php echo htmlspecialchars($t1['name'] ?? 'TBD'); ?></span>
                                        <input type="number" name="team1_score" placeholder="Score" min="0" required style="width: 60px; margin: 0; padding: 5px;">
                                    </div>
                                    <div class="team-display">
                                        <span><?php echo htmlspecialchars($t2['name'] ?? 'TBD'); ?></span>
                                        <input type="number" name="team2_score" placeholder="Score" min="0" required style="width: 60px; margin: 0; padding: 5px;">
                                    </div>
                                    
                                    <div class="matchup-form-section">
                                        <div style="margin-bottom: 8px; font-weight: bold; font-size: 13px;">Select Winner & Points</div>
                                        <div class="radio-group">
                                            <label>
                                                <input type="radio" name="winner_id" value="<?php echo $matchup['team1_id']; ?>" required>
                                                <?php echo substr($t1['name'] ?? 'TBD', 0, 10); ?>
                                            </label>
                                            <label>
                                                <input type="radio" name="winner_id" value="<?php echo $matchup['team2_id']; ?>" required>
                                                <?php echo substr($t2['name'] ?? 'TBD', 0, 10); ?>
                                            </label>
                                        </div>
                                        <div class="form-row full">
                                            <input type="number" name="points" placeholder="Points for winning" min="0" value="0" required style="margin: 0;">
                                        </div>
                                        <div class="button-group">
                                            <button type="submit" name="record_result">Record Result</button>
                                        </div>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-bracket">
                    <p>No rounds yet. Add matchups above to start building the bracket.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
            <div class="box">
                <div class="no-bracket">
                    <p>Create a new tournament above or select one from the dropdown to get started.</p>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="nav">
            <a href="admin-panel.php">← Back to Admin</a>
        </div>
    </div>
</body>
</html>
