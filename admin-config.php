<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/db-migrate.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$success = '';
$error = '';

// Handle form POST (update event type config)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_event_type') {
        $eventType = $_POST['event_type'] ?? '';
        $displayName = $_POST['display_name'] ?? '';
        $description = $_POST['description'] ?? '';
        $pointsAwarded = isset($_POST['points_awarded']) ? (float)$_POST['points_awarded'] : 0;
        $emoji = $_POST['emoji'] ?? '';

        if (!$eventType || !$displayName) {
            $error = 'Event type and display name are required.';
        } else {
            $result = dbExecute("
                UPDATE event_type_config 
                SET display_name = ?, description = ?, points_awarded = ?, emoji = ?, updated_at = CURRENT_TIMESTAMP
                WHERE event_type = ?
            ", [$displayName, $description, $pointsAwarded, $emoji, $eventType]);

            if ($result !== false) {
                $success = "Event type '{$displayName}' updated successfully.";
            } else {
                $error = 'Failed to update event type.';
            }
        }
    } elseif ($action === 'create_event_type') {
        $eventType = $_POST['event_type'] ?? '';
        $displayName = $_POST['display_name'] ?? '';
        $description = $_POST['description'] ?? '';
        $pointsAwarded = isset($_POST['points_awarded']) ? (float)$_POST['points_awarded'] : 0;
        $emoji = $_POST['emoji'] ?? '';

        if (!$eventType || !$displayName) {
            $error = 'Event type and display name are required.';
        } elseif (strlen($eventType) < 2) {
            $error = 'Event type must be at least 2 characters.';
        } else {
            $result = dbExecute("
                INSERT INTO event_type_config (event_type, display_name, description, points_awarded, emoji)
                VALUES (?, ?, ?, ?, ?)
            ", [$eventType, $displayName, $description, $pointsAwarded, $emoji]);

            if ($result !== false) {
                $success = "New event type '{$displayName}' created successfully.";
            } else {
                $error = 'Event type may already exist or database error occurred.';
            }
        }
    }
}

