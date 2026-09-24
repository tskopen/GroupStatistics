<?php
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$snapshot = recordLeaderboardSnapshot();
$db = getDb();

// Build a quick squadron_id => name map for display.
$stmt = $db->prepare('SELECT id, name FROM squadrons');
$stmt->execute();
$squadronMap = [];
foreach ($stmt->fetchAll() as $s) {
    $squadronMap[$s['id']] = $s['name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Snapshot Recorded - Squadron Tracker</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    .panel { max-width: 600px; margin: 40px auto; background: #fff; padding: 30px; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.15); }
    h1 { color: #002147; text-align: center; }
    .success { color: #1a7a1a; text-align: center; font-weight: bold; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background: #002147; color: #fff; padding: 10px; text-align: left; }
    td { padding: 10px; border-bottom: 1px solid #ddd; }
    .timestamp { text-align: center; color: #666; margin-bottom: 20px; }
    .nav-link { display: block; text-align: center; margin-top: 20px; font-size: 0.9em; }
    .nav-link a { color: #002147; }
</style>
</head>
<body>
    <div class="panel">
        <h1>📋 Leaderboard Snapshot Recorded</h1>
        <p class="success">Snapshot captured successfully.</p>
        <p class="timestamp">
            Timestamp: <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($snapshot['snapshot_date']))); ?><br>
            Squadrons captured: <?php echo count($snapshot['rankings']); ?>
        </p>
        <table>
            <tr>
                <th>Rank</th>
                <th>Squadron</th>
                <th>Total Points</th>
            </tr>
            <?php foreach ($snapshot['rankings'] as $row): ?>
            <tr>
                <td>#<?php echo (int) $row['rank']; ?></td>
                <td><?php echo htmlspecialchars($squadronMap[$row['squadron_id']] ?? $row['name']); ?></td>
                <td><?php echo htmlspecialchars((string) $row['total']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <div class="nav-link">
            <a href="admin-panel.php">&larr; Back to Admin Panel</a>
        </div>
    </div>
</body>
</html>
