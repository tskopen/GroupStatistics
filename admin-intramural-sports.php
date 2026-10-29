<?php
session_start();
require __DIR__ . '/config.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$db = getDb();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create_sport') {
        $sportName = trim($_POST['sport_name'] ?? '');
        $emoji = trim($_POST['emoji'] ?? '');
        $pointsWin = $_POST['points_win'] ?? 0;
        $pointsLoss = $_POST['points_loss'] ?? 0;
        $pointsBonusPerfect = $_POST['points_bonus_perfect'] ?? 0;

        if (!$sportName) {
            $error = 'Sport name is required.';
        } else {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("INSERT INTO intramural_sports (sport_name, emoji, points_win, points_loss, points_bonus_perfect, created_at) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $sportName,
                    $emoji,
                    (float) $pointsWin,
                    (float) $pointsLoss,
                    (float) $pointsBonusPerfect,
                    date('c'),
                ]);
                $db->commit();
                $success = "Sport '{$sportName}' created successfully.";
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Failed to create sport: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_sport') {
        $sportId = (int) ($_POST['sport_id'] ?? 0);
        $sportName = trim($_POST['sport_name'] ?? '');
        $emoji = trim($_POST['emoji'] ?? '');
        $pointsWin = $_POST['points_win'] ?? 0;
        $pointsLoss = $_POST['points_loss'] ?? 0;
        $pointsBonusPerfect = $_POST['points_bonus_perfect'] ?? 0;

        if (!$sportId || !$sportName) {
            $error = 'Sport name is required.';
        } else {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("UPDATE intramural_sports SET sport_name = ?, emoji = ?, points_win = ?, points_loss = ?, points_bonus_perfect = ? WHERE id = ?");
                $stmt->execute([
                    $sportName,
                    $emoji,
                    (float) $pointsWin,
                    (float) $pointsLoss,
                    (float) $pointsBonusPerfect,
                    $sportId,
                ]);
                $db->commit();
                $success = "Sport '{$sportName}' updated successfully.";
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'Failed to update sport: ' . $e->getMessage();
            }
        }
    }
}

