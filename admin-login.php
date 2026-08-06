<?php
session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD');

    if ($adminPassword !== false && $adminPassword !== '' && $password === $adminPassword) {
        $_SESSION['admin'] = true;
        header('Location: admin-panel.php');
        exit;
    } else {
        $error = 'Incorrect password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Login - Squadron Tracker</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
    .login-box { background: #fff; padding: 30px; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.2); width: 300px; }
    h1 { font-size: 1.3em; color: #002147; text-align: center; }
    input[type="password"] { width: 100%; padding: 8px; margin: 10px 0; box-sizing: border-box; }
    button { width: 100%; padding: 8px; background: #002147; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    button:hover { background: #003366; }
    .error { color: #b00020; font-size: 0.9em; text-align: center; }
    .back-link { display: block; text-align: center; margin-top: 15px; font-size: 0.85em; }
    .back-link a { color: #002147; }
</style>
</head>
<body>
    <div class="login-box">
        <h1>Admin Login</h1>
        <?php if ($error): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <form method="post" action="admin-login.php">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autofocus>
            <button type="submit">Log In</button>
        </form>
        <div class="back-link">
            <a href="index.php">&larr; Back to home</a>
        </div>
    </div>
</body>
</html>
