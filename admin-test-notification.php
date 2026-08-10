<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/notifications-helper.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

// AJAX endpoint: "Test Connection" — hits push-service.php directly with a
// dummy payload against the first available subscription, bypassing the
// score-notification flow entirely so we can isolate delivery problems.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'test_connection') {
    header('Content-Type: application/json');
    require __DIR__ . '/push-service.php';

    $subs = loadSubscriptions();
    $subList = $subs['subscriptions'] ?? [];

    if (empty($subList)) {
        echo json_encode([
            'success' => false,
            'message' => 'No subscribers to test against. Ask a user to enable notifications first.'
        ]);
        exit;
    }

    $testNotifications = array_map(function ($sub) {
        return [
            'endpoint' => $sub['endpoint'],
            'auth' => $sub['auth'],
            'p256dh' => $sub['p256dh'],
            'payload' => [
                'title' => 'Test Connection',
                'body' => 'This is a test push from the admin panel. If you see this, delivery works!',
                'icon' => '/pwa-icon.php?size=192',
                'badge' => '/pwa-icon.php?size=192',
                'tag' => 'admin-test-connection-' . time(),
                'data' => [
                    'type' => 'test_connection',
                    'squadron_id' => 0,
                    'url' => '/index.php'
                ]
            ]
        ];
    }, $subList);

    $sendResult = sendPushNotifications($testNotifications);

    echo json_encode([
        'success' => true,
        'sent' => $sendResult['sent'],
        'failed' => $sendResult['failed'],
        'results' => $sendResult['results'] ?? []
    ]);
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
    $sendResult = ['sent' => 0, 'failed' => 0, 'results' => []];
    if (!empty($allNotifications)) {
        $sendResult = sendPushNotifications($allNotifications);
        error_log('Admin test sent: ' . $sendResult['sent'] . ' success, ' . $sendResult['failed'] . ' failed');
    }
    
    $result = [
        'target_squadrons' => count($targetSquadrons),
        'matched_subscribers' => count($allNotifications),
        'notifications' => $allNotifications,
        'message' => $message,
        'sent' => $sendResult['sent'],
        'failed' => $sendResult['failed'],
        'delivery_results' => $sendResult['results'] ?? [],
    ];
}

$subscriptions = loadSubscriptions();
$subscriberList = $subscriptions['subscriptions'] ?? [];
$subscriberCount = count($subscriberList);

