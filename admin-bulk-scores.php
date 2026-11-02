<?php
session_start();
require __DIR__ . '/config.php';
require_once __DIR__ . '/theme-loader.php';
$theme = loadTheme();
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$db = getDb();

$stmt = $db->prepare("SELECT id, name, icon_filename FROM squadrons ORDER BY id");
$stmt->execute();
$squadrons = $stmt->fetchAll();

$success = '';
$error = '';

$stmt = $db->prepare("SELECT event_type, display_name FROM event_type_config WHERE event_type NOT IN ('bracket', 'cadet_of_month') ORDER BY display_name ASC");
$stmt->execute();
$eventTypesRows = $stmt->fetchAll();
$eventTypes = array_map(fn($et) => $et['event_type'], $eventTypesRows);

if ($_POST) {
    $eventName = trim((string)($_POST['event_name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $eventType = normalizeEventType($_POST['event_type'] ?? 'other');

    if ($eventName === '') {
        $error = 'Event name is required.';
    } elseif (strlen($eventName) > 160) {
        $error = 'Event titles must be 160 characters or fewer.';
    } elseif (strlen($description) > 3000) {
        $error = 'Event descriptions must be 3,000 characters or fewer.';
    } elseif (!in_array($eventType, $eventTypes, true)) {
        $error = 'Invalid event type.';
    } else {
        $newScores = [];
        $timestamp = date('c');

        try {
            $db->beginTransaction();

            // Store display-only scores in the isolated live event results ledger.
            // This table is not read by OPA or leaderboard aggregation code.
            $insertStmt = $db->prepare("
                INSERT INTO live_event_results
                    (squadron_id,event_type,event_name,description,value,timestamp,created_at,created_by)
                VALUES (?,?,?,?,?,?,?,?)
            ");

            foreach ($squadrons as $s) {
                $sid = (int) $s['id'];
                $scoreRaw = trim((string)($_POST["score_$sid"] ?? ''));
                if ($scoreRaw === '') continue;

                $scoreVal = filter_var($scoreRaw, FILTER_VALIDATE_FLOAT);
                if ($scoreVal === false || !is_finite((float)$scoreVal) || (float)$scoreVal < 0) {
                    throw new Exception('Enter a valid non-negative score for ' . $s['name'] . '.');
                }

                $scoreVal = (float)$scoreVal;
                $insertStmt->execute([
                    $sid,
                    $eventType,
                    $eventName,
                    $description,
                    $scoreVal,
                    $timestamp,
                    $timestamp,
                    (string)($_SESSION['username'] ?? 'admin'),
                ]);

                $newScores[] = [
                    'squadron_id' => $sid,
                    'event_name' => $eventName,
                    'event_type' => $eventType,
                    'value' => $scoreVal,
                    'timestamp' => $timestamp,
                ];
            }

            if (!$newScores) {
                throw new Exception('Enter at least one squadron score before posting the event.');
            }

            // Verify that every bulk row uses the same canonical event score
            // and compatibility points field before making the transaction visible.
            if (!empty($newScores)) {
                $verifyStmt = $db->prepare('
                    SELECT value, description, legacy_event_id
                    FROM live_event_results
                    WHERE squadron_id = ? AND event_name = ? AND event_type = ? AND timestamp = ?
                    ORDER BY id DESC
                    LIMIT 1
                ');
                foreach ($newScores as $scoreData) {
                    $verifyStmt->execute([
                        $scoreData['squadron_id'],
                        $scoreData['event_name'],
                        $scoreData['event_type'],
                        $timestamp,
                    ]);
                    $verifyRow = $verifyStmt->fetch();
                    if (
                        $verifyRow === false ||
                        (float) $verifyRow['value'] !== (float) $scoreData['value'] ||
                        (string) $verifyRow['description'] !== $description ||
                        $verifyRow['legacy_event_id'] !== null
                    ) {
                        throw new Exception('Bulk event score verification failed.');
                    }
                }
            }

            $db->commit();

            // The event rows are committed before sending optional push notices.
            // Delivery failures must not turn a saved event into an apparent failure.
            try {
                require_once __DIR__ . '/notifications-helper.php';
                require_once __DIR__ . '/push-service.php';
                foreach ($newScores as $scoreData) {
                    $notifications = sendNotificationForScore($scoreData);
                    if (!empty($notifications)) {
                        $result = sendPushNotifications($notifications);
                        error_log('Bulk event ' . $eventName . ' - squadron ' . $scoreData['squadron_id'] . ': ' . $result['sent'] . ' sent, ' . $result['failed'] . ' failed');
                    }
                }
            } catch (Throwable $notificationError) {
                error_log('[EVENT-BOARD] Bulk notification error: ' . $notificationError->getMessage());
            }

            $success = "Display-only event '$eventName' recorded for " . count($newScores) . " squadron(s). OPA and leaderboard standings were not changed.";
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Bulk event insert rolled back: ' . $e->getMessage());
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Post Event for Multiple Squadrons</title>
<style>
    body { font-family: Arial; background: #f4f4f4; margin: 0; padding: 20px; }
    .container { max-width: 700px; margin: 0 auto; }
    .form-box { background: #fff; padding: 30px; border-radius: 6px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    h1 { color: #002147; text-align: center; }
    label { display: block; margin-top: 15px; font-weight: bold; }
    input, select, textarea { width: 100%; padding: 10px; margin-top: 4px; box-sizing: border-box; border: 1px solid #ddd; border-radius: 3px; font: inherit; }
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
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
<style>
:root{--primary-color:<?php echo htmlspecialchars($theme['primary_color']??'#002147',ENT_QUOTES,'UTF-8'); ?>;--secondary-color:<?php echo htmlspecialchars($theme['secondary_color']??'#003366',ENT_QUOTES,'UTF-8'); ?>;--accent-color:<?php echo htmlspecialchars($theme['accent_color']??'#667eea',ENT_QUOTES,'UTF-8'); ?>;--background-color:<?php echo htmlspecialchars($theme['background_color']??'#f4f4f4',ENT_QUOTES,'UTF-8'); ?>;--text-color:<?php echo htmlspecialchars($theme['text_color']??'#222222',ENT_QUOTES,'UTF-8'); ?>}
</style>
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
<div class="container">
    <div class="form-box">
        <h1>Post Event for Multiple Squadrons</h1>
        <p style="color:#64748b;text-align:center;margin-top:-8px;">A display-only event for the public Results board. These values never enter OPA or leaderboard calculations.</p>
        <?php if ($success): ?>
        <div class="success">✓ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="success" style="background:#f8d7da; color:#b00020;">⚠ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <label>Event Name</label>
            <input type="text" name="event_name" placeholder="e.g., PFT Round 1" required>
            
            <label for="description">Description (optional)</label>
            <textarea id="description" name="description" rows="4" maxlength="3000" placeholder="Describe the event, award, recognition, or result. This description appears on the public event card."></textarea>

            <label>Event Type</label>
            <select id="event_type" name="event_type" required>
                <option value="">-- Select Event Type --</option>
                <?php foreach ($eventTypesRows as $type): ?>
                    <option value="<?php echo htmlspecialchars($type['event_type']); ?>">
                        <?php echo htmlspecialchars($type['display_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <label style="margin-top: 25px;">Display Scores (not OPA points)</label>
            <div class="score-grid">
                <?php foreach ($squadrons as $s): ?>
                <div class="score-input">
                    <label>
                        <?php if (!empty($s['icon_filename'])): ?>
                        <img src="<?php echo htmlspecialchars(iconUrl($s['icon_filename'])); ?>" class="icon">
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
            <a href="admin-scores.php">Manage live event results</a>
            &nbsp;·&nbsp;
            <a href="admin-panel.php">← Back to Admin</a>
        </div>
    </div>
</div>
</body>
</html>
