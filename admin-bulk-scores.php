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
$scores = readJson($dataDir . '/scores.json');
$success = '';

if ($_POST) {
    $eventName = $_POST['event_name'] ?? '';
    $eventType = $_POST['event_type'] ?? 'other';
    
    if ($eventName) {
        foreach ($squadrons as $s) {
            $sid = $s['id'];
            $scoreVal = isset($_POST["score_$sid"]) ? (float)$_POST["score_$sid"] : 0;
            if ($scoreVal > 0) {
                $scores[] = [
                    'squadron_id' => $sid,
                    'event_name' => $eventName,
                    'event_type' => $eventType,
                    'value' => $scoreVal,
                    'timestamp' => date('c')
                ];
            }
        }
        writeJson($dataDir . '/scores.json', $scores);
        $success = "Event '$eventName' recorded for all squadrons!";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Enter Event Scores</title>
<style>
    body { font-family: Arial; background: #f4f4f4; margin: 0; padding: 20px; }
    .container { max-width: 700px; margin: 0 auto; }
    .form-box { background: #fff; padding: 30px; border-radius: 6px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    h1 { color: #002147; text-align: center; }
    label { display: block; margin-top: 15px; font-weight: bold; }
    input, select { width: 100%; padding: 10px; margin-top: 4px; box-sizing: border-box; border: 1px solid #ddd; border-radius: 3px; }
    button { width: 100%; padding: 10px; margin-top: 20px; background: #28a745; color: #fff; border: none; border-radius: 3px; cursor: pointer; font-weight: bold; }
    button:hover { background: #218838; }
    .score-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px; }
    .score-input { padding: 15px; background: #f9f9f9; border-radius: 4px; border: 1px solid #eee; }
    .score-input label { margin: 0; display: flex; align-items: center; gap: 10px; }
    .score-input input { margin: 0; width: auto; flex: 1; }
    .icon { width: 30px; height: 30px; border-radius: 3px; object-fit: cover; }
    .success { background: #d4edda; color: #1a7a1a; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
    .nav { text-align: center; margin-top: 20px; }
    .nav a { color: #002147; text-decoration: none; }
</style>
</head>
<body>
<div class="container">
    <div class="form-box">
        <h1>Enter Event Scores</h1>
        <?php if ($success): ?>
        <div class="success">✓ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <label>Event Name</label>
            <input type="text" name="event_name" placeholder="e.g., PFT Round 1" required>
            
            <label>Event Type</label>
            <select name="event_type">
                <option value="bracket">Bracket Competition</option>
                <option value="pft">PFT (Physical Fitness Test)</option>
                <option value="samis">SAMIS</option>
                <option value="other">Other Event</option>
            </select>
            
            <label style="margin-top: 25px;">Scores</label>
            <div class="score-grid">
                <?php foreach ($squadrons as $s): ?>
                <div class="score-input">
                    <label>
                        <?php if ($s['icon']): ?>
                        <img src="<?php echo htmlspecialchars($s['icon']); ?>" class="icon">
                        <?php endif; ?>
                        <span><?php echo htmlspecialchars($s['name']); ?></span>
                    </label>
                    <input type="number" name="score_<?php echo $s['id']; ?>" step="0.01" placeholder="Score">
                </div>
                <?php endforeach; ?>
            </div>
            
            <button type="submit">Record Event</button>
        </form>
        
        <div class="nav">
            <a href="admin-panel.php">← Back to Admin</a>
        </div>
    </div>
</div>
</body>
</html>
