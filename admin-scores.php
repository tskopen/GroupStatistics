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
$stmt = $db->prepare("SELECT * FROM squadrons ORDER BY id");
$stmt->execute();
$squadrons = $stmt->fetchAll();

// Fetch event types from database
$stmt = $db->prepare("SELECT event_type, display_name, emoji FROM event_type_config WHERE event_type NOT IN ('bracket', 'cadet_of_month') ORDER BY display_name ASC");
$stmt->execute();
$eventTypes = $stmt->fetchAll();

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add_event';

    if ($action === 'delete_event') {
        $eventId = (int) ($_POST['event_id'] ?? 0);

        if (!$eventId) {
            $error = 'Invalid event ID.';
        } else {
            try {
                $db->beginTransaction();

                // Get the event first so we can confirm the delete
                $stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
                $stmt->execute([$eventId]);
                $event = $stmt->fetch();

                if (!$event) {
                    throw new Exception('Event not found.');
                }

                // Bracket and Cadet of the Month entries are maintained by their own
                // admin workflows. Deleting them here would break their source records.
                $managedStmt = $db->prepare("SELECT
                    EXISTS(SELECT 1 FROM bracket_score_events WHERE event_id = ?) AS bracket_managed,
                    EXISTS(SELECT 1 FROM cadet_of_month_awards WHERE event_id = ?) AS cotm_managed");
                $managedStmt->execute([$eventId, $eventId]);
                $managed = $managedStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                if (!empty($managed['bracket_managed']) || !empty($managed['cotm_managed'])) {
                    throw new Exception('This result is managed by the Bracket or Cadet of the Month page.');
                }

                // Delete the event from the live results board.
                $stmt = $db->prepare("DELETE FROM events WHERE id = ?");
                $stmt->execute([$eventId]);

                $db->commit();
                $success = true;
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Failed to delete event: ' . $e->getMessage();
            }
        }
    } else {
        $squadronId = isset($_POST['squadron_id']) ? (int) $_POST['squadron_id'] : 0;
        $eventType = normalizeEventType($_POST['event_type'] ?? '');
        $eventName = trim((string)($_POST['event_name'] ?? ''));
        $valueRaw = trim((string)($_POST['value'] ?? ''));
        $value = $valueRaw === '' ? false : filter_var($valueRaw, FILTER_VALIDATE_FLOAT);

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
        } elseif ($value === false || !is_finite((float)$value)) {
            $error = 'Enter a valid numeric score.';
        } elseif (strlen($eventName) > 160) {
            $error = 'Event titles must be 160 characters or fewer.';
        } else {
            try {
                $db->beginTransaction();

                // points_awarded must always be set explicitly (defaults to
                // the submitted value) so non-intramural events are never
                // silently excluded from getSquadronRankings().
                $pointsAwarded = $value;
                if ($pointsAwarded === null) {
                    error_log('[SCORE-DIAG] points_awarded was NULL for squadron ' . $squadronId . ', event_type ' . $eventType . ' - defaulting to 0');
                    $pointsAwarded = 0;
                }

                $eventTimestamp = date('c');
                $displayEventName = $eventName !== '' ? $eventName : ucfirst($eventType);
                $stmt = $db->prepare("
                    INSERT INTO events (squadron_id, event_type, event_name, value, points_awarded, timestamp)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $insertResult = $stmt->execute([
                    $squadronId,
                    $eventType,
                    $displayEventName,
                    $value,
                    $pointsAwarded,
                    $eventTimestamp
                ]);

                if (!$insertResult) {
                    error_log('[SCORE-DIAG] Event INSERT failed for squadron ' . $squadronId . ', event_type ' . $eventType . ': ' . json_encode($stmt->errorInfo()));
                    throw new Exception('Failed to insert event.');
                }

                $newEventId = $db->lastInsertId();

                // Confirm points_awarded was actually persisted before committing.
                $verifyStmt = $db->prepare('SELECT value, points_awarded FROM events WHERE id = ?');
                $verifyStmt->execute([$newEventId]);
                $verifyRow = $verifyStmt->fetch();

                if (
                    $verifyRow === false ||
                    $verifyRow['value'] === null ||
                    $verifyRow['points_awarded'] === null ||
                    (float) $verifyRow['value'] !== (float) $verifyRow['points_awarded']
                ) {
                    error_log('[SCORE-DIAG] points_awarded missing after insert for event id ' . $newEventId . ' (squadron ' . $squadronId . ', event_type ' . $eventType . ')');
                    throw new Exception('points_awarded was not persisted for the new event.');
                }

                $db->commit();

                // These legacy event entries feed the public event-results cards;
                // official OPA standings are managed separately.
                error_log('[EVENT-BOARD] Saved event #' . $newEventId . ' for squadron ' . $squadronId . '.');

                $success = true;

                $scoreData = [
                    'squadron_id' => $squadronId,
                    'event_type' => $eventType,
                    'value' => $value,
                    'timestamp' => $eventTimestamp,
                ];

                try {
                    require_once __DIR__ . '/notifications-helper.php';
                    require_once __DIR__ . '/push-service.php';
                    $notifications = sendNotificationForScore($scoreData);
                    if (!empty($notifications)) {
                        $result = sendPushNotifications($notifications);
                        error_log('Event-board notification delivery: ' . $result['sent'] . ' sent, ' . $result['failed'] . ' failed');
                    }
                } catch (Throwable $notificationError) {
                    // The result is already committed; notification trouble must not
                    // make an administrator accidentally submit the score twice.
                    error_log('[EVENT-BOARD] Notification error: ' . $notificationError->getMessage());
                }


            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('[SCORE-DIAG] Event insert transaction rolled back for squadron ' . $squadronId . ', event_type ' . $eventType . ': ' . $e->getMessage());
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Live Events Board | GroupStatistics</title>
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
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
<style>
:root{--primary-color:<?php echo htmlspecialchars($theme['primary_color']??'#002147',ENT_QUOTES,'UTF-8'); ?>;--secondary-color:<?php echo htmlspecialchars($theme['secondary_color']??'#003366',ENT_QUOTES,'UTF-8'); ?>;--accent-color:<?php echo htmlspecialchars($theme['accent_color']??'#667eea',ENT_QUOTES,'UTF-8'); ?>;--background-color:<?php echo htmlspecialchars($theme['background_color']??'#f4f4f4',ENT_QUOTES,'UTF-8'); ?>;--text-color:<?php echo htmlspecialchars($theme['text_color']??'#222222',ENT_QUOTES,'UTF-8'); ?>}
</style>
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
    <div class="panel">
        <h1>Manage Live Events Board</h1>
        <p style="text-align:center;color:#64748b;margin-top:-8px;">Add or remove event results shown in the public Results section. These entries do not change official OPA standings.</p>
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

        <?php
        // Fetch recent events
        $stmt = $db->prepare("
            SELECT e.id, e.squadron_id, e.event_type, e.event_name, e.value, e.points_awarded, e.timestamp,
                   s.name AS squadron_name, s.icon_filename,
                   EXISTS(SELECT 1 FROM bracket_score_events bse WHERE bse.event_id=e.id) AS bracket_managed,
                   EXISTS(SELECT 1 FROM cadet_of_month_awards cotm WHERE cotm.event_id=e.id) AS cotm_managed
            FROM events e
            LEFT JOIN squadrons s ON e.squadron_id = s.id
            WHERE e.event_type NOT IN ('bracket', 'cadet_of_month')
               OR EXISTS(SELECT 1 FROM bracket_score_events bse WHERE bse.event_id=e.id)
               OR EXISTS(SELECT 1 FROM cadet_of_month_awards cotm WHERE cotm.event_id=e.id)
            ORDER BY e.timestamp DESC, e.id DESC
            LIMIT 50
        ");
        $stmt->execute();
        $recentEvents = $stmt->fetchAll();
        ?>

        <?php if (!empty($recentEvents)): ?>
            <h2 style="margin-top: 40px;">Recent Events</h2>
            <table style="width: 100%; border-collapse: collapse; background: #fff; margin-top: 20px;">
                <tr style="background: #002147; color: #fff;">
                    <th style="padding: 12px; text-align: left;">Squadron</th>
                    <th style="padding: 12px; text-align: left;">Event</th>
                    <th style="padding: 12px; text-align: center;">Score</th>
                    <th style="padding: 12px; text-align: left;">Date</th>
                    <th style="padding: 12px; text-align: center;">Action</th>
                </tr>
                <?php foreach ($recentEvents as $event): ?>
                <tr style="border-bottom: 1px solid #ddd;">
                    <td style="padding: 12px;"><?php echo htmlspecialchars($event['squadron_name'] ?? 'Unknown squadron'); ?></td>
                    <td style="padding: 12px;"><?php echo htmlspecialchars($event['event_name'] ?? $event['event_type']); ?></td>
                    <td style="padding: 12px; text-align: center;"><?php echo htmlspecialchars((string)$event['value']); ?></td>
                    <td style="padding: 12px;"><?php echo htmlspecialchars(date('M j, Y', strtotime($event['timestamp']))); ?></td>
                    <td style="padding: 12px; text-align: center;">
                        <?php if (!empty($event['bracket_managed']) || !empty($event['cotm_managed'])): ?>
                            <span title="Managed from its dedicated admin page">Managed separately</span>
                        <?php else: ?>
                            <form method="post" action="admin-scores.php" style="display: inline;">
                                <input type="hidden" name="action" value="delete_event">
                                <input type="hidden" name="event_id" value="<?php echo htmlspecialchars((string)$event['id']); ?>">
                                <button type="submit" style="background: #b00020; color: #fff; padding: 6px 10px; border: none; border-radius: 3px; cursor: pointer; font-size: 0.85em;" onclick="return confirm('Remove this result from the live events board? This cannot be undone.');">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <div class="nav-link">
            <a href="admin-bulk-scores.php">Post one event for multiple squadrons</a>
            &nbsp;·&nbsp;
            <a href="admin-panel.php">&larr; Back to Admin Panel</a>
        </div>
    </div>
</body>
</html>
