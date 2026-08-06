<?php
session_start();

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

function readJson($file) {
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function writeJson($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$dataDir = '/data';
$squadronsFile = $dataDir . '/squadrons.json';
$scoresFile = $dataDir . '/scores.json';

$squadrons = readJson($squadronsFile);
$eventTypes = ['bracket', 'pft', 'samis', 'other'];

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $squadronId = isset($_POST['squadron_id']) ? (int) $_POST['squadron_id'] : 0;
    $eventType = $_POST['event_type'] ?? '';
    $value = isset($_POST['value']) ? $_POST['value'] : '';

    $validSquadron = false;
    foreach ($squadrons as $s) {
        if ($s['id'] === $squadronId) {
            $validSquadron = true;
            break;
        }
    }

    if (!$validSquadron || !in_array($eventType, $eventTypes, true) || $value === '' || !is_numeric($value)) {
        $error = 'Please fill out all fields correctly.';
    } else {
        $scores = readJson($scoresFile);
        $scores[] = [
            'squadron_id' => $squadronId,
            'event_type' => $eventType,
            'value' => (float) $value,
            'timestamp' => date('c'),
        ];
        writeJson($scoresFile, $scores);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Enter Scores - Squadron Tracker</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    .panel { max-width: 500px; margin: 40px auto; background: #fff; padding: 30px; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.15); }
    h1 { color: #002147; text-align: center; }
    label { display: block; margin-top: 12px; font-weight: bold; }
    select, input[type="number"] { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; }
    button { margin-top: 18px; width: 100%; padding: 10px; background: #002147; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    button:hover { background: #003366; }
    .success { color: #1a7a1a; text-align: center; font-weight: bold; }
    .error { color: #b00020; text-align: center; }
    .nav-link { display: block; text-align: center; margin-top: 20px; font-size: 0.9em; }
    .nav-link a { color: #002147; }
</style>
</head>
<body>
    <div class="panel">
        <h1>Enter Scores</h1>
        <?php if ($success): ?>
            <p class="success">Score saved successfully.</p>
        <?php endif; ?>
        <?php if ($error): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <form method="post" action="admin-scores.php">
            <label for="squadron_id">Squadron</label>
            <select id="squadron_id" name="squadron_id" required>
                <option value="">-- Select Squadron --</option>
                <?php foreach ($squadrons as $squadron): ?>
                    <option value="<?php echo htmlspecialchars((string) $squadron['id']); ?>">
                        <?php echo htmlspecialchars($squadron['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="event_type">Event Type</label>
            <select id="event_type" name="event_type" required>
                <option value="">-- Select Event Type --</option>
                <?php foreach ($eventTypes as $type): ?>
                    <option value="<?php echo htmlspecialchars($type); ?>">
                        <?php echo htmlspecialchars(strtoupper($type)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="value">Score Value</label>
            <input type="number" id="value" name="value" step="any" required>

            <button type="submit">Save Score</button>
        </form>
        <div class="nav-link">
            <a href="admin-panel.php">&larr; Back to Admin Panel</a>
        </div>
    </div>
</body>
</html>
