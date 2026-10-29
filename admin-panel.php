<?php
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: admin-login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Admin Panel</title>
<style>
:root{--primary-color:<?php echo htmlspecialchars($theme['primary_color'] ?? '#002147', ENT_QUOTES, 'UTF-8'); ?>;--secondary-color:<?php echo htmlspecialchars($theme['secondary_color'] ?? '#003366', ENT_QUOTES, 'UTF-8'); ?>;--accent-color:<?php echo htmlspecialchars($theme['accent_color'] ?? '#667eea', ENT_QUOTES, 'UTF-8'); ?>;--background-color:<?php echo htmlspecialchars($theme['background_color'] ?? '#f4f4f4', ENT_QUOTES, 'UTF-8'); ?>;--text-color:<?php echo htmlspecialchars($theme['text_color'] ?? '#222222', ENT_QUOTES, 'UTF-8'); ?>}

    body { font-family: Arial; background: var(--background-color); margin: 0; padding: 20px; }
    .panel { max-width: 500px; margin: 60px auto; background: var(--background-color); padding: 30px; border-radius: 6px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
    h1 { color: var(--primary-color); }
    a.button { display: block; margin: 12px 0; padding: 12px; background: var(--primary-color); color: #fff; text-decoration: none; border-radius: 4px; font-weight: bold; }
    a.button:hover { background: var(--secondary-color); }
    .logout { margin-top: 30px; }
    .logout a { color: #b00020; text-decoration: none; font-size: 0.9em; }
</style>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
    <div class="panel">
        <h1>Admin Panel</h1>
        <a class="button" href="admin-scores.php">Enter Single Score</a>
        <a class="button" href="admin-bulk-scores.php">Enter Event (All Squadrons)</a>
        <a class="button" href="admin-brackets.php">🏆 Manage Brackets</a>
        <a class="button" href="admin-squadrons.php">Manage Squadrons</a>
        <a class="button" href="admin-directory.php">👥 Manage Meet the Group</a>
        <a class="button" href="admin-config.php">⚙️ Scoring Configuration</a>
        <a class="button" href="admin-intramural-sports.php">🏆 Intramural Sports</a>
        <a class="button" href="admin-intramural-games.php">🎮 Intramural Games</a>
        <a class="button" href="admin-cadet-of-month.php">⭐ Cadet of the Month</a>
        <a class="button" href="admin-announcements.php">📣 Manage Group Announcements</a>
        <a class="button" href="admin-awards.php">🏅 Manage Challenge Awards</a>
        <a class="button" href="admin-theme.php">Theme Settings</a>
        <a class="button" href="admin-record-snapshot.php">📋 Record Leaderboard Snapshot</a>
        <a class="button" href="admin-test-notification.php">Send Notifications</a>
        <a class="button" href="debug-vapid.php" style="background: #666;">Debug VAPID Key</a>
        <div class="logout">
            <a href="admin-panel.php?logout=1">Log Out</a>
        </div>
    </div>
</body>
</html>
