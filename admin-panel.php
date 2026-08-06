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
<title>Admin Panel - Squadron Tracker</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    .panel { max-width: 500px; margin: 60px auto; background: #fff; padding: 30px; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.15); text-align: center; }
    h1 { color: #002147; }
    a.button { display: block; margin: 12px 0; padding: 12px; background: #002147; color: #fff; text-decoration: none; border-radius: 4px; }
    a.button:hover { background: #003366; }
    .logout { margin-top: 20px; }
    .logout a { color: #b00020; text-decoration: none; font-size: 0.9em; }
</style>
</head>
<body>
    <div class="panel">
        <h1>Admin Panel</h1>
        <a class="button" href="admin-scores.php">Enter Scores</a>
        <a class="button" href="admin-squadrons.php">Manage Squadrons</a>
        <div class="logout">
            <a href="admin-panel.php?logout=1">Log Out</a>
        </div>
    </div>
</body>
</html>
