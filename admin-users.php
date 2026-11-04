<?php
session_start();
require __DIR__ . '/config.php';
if (empty($_SESSION['admin'])) { header('Location: admin-login.php'); exit; }
$db = getDb();
$error = '';
$success = '';
function adminUsersEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
            $error = 'Username must be 3–32 characters and use only letters, numbers, dots, underscores, or hyphens.';
        } elseif (strlen($password) < 12) {
            $error = 'Password must be at least 12 characters long.';
        } elseif (strlen($password) > 1024) {
            $error = 'Password is too long.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare('INSERT INTO admin_users(username,password_hash,created_at) VALUES(?,?,?)');
                $stmt->execute([$username, $hash, date('c')]);
                $success = 'Admin account created. Share the credentials securely with the intended admin.';
            } catch (PDOException $e) {
                $error = 'That username already exists or the account could not be created.';
            }
        }
    } elseif ($action === 'delete') {
        $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            $error = 'Invalid admin account selected.';
        } else {
            $stmt = $db->prepare('SELECT username FROM admin_users WHERE id=?');
            $stmt->execute([$id]); $target = $stmt->fetchColumn();
            if ($target === false) {
                $error = 'Admin account was not found.';
            } elseif ((string)$target === (string)($_SESSION['username'] ?? '')) {
                $error = 'You cannot delete the account you are currently signed in with.';
            } else {
                $db->prepare('DELETE FROM admin_users WHERE id=?')->execute([$id]);
                $success = 'Admin account deleted.';
            }
        }
    }
}
$admins = $db->query('SELECT id, username, created_at, password_hash FROM admin_users ORDER BY username COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Accounts</title>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
<style>
body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;color:#222}
.container{max-width:850px;margin:30px auto}
h1,h2{color:#002147}
.panel{background:#fff;padding:22px;border-radius:8px;box-shadow:0 2px 8px #0001;margin:18px 0}
label{display:block;font-weight:bold;margin:12px 0 5px}
input{box-sizing:border-box;width:100%;padding:10px;border:1px solid #bbb;border-radius:4px}
button,.back{display:inline-block;background:#002147;color:#fff;padding:10px 15px;border:0;border-radius:4px;text-decoration:none;cursor:pointer;margin-top:14px}
button.delete{background:#a32121;margin:0}
.notice{padding:12px;border-radius:5px;margin:12px 0}.success{background:#e8f5e9;color:#176b28}.error{background:#ffebee;color:#a32121}
.admin-row{display:flex;align-items:center;justify-content:space-between;gap:15px;border-bottom:1px solid #ddd;padding:12px 0}
.admin-row:last-child{border-bottom:0}.meta{font-size:.88em;color:#666}
@media(max-width:600px){.admin-row{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
<div class="container">
<h1>Manage Admin Accounts</h1>
<p>Create individual admin logins with specific usernames and passwords.</p>
<?php if($success): ?><div class="notice success"><?php echo adminUsersEscape($success); ?></div><?php endif; ?>
<?php if($error): ?><div class="notice error"><?php echo adminUsersEscape($error); ?></div><?php endif; ?>
<section class="panel">
<h2>Add Admin</h2>
<form method="post" action="admin-users.php">
<input type="hidden" name="action" value="create">
<label for="username">Username</label>
<input id="username" name="username" type="text" minlength="3" maxlength="32" pattern="[A-Za-z0-9._-]+" autocomplete="off" required>
<label for="password">Password (minimum 12 characters)</label>
<input id="password" name="password" type="password" minlength="12" maxlength="1024" autocomplete="new-password" required>
<button type="submit">Create Admin Account</button>
</form>
</section>
<section class="panel">
<h2>Existing Admin Accounts</h2>
<?php if(!$admins): ?><p>No database-managed admin accounts yet. Environment-configured admins will be listed after their first login.</p><?php endif; ?>
<?php foreach($admins as $admin): ?>
<div class="admin-row">
<div><strong><?php echo adminUsersEscape($admin['username']); ?></strong><div class="meta"><?php echo !empty($admin['password_hash'])?'Individual password':'Environment-configured account'; ?> · Created <?php echo adminUsersEscape($admin['created_at'] ?? ''); ?></div></div>
<?php if((string)$admin['username'] !== (string)($_SESSION['username'] ?? '')): ?>
<form method="post" action="admin-users.php" onsubmit="return confirm('Delete this admin account?');">
<input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?php echo (int)$admin['id']; ?>">
<button class="delete" type="submit">Delete</button>
</form>
<?php else: ?><span class="meta">Currently signed in</span><?php endif; ?>
</div>
<?php endforeach; ?>
</section>
<a class="back" href="admin-panel.php">← Back to Admin Panel</a>
</div>
</body>
</html>
