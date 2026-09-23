<?php
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$squadronsFile = DATA_DIR . '/squadrons.json';
$scoresFile = DATA_DIR . '/scores.json';

$squadrons = readJson($squadronsFile);

// Fetch event types from database configuration
require __DIR__ . '/db-migrate.php';
$eventTypes = dbFetchAll("SELECT event_type, display_name FROM event_type_config ORDER BY display_name ASC");
$eventTypeOptions = array_map(fn($et) => $et['event_type'], $eventTypes);

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $squadronId = isset($_POST['squadron_id']) ? (int) $_POST['squadron_id'] : 0;
    $eventType = $_POST['event_type'] ?? '';
    $eventName = $_POST['event_name'] ?? '';

    // Validate squadron
    $validSquadron = false;
    foreach ($squadrons as $s) {
        if ($s['id'] === $squadronId) {
            $validSquadron = true;
            break;
        }
    }

    // Validate event type exists in config
    if (!in_array($eventType, $eventTypeOptions, true)) {
        $error = 'Invalid event type.';
    } elseif (!$validSquadron) {
        $error = 'Invalid squadron.';
    } else {
        // Fetch the configured point value for this event type
        $config = dbFetchOne("SELECT points_awarded FROM event_type_config WHERE event_type = ?", [$eventType]);
        $value = $config['points_awarded'] ?? 0;

        $scores = readJson($scoresFile);
        $scoreData = [
            'squadron_id' => $squadronId,
            'event_type' => $eventType,
            'event_name' => $eventName ?: ucfirst($eventType),
            'value' => $value,
            'timestamp' => date('c'),
        ];
        $scores[] = $scoreData;
        writeJson($scoresFile, $scores);

        require __DIR__ . '/notifications-helper.php';
        require __DIR__ . '/push-service.php';
        $notifications = sendNotificationForScore($scoreData);

        if (!empty($notifications)) {
            $result = sendPushNotifications($notifications);
            error_log('Push delivery for squadron ' . $squadronId . ': ' . $result['sent'] . ' sent, ' . $result['failed'] . ' failed');
        }

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
                    <option value="<?php echo htmlspecialchars($type['event_type']); ?>">
                        <?php echo htmlspecialchars($type['display_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="event_name">Event Name (optional, auto-filled from type)</label>
            <input type="text" id="event_name" name="event_name" placeholder="e.g., SAMI Round 1">

            <button type="submit">Save Score</button>
        </form>
        <div class="nav-link">
            <a href="admin-panel.php">&larr; Back to Admin Panel</a>
        </div>
    </div>
</body>
</html>
