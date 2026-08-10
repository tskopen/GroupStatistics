<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/notifications-helper.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$squadrons = readJson(DATA_DIR . '/squadrons.json');
$result = null;
$notificationLog = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $squadronId = isset($_POST['squadron_id']) && $_POST['squadron_id'] !== '' ? (int) $_POST['squadron_id'] : null;
    $value = isset($_POST['value']) && $_POST['value'] !== '' ? (float) $_POST['value'] : 0;
    $message = trim($_POST['message'] ?? '');
    
    // Support both single squadron and "all squadrons"
    $targetSquadrons = [];
    if ($squadronId === 0 || $squadronId === null) {
        // All squadrons
        $targetSquadrons = array_column($squadrons, 'id');
    } else {
        $targetSquadrons = [$squadronId];
    }
    
    $allNotifications = [];
    foreach ($targetSquadrons as $sid) {
        $scoreData = [
            'squadron_id' => $sid,
            'value' => $value,
        ];
        $notifications = sendNotificationForScore($scoreData, $message !== '' ? $message : null);
        $allNotifications = array_merge($allNotifications, $notifications);
    }
    
    // Actually send the notifications via Web Push Protocol
    require __DIR__ . '/push-service.php';
    if (!empty($allNotifications)) {
        $sendResult = sendPushNotifications($allNotifications);
        error_log('Admin test sent: ' . $sendResult['sent'] . ' success, ' . $sendResult['failed'] . ' failed');
    }
    
    $result = [
        'target_squadrons' => count($targetSquadrons),
        'matched_subscribers' => count($allNotifications),
        'notifications' => $allNotifications,
        'message' => $message,
    ];
}

$subscriptions = loadSubscriptions();
$subscriberCount = count($subscriptions['subscriptions'] ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Send Notifications</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    .panel { max-width: 600px; margin: 40px auto; background: #fff; padding: 30px; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.15); }
    h1 { color: #002147; text-align: center; }
    label { display: block; margin-top: 12px; font-weight: bold; }
    select, input[type="number"], textarea { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; }
    textarea { resize: vertical; min-height: 60px; }
    button { margin-top: 18px; width: 100%; padding: 10px; background: #002147; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
    button:hover { background: #003366; }
    .info { background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 4px; margin-bottom: 15px; text-align: center; }
    .result { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-top: 15px; }
    .result.full { background: #f8f9fa; color: #333; }
    .nav-link { display: block; text-align: center; margin-top: 20px; font-size: 0.9em; }
    .nav-link a { color: #002147; }
    pre { white-space: pre-wrap; word-break: break-all; background: #f9f9f9; padding: 10px; border-radius: 4px; font-size: 0.75em; max-height: 200px; overflow-y: auto; }
    .form-group { margin: 15px 0; }
    .half-width { width: 48%; display: inline-block; margin-right: 2%; }
    .half-width:nth-child(even) { margin-right: 0; }
    @media (max-width: 600px) { .half-width { width: 100%; display: block; margin-right: 0; } }
</style>
</head>
<body>
    <div class="panel">
        <h1>Send Notifications</h1>
        <p class="info"><?php echo $subscriberCount; ?> device(s) subscribed • Push notifications <?php echo $subscriberCount > 0 ? '✓ Ready' : '⚠ No subscribers yet'; ?></p>

        <?php if ($result !== null): ?>
            <div class="result">
                <strong>Targets:</strong> <?php echo $result['target_squadrons']; ?> squadron(s)<br>
                <strong>Matched:</strong> <?php echo $result['matched_subscribers']; ?> subscriber(s)
                <?php if (!empty($result['message'])): ?>
                    <br><strong>Message:</strong> <?php echo htmlspecialchars($result['message']); ?>
                <?php endif; ?>
                <?php if ($result['matched_subscribers'] > 0): ?>
                    <details style="margin-top:12px;">
                        <summary>View notification details</summary>
                        <pre><?php echo htmlspecialchars(json_encode($result['notifications'], JSON_PRETTY_PRINT)); ?></pre>
                    </details>
                    <p style="font-size:0.85em; color:#666;"><em>Note: Attempting to send via Firebase Cloud Messaging and Windows Notification Service. Check Railway logs for delivery status.</em></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="admin-test-notification.php">
            <div class="form-group">
                <label for="squadron_id">Target Squadron</label>
                <select id="squadron_id" name="squadron_id">
                    <option value="0">📢 All Squadrons</option>
                    <?php foreach ($squadrons as $squadron): ?>
                        <option value="<?php echo htmlspecialchars((string) $squadron['id']); ?>">
                            <?php echo htmlspecialchars($squadron['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="value">Score Value (optional)</label>
                <input type="number" id="value" name="value" step="any" placeholder="e.g. 10" value="">
            </div>

            <div class="form-group">
                <label for="message">Custom Message (optional)</label>
                <textarea id="message" name="message" placeholder="e.g. 'Amazing performance in the Dodgeball competition!' Leave blank for default."></textarea>
            </div>

            <button type="submit">Send Notification</button>
        </form>

        <div class="nav-link">
            <a href="admin-panel.php">&larr; Back to Admin Panel</a>
        </div>
    </div>
</body>
</html>
