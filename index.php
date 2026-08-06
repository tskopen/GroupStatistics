<?php
/**
 * USAFA Group 1 Squadron Tracker
 * Public homepage - shows all squadrons ranked by total score.
 */

function readJson($file) {
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

$squadrons = readJson(__DIR__ . '/data/squadrons.json');
$scores = readJson(__DIR__ . '/data/scores.json');

// Compute total score per squadron id
$totals = [];
foreach ($squadrons as $squadron) {
    $totals[$squadron['id']] = 0;
}
foreach ($scores as $score) {
    $sid = $score['squadron_id'] ?? null;
    $value = isset($score['value']) ? (float) $score['value'] : 0;
    if ($sid !== null && array_key_exists($sid, $totals)) {
        $totals[$sid] += $value;
    }
}

// Build ranking list
$ranked = [];
foreach ($squadrons as $squadron) {
    $ranked[] = [
        'squadron' => $squadron,
        'total' => $totals[$squadron['id']] ?? 0,
    ];
}
usort($ranked, function ($a, $b) {
    return $b['total'] <=> $a['total'];
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>USAFA Group 1 Squadron Tracker</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #222; }
    h1 { text-align: center; color: #002147; }
    table { width: 100%; max-width: 700px; margin: 20px auto; border-collapse: collapse; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,0.15); }
    th, td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #ddd; }
    th { background: #002147; color: #fff; }
    tr:nth-child(1) td { font-weight: bold; background: #fff8dc; }
    img.icon { height: 32px; vertical-align: middle; margin-right: 8px; }
    .footer-link { display: block; text-align: center; margin-top: 30px; }
    .footer-link a { color: #002147; text-decoration: none; font-size: 0.9em; }
</style>
</head>
<body>
    <h1>USAFA Group 1 Squadron Tracker</h1>
    <table>
        <tr>
            <th>Rank</th>
            <th>Squadron</th>
            <th>Total Score</th>
        </tr>
        <?php $rank = 1; foreach ($ranked as $entry): ?>
            <tr>
                <td><?php echo $rank; ?></td>
                <td>
                    <?php if (!empty($entry['squadron']['icon'])): ?>
                        <img class="icon" src="<?php echo htmlspecialchars($entry['squadron']['icon']); ?>" alt="icon">
                    <?php endif; ?>
                    <?php echo htmlspecialchars($entry['squadron']['name']); ?>
                </td>
                <td><?php echo htmlspecialchars((string) $entry['total']); ?></td>
            </tr>
        <?php $rank++; endforeach; ?>
    </table>
    <div class="footer-link">
        <a href="admin-login.php">Admin Login</a>
    </div>
</body>
</html>
