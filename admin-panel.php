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
<title>Admin Panel</title>
<style>
    body { font-family: Arial; background: #f4f4f4; margin: 0; padding: 20px; }
    .panel { max-width: 500px; margin: 60px auto; background: #fff; padding: 30px; border-radius: 6px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
    h1 { color: #002147; }
    a.button { display: block; margin: 12px 0; padding: 12px; background: #002147; color: #fff; text-decoration: none; border-radius: 4px; font-weight: bold; }
    a.button:hover { background: #003366; }
    .logout { margin-top: 30px; }
    .logout a { color: #b00020; text-decoration: none; font-size: 0.9em; }
</style>
</head>
<body>
    <div class="panel">
        <h1>Admin Panel</h1>
        <a class="button" href="admin-scores.php">Enter Single Score</a>
        <a class="button" href="admin-bulk-scores.php">Enter Event (All Squadrons)</a>
        <a class="button" href="admin-bracket.php">Manage Bracket</a>
        <a class="button" href="admin-squadrons.php">Manage Squadrons</a>
        <div class="logout">
            <a href="admin-panel.php?logout=1">Log Out</a>
        </div>
    </div>
</body>
</html>