$stmt = $db->prepare("SELECT * FROM intramural_sports ORDER BY sport_name ASC");
$stmt->execute();
$sports = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Intramural Sports - Squadron Tracker</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    .container { max-width: 1000px; margin: 0 auto; }
    h1 { color: #002147; text-align: center; }
    h2 { color: #002147; border-bottom: 2px solid #002147; padding-bottom: 8px; margin-top: 30px; }
    .panel { background: #fff; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.15); padding: 20px; margin-bottom: 20px; }
    label { display: block; margin-top: 12px; font-weight: bold; }
    input[type="text"], input[type="number"], textarea { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; border: 1px solid #ddd; border-radius: 4px; }
    textarea { resize: vertical; height: 80px; }
    button { margin-top: 18px; padding: 10px 16px; background: #002147; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
    button:hover { background: #003366; }
    .success { color: #1a7a1a; background: #e8f5e9; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
    .error { color: #b00020; background: #ffebee; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
    .sport-row { background: #f9f9f9; padding: 15px; margin-bottom: 12px; border-left: 4px solid #002147; border-radius: 4px; }
    .sport-row h3 { margin: 0 0 10px; color: #002147; }
    .sport-row p { margin: 5px 0; color: #666; font-size: 0.9em; }
    .sport-row .emoji { font-size: 1.5em; margin-right: 10px; }
    .row { display: flex; gap: 20px; }
    .col { flex: 1; }
    .nav-link { display: block; text-align: center; margin-top: 20px; font-size: 0.9em; }
    .nav-link a { color: #002147; text-decoration: none; }
    .toggle-form { cursor: pointer; color: #002147; text-decoration: underline; font-weight: bold; }
    .form-section { display: none; }
    .form-section.active { display: block; }
</style>
</head>
<body>
    <div class="container">
        <h1>🏆 Intramural Sports</h1>
        <?php if ($success): ?><div class="success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <div class="panel">
            <h2>Sports</h2>
            <p>Configure intramural sports and their point rules.</p>
            <div style="margin-bottom: 20px;">
                <span class="toggle-form" onclick="toggleForm('create-form')">+ Create New Sport</span>
            </div>
            <div id="create-form" class="form-section">
                <form method="post" action="admin-intramural-sports.php" style="background: #e3f2fd; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <h3>Create New Sport</h3>
                    <input type="hidden" name="action" value="create_sport">
                    <div class="row">
                        <div class="col">
                            <label>Sport Name <small>(e.g., "Basketball")</small></label>
                            <input type="text" name="sport_name" required placeholder="e.g., Basketball">
                        </div>
                        <div class="col">
                            <label>Emoji (optional)</label>
                            <input type="text" name="emoji" placeholder="🏀" maxlength="2">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <label>Points for Win</label>
                            <input type="number" step="any" name="points_win" value="0">
                        </div>
                        <div class="col">
                            <label>Points for Loss</label>
                            <input type="number" step="any" name="points_loss" value="0">
                        </div>
                        <div class="col">
                            <label>Bonus Points (Perfect Record)</label>
                            <input type="number" step="any" name="points_bonus_perfect" value="0">
                        </div>
                    </div>
                    <button type="submit">Create Sport</button>
                </form>
            </div>
            <h3>Existing Sports</h3>
            <?php if (empty($sports)): ?>
                <p>No sports have been created yet.</p>
            <?php endif; ?>
            <?php foreach ($sports as $sport): ?>
            <div class="sport-row">
                <h3><?php if (!empty($sport['emoji'])): ?><span class="emoji"><?php echo htmlspecialchars($sport['emoji']); ?></span><?php endif; ?><?php echo htmlspecialchars($sport['sport_name']); ?></h3>
                <p><strong>Win:</strong> <?php echo htmlspecialchars((string) $sport['points_win']); ?> pts &nbsp;|&nbsp; <strong>Loss:</strong> <?php echo htmlspecialchars((string) $sport['points_loss']); ?> pts &nbsp;|&nbsp; <strong>Perfect Record Bonus:</strong> <?php echo htmlspecialchars((string) $sport['points_bonus_perfect']); ?> pts</p>
                <form method="post" action="admin-intramural-sports.php" style="margin-top: 12px;">
                    <input type="hidden" name="action" value="update_sport">
                    <input type="hidden" name="sport_id" value="<?php echo htmlspecialchars((string) $sport['id']); ?>">
                    <div class="row">
                        <div class="col">
                            <label>Sport Name</label>
                            <input type="text" name="sport_name" value="<?php echo htmlspecialchars($sport['sport_name']); ?>" required>
                        </div>
                        <div class="col">
                            <label>Emoji</label>
                            <input type="text" name="emoji" value="<?php echo htmlspecialchars($sport['emoji'] ?? ''); ?>" maxlength="2">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col">
                            <label>Points for Win</label>
                            <input type="number" step="any" name="points_win" value="<?php echo htmlspecialchars((string) $sport['points_win']); ?>">
                        </div>
                        <div class="col">
                            <label>Points for Loss</label>
                            <input type="number" step="any" name="points_loss" value="<?php echo htmlspecialchars((string) $sport['points_loss']); ?>">
                        </div>
                        <div class="col">
                            <label>Bonus Points (Perfect Record)</label>
                            <input type="number" step="any" name="points_bonus_perfect" value="<?php echo htmlspecialchars((string) $sport['points_bonus_perfect']); ?>">
                        </div>
                    </div>
                    <button type="submit">Update</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="nav-link">
            <a href="admin-panel.php">&larr; Back to Admin Panel</a>
        </div>
    </div>
    <script>function toggleForm(formId) { document.getElementById(formId).classList.toggle('active'); }</script>
</body>
</html>