// Build a lightweight preview list (endpoint hash + squadron prefs) for the
// admin panel so admins can see who is actually subscribed before sending.
$subscriberPreview = array_map(function ($sub) use ($squadrons) {
    $squadronIds = $sub['squadrons'] ?? [];

    $names = empty($squadronIds)
        ? ['All squadrons']
        : array_map(function ($sid) use ($squadrons) {
            foreach ($squadrons as $sq) {
                if ((int) $sq['id'] === (int) $sid) {
                    return $sq['name'];
                }
            }
            return "Squadron #$sid";
        }, $squadronIds);

    return [
        'endpoint_hash' => substr($sub['endpoint'] ?? '', 0, 20),
        'squadrons' => $names,
        'last_active' => $sub['last_active'] ?? $sub['subscribed_at'] ?? 'unknown',
    ];
}, $subscriberList);
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
    .status-banner { padding: 10px 12px; border-radius: 4px; margin-bottom: 12px; font-size: 0.9em; }
    .status-banner.ok { background: #d4edda; color: #155724; }
    .status-banner.warn { background: #fff3cd; color: #856404; }
    .status-banner.bad { background: #f8d7da; color: #721c24; }
    .subscriber-list { max-height: 180px; overflow-y: auto; border: 1px solid #eee; border-radius: 4px; padding: 8px; margin: 10px 0; font-size: 0.85em; }
    .subscriber-list .row { padding: 4px 0; border-bottom: 1px solid #f0f0f0; }
    .subscriber-list .row:last-child { border-bottom: none; }
    .secondary-btn { background: #555; margin-top: 10px; }
    .secondary-btn:hover { background: #333; }
    .delivery-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.85em; }
    .delivery-table th, .delivery-table td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
    .delivery-table td.sent { color: #155724; font-weight: bold; }
    .delivery-table td.failed { color: #721c24; font-weight: bold; }
    #test-connection-result { margin-top: 10px; font-size: 0.85em; }
</style>
</head>
<body>
    <div class="panel">
        <h1>Send Notifications</h1>
        <p class="info"><?php echo $subscriberCount; ?> device(s) subscribed • Push notifications <?php echo $subscriberCount > 0 ? '✓ Ready' : '⚠ No subscribers yet'; ?></p>

        <div id="permission-banner" class="status-banner warn" style="display:none;"></div>

        <?php if (!empty($subscriberPreview)): ?>
            <details>
                <summary>Subscriber preview (<?php echo count($subscriberPreview); ?>)</summary>
                <div class="subscriber-list">
                    <?php foreach ($subscriberPreview as $s): ?>
                        <div class="row">
                            <code><?php echo htmlspecialchars($s['endpoint_hash']); ?>...</code>
                            — <?php echo htmlspecialchars(implode(', ', $s['squadrons'])); ?>
                            <span style="color:#999;">(last active: <?php echo htmlspecialchars($s['last_active']); ?>)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>

        <button type="button" class="secondary-btn" onclick="testConnection()">Test Connection</button>
        <div id="test-connection-result"></div>

        <?php if ($result !== null): ?>
            <div class="result">
                <strong>Targets:</strong> <?php echo $result['target_squadrons']; ?> squadron(s)<br>
                <strong>Matched:</strong> <?php echo $result['matched_subscribers']; ?> subscriber(s)<br>
                <strong>Delivery:</strong> <?php echo $result['sent']; ?> sent, <?php echo $result['failed']; ?> failed
                <?php if (!empty($result['message'])): ?>
                    <br><strong>Message:</strong> <?php echo htmlspecialchars($result['message']); ?>
                <?php endif; ?>
                <?php if (!empty($result['delivery_results'])): ?>
                    <table class="delivery-table">
                        <thead>
                            <tr><th>Endpoint</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['delivery_results'] as $dr): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($dr['endpoint']); ?>...</code></td>
                                    <td class="<?php echo htmlspecialchars($dr['status']); ?>"><?php echo htmlspecialchars(ucfirst($dr['status'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <?php if ($result['matched_subscribers'] > 0): ?>
                    <details style="margin-top:12px;">
                        <summary>View notification details</summary>
                        <pre><?php echo htmlspecialchars(json_encode($result['notifications'], JSON_PRETTY_PRINT)); ?></pre>
                    </details>
                    <p style="font-size:0.85em; color:#666;"><em>Note: Delivery is via the standard Web Push Protocol (RFC 8188/8292). If failures persist, check the Railway logs (search for "[push]") for per-endpoint HTTP response details.</em></p>
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
            &nbsp;|&nbsp;
            <a href="notification-preferences.php">Check notification permissions</a>
        </div>
        <p style="text-align:center; font-size:0.8em; color:#999; margin-top:10px;">
            Delivery issues? Check the Railway deployment logs and search for "[push]" entries for
            per-endpoint HTTP response codes and errors.
        </p>
    </div>

    <script>
        // Live push permission status indicator. This checks the *browser
        // running the admin panel*, which is informative for the admin's own
        // device but does not reflect subscriber permissions — subscriber
        // permission state can only be inferred indirectly (via whether a
        // subscription exists at all, since browsers won't create a push
        // subscription without granted permission).
        function updatePermissionBanner() {
            const banner = document.getElementById('permission-banner');
            const subscriberCount = <?php echo (int) $subscriberCount; ?>;

            if (!('Notification' in window)) {
                banner.style.display = 'block';
                banner.className = 'status-banner warn';
                banner.textContent = 'This browser does not support the Notifications API.';
                return;
            }

            const permission = Notification.permission;

            if (subscriberCount === 0) {
                banner.style.display = 'block';
                banner.className = 'status-banner bad';
                banner.innerHTML = '⚠ No subscribers with an active push subscription. ' +
                    'Notifications cannot be delivered until at least one user enables them on the ' +
                    '<a href="notification-preferences.php">notification preferences page</a>.';
                return;
            }

            if (permission === 'granted') {
                banner.style.display = 'block';
                banner.className = 'status-banner ok';
                banner.textContent = '✓ Push permission granted on this device. ' + subscriberCount + ' subscriber(s) registered.';
            } else if (permission === 'denied') {
                banner.style.display = 'block';
                banner.className = 'status-banner bad';
                banner.innerHTML = '✗ Push permission is denied on this device. This does not block sending to ' +
                    'other subscribers, but you will not receive notifications here. ' +
                    '<a href="notification-preferences.php">Fix permissions</a>.';
            } else {
                banner.style.display = 'block';
                banner.className = 'status-banner warn';
                banner.innerHTML = '⚠ Push permission is "' + permission + '" on this device. ' +
                    '<a href="notification-preferences.php">Grant permission</a> to receive test notifications here.';
            }
        }

        async function testConnection() {
            const resultEl = document.getElementById('test-connection-result');
            resultEl.textContent = 'Sending test push...';

            try {
                const resp = await fetch('admin-test-notification.php?ajax=test_connection');
                const data = await resp.json();

                if (!data.success) {
                    resultEl.innerHTML = '<span style="color:#721c24;">✗ ' + (data.message || 'Test failed') + '</span>';
                    return;
                }

                let html = '<strong>Test Connection result:</strong> ' + data.sent + ' sent, ' + data.failed + ' failed<br>';

                if (Array.isArray(data.results) && data.results.length > 0) {
                    html += '<ul style="margin:6px 0; padding-left:18px;">';
                    data.results.forEach(r => {
                        const color = r.status === 'sent' ? '#155724' : '#721c24';
                        html += '<li style="color:' + color + ';">' + r.endpoint + '... — ' + r.status + '</li>';
                    });
                    html += '</ul>';
                }

                html += '<em style="color:#666;">Check Railway logs (search "[push]") for full HTTP response details.</em>';

                resultEl.innerHTML = html;
            } catch (err) {
                resultEl.innerHTML = '<span style="color:#721c24;">✗ Test connection request failed: ' + err.message + '</span>';
            }
        }

        updatePermissionBanner();
    </script>
</body>
</html>