// Fetch all event types
$eventTypes = dbFetchAll("SELECT * FROM event_type_config ORDER BY event_type ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Scoring Configuration - Squadron Tracker</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    .container { max-width: 1000px; margin: 0 auto; }
    h1 { color: #002147; text-align: center; }
    h2 { color: #002147; border-bottom: 2px solid #002147; padding-bottom: 8px; margin-top: 30px; }
    
    .panel { background: #fff; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.15); padding: 20px; margin-bottom: 20px; }
    
    label { display: block; margin-top: 12px; font-weight: bold; }
    input[type="text"], input[type="number"], textarea, select { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; border: 1px solid #ddd; border-radius: 4px; }
    textarea { resize: vertical; height: 80px; }
    
    button { margin-top: 18px; padding: 10px 16px; background: #002147; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    button:hover { background: #003366; }
    button.secondary { background: #666; }
    button.secondary:hover { background: #444; }
    
    .success { color: #1a7a1a; background: #e8f5e9; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
    .error { color: #b00020; background: #ffebee; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
    
    .event-type-row { background: #f9f9f9; padding: 15px; margin-bottom: 12px; border-left: 4px solid #002147; border-radius: 4px; }
    .event-type-row h3 { margin: 0 0 10px; color: #002147; }
    .event-type-row p { margin: 5px 0; color: #666; font-size: 0.9em; }
    .event-type-row .emoji { font-size: 1.5em; margin-right: 10px; }
    
    .row { display: flex; gap: 20px; }
    .col { flex: 1; }
    
    .nav-link { display: block; text-align: center; margin-top: 20px; font-size: 0.9em; }
    .nav-link a { color: #002147; text-decoration: none; }
    .nav-link a:hover { text-decoration: underline; }
    
    .toggle-form { cursor: pointer; color: #002147; text-decoration: underline; }
    .form-section { display: none; }
    .form-section.active { display: block; }
</style>
</head>
<body>
    <div class="container">
        <h1>⚙️ Scoring Configuration</h1>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="panel">
            <h2>Event Types & Point Values</h2>
            <p>Configure how many points each event type awards to squadrons. These values are used when entering scores.</p>
            
            <div style="margin-bottom: 20px;">
                <span class="toggle-form" onclick="toggleForm('create-form')">+ Create New Event Type</span>
            </div>
            
            <div id="create-form" class="form-section">
                <form method="post" action="admin-config.php" style="background: #e3f2fd; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <h3>Create New Event Type</h3>
                    <input type="hidden" name="action" value="create_event_type">
                    
                    <div class="row">
                        <div class="col">
                            <label>Event Type Code <small>(e.g., "intramural_basketball")</small></label>
                            <input type="text" name="event_type" required placeholder="e.g., intramural_basketball">
                        </div>
                        <div class="col">
                            <label>Display Name <small>(e.g., "Intramural Basketball")</small></label>
                            <input type="text" name="display_name" required placeholder="e.g., Intramural Basketball">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col">
                            <label>Points Awarded</label>
                            <input type="number" name="points_awarded" step="0.5" value="0" placeholder="0">
                        </div>
                        <div class="col">
                            <label>Emoji (optional)</label>
                            <input type="text" name="emoji" placeholder="🏀" maxlength="2">
                        </div>
                    </div>
                    
                    <label>Description (optional)</label>
                    <textarea name="description" placeholder="Brief description of this event type..."></textarea>
                    
                    <button type="submit">Create Event Type</button>
                </form>
            </div>
            
            <h3>Existing Event Types</h3>
            <?php if (empty($eventTypes)): ?>
                <p style="color: #999;">No event types configured yet.</p>
            <?php else: ?>
                <?php foreach ($eventTypes as $et): ?>
                <div class="event-type-row">
                    <h3>
                        <?php if (!empty($et['emoji'])): ?>
                            <span class="emoji"><?php echo htmlspecialchars($et['emoji']); ?></span>
                        <?php endif; ?>
                        <?php echo htmlspecialchars($et['display_name']); ?>
                    </h3>
                    <p><strong>Code:</strong> <code><?php echo htmlspecialchars($et['event_type']); ?></code></p>
                    <p><strong>Points Awarded:</strong> <strong style="color: #28a745;"><?php echo htmlspecialchars((string)$et['points_awarded']); ?></strong></p>
                    <?php if (!empty($et['description'])): ?>
                        <p><strong>Description:</strong> <?php echo htmlspecialchars($et['description']); ?></p>
                    <?php endif; ?>
                    
                    <form method="post" action="admin-config.php" style="margin-top: 12px;">
                        <input type="hidden" name="action" value="update_event_type">
                        <input type="hidden" name="event_type" value="<?php echo htmlspecialchars($et['event_type']); ?>">
                        
                        <div class="row">
                            <div class="col">
                                <label>Display Name</label>
                                <input type="text" name="display_name" value="<?php echo htmlspecialchars($et['display_name']); ?>" required>
                            </div>
                            <div class="col">
                                <label>Points Awarded</label>
                                <input type="number" name="points_awarded" step="0.5" value="<?php echo htmlspecialchars((string)$et['points_awarded']); ?>">
                            </div>
                            <div class="col">
                                <label>Emoji</label>
                                <input type="text" name="emoji" value="<?php echo htmlspecialchars($et['emoji'] ?? ''); ?>" maxlength="2">
                            </div>
                        </div>
                        
                        <label>Description</label>
                        <textarea name="description"><?php echo htmlspecialchars($et['description'] ?? ''); ?></textarea>
                        
                        <button type="submit">Update</button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="nav-link">
            <a href="admin-panel.php">&larr; Back to Admin Panel</a>
        </div>
    </div>
    
    <script>
        function toggleForm(formId) {
            const form = document.getElementById(formId);
            form.classList.toggle('active');
        }
    </script>
</body>
</html>
