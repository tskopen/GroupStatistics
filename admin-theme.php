<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$squadrons = readJson(DATA_DIR . '/squadrons.json');
$squadronMap = [];
foreach ($squadrons as $s) {
    $squadronMap[$s['id']] = $s;
}

$success = '';
$theme = loadTheme();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset'])) {
        $theme = getDefaultTheme();
        saveTheme($theme);
        $success = 'Theme reset to defaults.';
    } else {
        $theme = [
            'selected_squadron_id' => isset($_POST['selected_squadron_id']) && $_POST['selected_squadron_id'] !== ''
                ? (int) $_POST['selected_squadron_id']
                : null,
            'primary_color' => $_POST['primary_color'] ?? $theme['primary_color'],
            'secondary_color' => $_POST['secondary_color'] ?? $theme['secondary_color'],
            'accent_color' => $_POST['accent_color'] ?? $theme['accent_color'],
            'background_color' => $_POST['background_color'] ?? $theme['background_color'],
            'text_color' => $_POST['text_color'] ?? $theme['text_color'],
        ];
        saveTheme($theme);
        $success = 'Theme saved successfully.';
    }
}

$selectedSquadron = ($theme['selected_squadron_id'] && isset($squadronMap[$theme['selected_squadron_id']]))
    ? $squadronMap[$theme['selected_squadron_id']]
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Theme Settings - Squadron Tracker</title>
<style>
    :root {
        --primary-color: <?php echo htmlspecialchars($theme['primary_color']); ?>;
        --secondary-color: <?php echo htmlspecialchars($theme['secondary_color']); ?>;
        --accent-color: <?php echo htmlspecialchars($theme['accent_color']); ?>;
        --background-color: <?php echo htmlspecialchars($theme['background_color']); ?>;
        --text-color: <?php echo htmlspecialchars($theme['text_color']); ?>;
    }
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    .container { max-width: 700px; margin: 0 auto; }
    h1 { color: var(--primary-color); text-align: center; }
    .card { background: #fff; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.15); padding: 20px; margin-bottom: 20px; }
    label { display: block; font-weight: bold; margin-bottom: 6px; margin-top: 14px; }
    select { width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #ccc; }
    .squadron-info { display: flex; align-items: center; gap: 12px; margin-top: 12px; }
    .squadron-info img { width: 50px; height: 50px; border-radius: 4px; object-fit: cover; }
    .squadron-info .placeholder { width: 50px; height: 50px; border-radius: 4px; background: #ccc; }
    .squadron-info p { margin: 0; color: #555; font-size: 0.9em; }
    .color-row { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
    .color-row label { flex: 1; margin: 0; font-weight: normal; }
    input[type="color"] { width: 60px; height: 36px; border: none; padding: 0; cursor: pointer; }
    .preview { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
    .swatch { flex: 1; min-width: 100px; padding: 16px 10px; border-radius: 4px; text-align: center; font-weight: bold; font-size: 0.85em; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.4); }
    .actions { display: flex; gap: 10px; margin-top: 20px; }
    button { padding: 10px 16px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
    .save-btn { background: var(--primary-color); color: #fff; flex: 1; }
    .save-btn:hover { opacity: 0.9; }
    .reset-btn { background: #b00020; color: #fff; }
    .reset-btn:hover { opacity: 0.9; }
    .success { color: #1a7a1a; text-align: center; font-weight: bold; }
    .nav-link { display: block; text-align: center; margin-top: 20px; font-size: 0.9em; }
    .nav-link a { color: var(--primary-color); }
</style>
</head>
<body>
    <div class="container">
        <h1>Theme Settings</h1>
        <?php if ($success): ?>
            <p class="success"><?php echo htmlspecialchars($success); ?></p>
        <?php endif; ?>

        <form method="post" action="admin-theme.php" id="theme-form">
            <div class="card">
                <label for="selected_squadron_id">Squadron</label>
                <select name="selected_squadron_id" id="selected_squadron_id" onchange="updateSquadronInfo()">
                    <option value="">-- None Selected --</option>
                    <?php foreach ($squadrons as $s): ?>
                        <option value="<?php echo htmlspecialchars((string) $s['id']); ?>" <?php echo ($theme['selected_squadron_id'] == $s['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="squadron-info" id="squadron-info">
                    <?php if ($selectedSquadron): ?>
                        <?php if (!empty($selectedSquadron['icon'])): ?>
                            <img src="<?php echo htmlspecialchars(iconUrl($selectedSquadron['icon'])); ?>" alt="icon">
                        <?php else: ?>
                            <div class="placeholder"></div>
                        <?php endif; ?>
                        <p><?php echo htmlspecialchars($selectedSquadron['description']); ?></p>
                    <?php else: ?>
                        <p>No squadron selected.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="color-row">
                    <label for="primary_color">Primary Color</label>
                    <input type="color" name="primary_color" id="primary_color" value="<?php echo htmlspecialchars($theme['primary_color']); ?>" oninput="updatePreview()">
                </div>
                <div class="color-row">
                    <label for="secondary_color">Secondary Color</label>
                    <input type="color" name="secondary_color" id="secondary_color" value="<?php echo htmlspecialchars($theme['secondary_color']); ?>" oninput="updatePreview()">
                </div>
                <div class="color-row">
                    <label for="accent_color">Accent Color</label>
                    <input type="color" name="accent_color" id="accent_color" value="<?php echo htmlspecialchars($theme['accent_color']); ?>" oninput="updatePreview()">
                </div>
                <div class="color-row">
                    <label for="background_color">Background Color</label>
                    <input type="color" name="background_color" id="background_color" value="<?php echo htmlspecialchars($theme['background_color']); ?>" oninput="updatePreview()">
                </div>
                <div class="color-row">
                    <label for="text_color">Text Color</label>
                    <input type="color" name="text_color" id="text_color" value="<?php echo htmlspecialchars($theme['text_color']); ?>" oninput="updatePreview()">
                </div>

                <h3>Live Preview</h3>
                <div class="preview" id="preview">
                    <div class="swatch" id="swatch-primary" style="background: <?php echo htmlspecialchars($theme['primary_color']); ?>;">Primary</div>
                    <div class="swatch" id="swatch-secondary" style="background: <?php echo htmlspecialchars($theme['secondary_color']); ?>;">Secondary</div>
                    <div class="swatch" id="swatch-accent" style="background: <?php echo htmlspecialchars($theme['accent_color']); ?>;">Accent</div>
                    <div class="swatch" id="swatch-background" style="background: <?php echo htmlspecialchars($theme['background_color']); ?>; color: #222; text-shadow: none;">Background</div>
                    <div class="swatch" id="swatch-text" style="background: <?php echo htmlspecialchars($theme['text_color']); ?>;">Text</div>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="save-btn">Save Theme</button>
            </div>
        </form>

        <form method="post" action="admin-theme.php" onsubmit="return confirm('Reset theme to default colors?');">
            <input type="hidden" name="reset" value="1">
            <div class="actions">
                <button type="submit" class="reset-btn">Reset to Defaults</button>
            </div>
        </form>

        <div class="nav-link">
            <a href="admin-panel.php">&larr; Back to Admin Panel</a>
        </div>
    </div>

    <script>
        var squadronData = <?php echo json_encode($squadronMap, JSON_UNESCAPED_SLASHES); ?>;
        var iconBase = 'image.php?file=';

        function updatePreview() {
            document.getElementById('swatch-primary').style.background = document.getElementById('primary_color').value;
            document.getElementById('swatch-secondary').style.background = document.getElementById('secondary_color').value;
            document.getElementById('swatch-accent').style.background = document.getElementById('accent_color').value;
            document.getElementById('swatch-background').style.background = document.getElementById('background_color').value;
            document.getElementById('swatch-text').style.background = document.getElementById('text_color').value;
        }

        function updateSquadronInfo() {
            var select = document.getElementById('selected_squadron_id');
            var info = document.getElementById('squadron-info');
            var squadron = squadronData[select.value];

            if (!squadron) {
                info.innerHTML = '<p>No squadron selected.</p>';
                return;
            }

            var html = '';
            if (squadron.icon) {
                html += '<img src="' + iconBase + encodeURIComponent(squadron.icon.split('/').pop()) + '" alt="icon">';
            } else {
                html += '<div class="placeholder"></div>';
            }
            html += '<p>' + squadron.description + '</p>';
            info.innerHTML = html;
        }
    </script>
</body>
</html>
