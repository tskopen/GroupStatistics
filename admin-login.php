<?php
session_start();
require __DIR__ . '/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '') {
        // Backward compatibility: if no username field was submitted
        // (older cached page, or a single-admin deployment), default
        // to the first configured admin username.
        $allowed = getAdminUsernames();
        $username = $allowed[0] ?? 'admin';
    }

    if (verifyAdminCredentials($username, $password)) {
        // Prevent session fixation by regenerating the session ID on
        // every successful login.
        session_regenerate_id(true);

        $_SESSION['admin'] = true;
        $_SESSION['username'] = $username;

        recordAdminUser($username);

        header('Location: admin-panel.php');
        exit;
    } else {
        $error = 'Incorrect username or password. Please try again.';
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
    input[type="text"], input[type="password"] { width: 100%; padding: 8px; margin: 10px 0; box-sizing: border-box; }
    label { font-size: 0.9em; color: #333; }
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
            <label for="username">Username</label>
            <input type="text" id="username" name="username" autocomplete="username" autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">

            <button type="submit">Log In</button>
        </form>
        <div class="back-link">
            <a href="index.php">&larr; Back to home</a>
        </div>
    </div>
</body>
</html>
