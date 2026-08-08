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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $squadronId = isset($_POST['squadron_id']) ? (int) $_POST['squadron_id'] : 0;
    $value = isset($_POST['value']) && $_POST['value'] !== '' ? (float) $_POST['value'] : 0;

    $notifications = sendNotificationForScore([
        'squadron_id' => $squadronId,
        'value' => $value,
    ]);

    $result = [
        'count' => is_array($notifications) ? count($notifications) : 0,
        'notifications' => $notifications ?: [],
    ];
}

$subscriptions = loadSubscriptions();
$subscriberCount = count($subscriptions['subscriptions'] ?? []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Send Test Notification</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    .panel { max-width: 560px; margin: 40px auto; background: #fff; padding: 30px; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.15); }
    h1 { color: #002147; text-align: center; }
    label { display: block; margin-top: 12px; font-weight: bold; }
    select, input[type="number"] { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; }
    button { margin-top: 18px; width: 100%; padding: 10px; background: #002147; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    button:hover { background: #003366; }
    .info { background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 4px; margin-bottom: 15px; text-align: center; }
    .result { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-top: 15px; }
    .nav-link { display: block; text-align: center; margin-top: 20px; font-size: 0.9em; }
    .nav-link a { color: #002147; }
    pre { white-space: pre-wrap; word-break: break-all; background: #f9f9f9; padding: 10px; border-radius: 4px; font-size: 0.8em; }
</style>
</head>
<body>
    <div class="panel">
        <h1>Send Test Notification</h1>
        <p class="info"><?php echo $subscriberCount; ?> device(s) currently subscribed to notifications.</p>

        <?php if ($result !== null): ?>
            <div class="result">
                Matched <?php echo $result['count']; ?> subscriber(s) for this squadron.
                <?php if ($result['count'] > 0): ?>
                    <pre><?php echo htmlspecialchars(json_encode($result['notifications'], JSON_PRETTY_PRINT)); ?></pre>
                    <p><em>Note: this demo builds the notification payloads but does not dispatch them to a push
                    service. Wire up a Web Push library (e.g. web-push) with real VAPID keys to deliver them.</em></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="admin-test-notification.php">
            <label for="squadron_id">Squadron</label>
            <select id="squadron_id" name="squadron_id" required>
                <option value="">-- Select Squadron --</option>
                <?php foreach ($squadrons as $squadron): ?>
                    <option value="<?php echo htmlspecialchars((string) $squadron['id']); ?>">
                        <?php echo htmlspecialchars($squadron['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="value">Score Value</label>
            <input type="number" id="value" name="value" step="any" value="10">

            <button type="submit">Send Test Notification</button>
        </form>
        <div class="nav-link">
            <a href="admin-panel.php">&larr; Back to Admin Panel</a>
        </div>
    </div>
</body>
</html>
