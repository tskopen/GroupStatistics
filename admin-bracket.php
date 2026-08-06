<?php
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

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
$squadronMap = [];
foreach ($squadrons as $s) $squadronMap[$s['id']] = $s;

$brackets = readJson($dataDir . '/brackets.json');
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
        writeJson($dataDir . '/brackets.json', $brackets);
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
    writeJson($dataDir . '/brackets.json', $brackets);
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
