<?php
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$db = getDb();
$stmt = $db->prepare("SELECT * FROM squadrons ORDER BY id");
$stmt->execute();
$squadrons = $stmt->fetchAll();

// Fetch event types from database
$stmt = $db->prepare("SELECT event_type, display_name, emoji FROM event_type_config ORDER BY display_name ASC");
$stmt->execute();
$eventTypes = $stmt->fetchAll();

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $squadronId = isset($_POST['squadron_id']) ? (int) $_POST['squadron_id'] : 0;
    $eventType = $_POST['event_type'] ?? '';
    $eventName = $_POST['event_name'] ?? '';
    $value = isset($_POST['value']) ? (float)$_POST['value'] : 0;

    // Validate squadron
    $validSquadron = false;
    foreach ($squadrons as $s) {
        if ($s['id'] === $squadronId) {
            $validSquadron = true;
            break;
        }
    }

    // Validate event type
    $validEventType = false;
    foreach ($eventTypes as $et) {
        if ($et['event_type'] === $eventType) {
            $validEventType = true;
            break;
        }
    }

    if (!$validSquadron) {
        $error = 'Invalid squadron.';
    } elseif (!$validEventType) {
        $error = 'Invalid event type.';
    } else {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO events (squadron_id, event_type, event_name, value, points_awarded, timestamp)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $squadronId,
                $eventType,
                $eventName ?: ucfirst($eventType),
                $value,
                $value,
                date('c')
            ]);

            $db->commit();
            $success = true;

            $scoreData = [
                'squadron_id' => $squadronId,
                'event_type' => $eventType,
                'value' => $value,
                'timestamp' => date('c'),
            ];

            require __DIR__ . '/notifications-helper.php';
            require __DIR__ . '/push-service.php';
            $notifications = sendNotificationForScore($scoreData);

            if (!empty($notifications)) {
                $result = sendPushNotifications($notifications);
                error_log('Push delivery for squadron ' . $squadronId . ': ' . $result['sent'] . ' sent, ' . $result['failed'] . ' failed');
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Database error: ' . $e->getMessage();
        }
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
    select, input[type="text"], input[type="number"] { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; border: 1px solid #ddd; border-radius: 4px; }
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
                        <?php if (!empty($type['emoji'])): ?> <?php echo htmlspecialchars($type['emoji']); ?><?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="event_name">Event Title (optional)</label>
            <input type="text" id="event_name" name="event_name" placeholder="e.g., SAMI Round 1, PFT Cycle 2">

            <label for="value">Score Value</label>
            <input type="number" id="value" name="value" step="any" required placeholder="e.g., 10">

            <button type="submit">Save Score</button>
        </form>
        <div class="nav-link">
            <a href="admin-panel.php">&larr; Back to Admin Panel</a>
        </div>
    </div>
</body>
</html>
