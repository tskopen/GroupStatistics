<?php
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();
$db = getDb();
$publications = $db->query('SELECT * FROM scoring_publications ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$publicationLabels = [];
foreach (array_reverse($publications) as $index => $publication) {
    $publicationLabels[(int)$publication['id']] = 'P' . ($index + 1);
}
$requestedId = isset($_GET['publication_id']) ? (int)$_GET['publication_id'] : 0;
$selected = null;
foreach ($publications as $publication) if ((int)$publication['id'] === $requestedId) $selected = $publication;
if (!$selected && $publications) $selected = $publications[0];
$leaderboard = [];
$categoryRows = [];
if ($selected) {
    $leaderboard = opaGetPublishedLeaderboard($db, (int)$selected['id']);
    $stmt = $db->prepare('SELECT category_name,category_key,squadron_id,category_rank,category_score,category_points,source_rank,previous_rank,rank_change,previous_score,score_change
        FROM scoring_publication_scores WHERE publication_id=? ORDER BY category_name,category_rank,squadron_id');
    $stmt->execute([(int)$selected['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $categoryRows[$row['category_name']][] = $row;
}
function historyH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?php echo historyH($theme['primary_color'] ?? '#002147'); ?>">
<meta name="description" content="Official Group 1 OPA publication history and category standings.">
<title>OPA Publication History | USAFA Group 1 Squadron Tracker</title>
<style>
:root {
    --navy: <?php echo historyH($theme['primary_color'] ?? '#002147'); ?>;
    --blue: <?php echo historyH($theme['secondary_color'] ?? '#003366'); ?>;
    --accent: <?php echo historyH($theme['accent_color'] ?? '#667eea'); ?>;
    --ink: <?php echo historyH($theme['text_color'] ?? '#202938'); ?>;
    --page: <?php echo historyH($theme['background_color'] ?? '#f4f6f9'); ?>;
    --muted: color-mix(in srgb, var(--ink) 58%, var(--page));
    --line: color-mix(in srgb, var(--navy), var(--page) 78%);
    --surface: color-mix(in srgb, var(--page), white 92%);
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
    margin: 0;
    background: var(--page);
    color: var(--ink);
    font-family: Arial, Helvetica, sans-serif;
    line-height: 1.55;
}
.history-hero {
    background: linear-gradient(120deg, var(--navy), var(--blue));
    color: #fff;
    padding: 30px 20px;
}
.history-hero-inner, .history-page {
    width: min(100% - 36px, 1080px);
    margin: 0 auto;
}
.history-eyebrow {
    text-transform: uppercase;
    letter-spacing: .13em;
    font-size: .76rem;
    font-weight: 700;
    opacity: .82;
}
.history-hero h1 {
    color: #fff;
    font-size: clamp(1.8rem, 4vw, 2.6rem);
    line-height: 1.15;
    margin: 7px 0;
}
.history-hero p {
    color: #e4edf7;
    max-width: 720px;
    margin: 0;
}
.history-page { padding: 24px 0 50px; }
.history-section { margin: 0 0 22px; min-width: 0; }
.section-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 12px;
    margin: 28px 0 14px;
}
.section-heading:first-child { margin-top: 0; }
.section-heading h2 {
    color: var(--navy);
    font-size: 1.35rem;
    line-height: 1.25;
    margin: 0;
}
.section-heading p {
    color: var(--muted);
    font-size: .9rem;
    margin: 0;
}
.history-card {
    background: var(--surface);
    border: 1px solid var(--line);
    border-top: 4px solid var(--accent);
    border-radius: 14px;
    padding: 19px;
    box-shadow: 0 4px 14px #0f172a0b;
    min-width: 0;
}
.history-card h2, .history-card h3 {
    color: var(--navy);
    margin: 0 0 8px;
    overflow-wrap: anywhere;
}
.history-card h2 { font-size: 1.18rem; }
.history-card h3 { font-size: 1.05rem; }
.history-muted { color: var(--muted); font-size: .9rem; }
.publication-selector {
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
}
.publication-option {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 42px;
    padding: 8px 12px;
    border: 1px solid var(--line);
    border-radius: 9px;
    background: var(--surface);
    color: var(--navy);
    font-size: .9rem;
    font-weight: 700;
    text-decoration: none;
    transition: background .15s ease, border-color .15s ease, transform .15s ease;
}
.publication-option:hover {
    background: color-mix(in srgb, var(--accent) 10%, white);
    border-color: var(--accent);
}
.publication-option.active {
    background: var(--navy);
    border-color: var(--navy);
    color: #fff;
}
.publication-option-label { white-space: nowrap; }
.publication-option-date { opacity: .78; font-size: .82rem; font-weight: 500; }
.publication-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 13px;
}
.publication-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 9px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: color-mix(in srgb, var(--page), white 85%);
    color: var(--ink);
    font-size: .82rem;
    overflow-wrap: anywhere;
}
.history-table-wrap {
    width: 100%;
    overflow-x: auto;
    border: 1px solid var(--line);
    border-radius: 9px;
    -webkit-overflow-scrolling: touch;
}
.history-table {
    width: 100%;
    min-width: 680px;
    border-collapse: collapse;
    background: var(--surface);
    font-size: .9rem;
}
.history-table th {
    background: var(--navy);
    color: #fff;
    text-align: left;
    font-weight: 700;
    padding: 11px 12px;
    white-space: nowrap;
}
.history-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
}
.history-table tbody tr:nth-child(even) { background: color-mix(in srgb, var(--page), white 84%); }
.history-table tbody tr:hover { background: color-mix(in srgb, var(--accent) 9%, white); }
.history-table tbody tr:last-child td { border-bottom: 0; }
.history-change-up { color: #19703a; font-weight: 700; white-space: nowrap; }
.history-change-down { color: #a51d31; font-weight: 700; white-space: nowrap; }
.history-change-same { color: var(--muted); font-weight: 700; white-space: nowrap; }
.history-empty {
    background: var(--surface);
    border: 1px dashed var(--line);
    border-radius: 12px;
    padding: 26px;
    color: var(--muted);
    text-align: center;
}
.history-empty strong { display: block; color: var(--navy); margin-bottom: 5px; font-size: 1.05rem; }
.history-footer-link {
    display: inline-flex;
    align-items: center;
    margin-top: 4px;
    color: var(--blue);
    font-weight: 700;
    text-decoration: none;
}
.history-footer-link:hover { text-decoration: underline; }
@media (max-width: 700px) {
    .history-hero { padding: 24px 17px; }
    .history-hero-inner, .history-page { width: min(100% - 24px, 1080px); }
    .history-page { padding: 16px 0 36px; }
    .section-heading { align-items: start; flex-direction: column; margin: 22px 0 12px; }
    .history-card { padding: 15px; }
    .publication-option { flex: 1 1 145px; justify-content: space-between; }
    .publication-option-date { text-align: right; }
    .publication-meta-item { max-width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
    html { scroll-behavior: auto; }
    *, *::before, *::after { transition-duration: .01ms !important; }
}
</style>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
<header class="history-hero">
    <div class="history-hero-inner">
        <div class="history-eyebrow">USAFA · Group 1</div>
        <h1>OPA Publication History</h1>
        <p>Review official scoring snapshots, overall standings, and category-level results for each published period.</p>
    </div>
</header>
<main class="history-page">
    <?php if (!$publications): ?>
        <div class="history-empty">
            <strong>No publications yet</strong>
            Official OPA scoring has not been published. Once a publication is released, its standings and category results will appear here.
        </div>
    <?php else: ?>
        <section class="history-section" aria-labelledby="publication-selector-title">
            <div class="section-heading">
                <h2 id="publication-selector-title">Select Publication</h2>
                <p>Choose a snapshot to review its results.</p>
            </div>
            <nav class="publication-selector" aria-label="Select publication">
                <?php foreach ($publications as $publication): ?>
                <?php
                    $publicationId = (int)$publication['id'];
                    $publicationDate = new DateTimeImmutable($publication['published_at']);
                ?>
                <a class="publication-option <?php echo $publicationId === (int)$selected['id'] ? 'active' : ''; ?>"
                   href="score-history.php?publication_id=<?php echo $publicationId; ?>"
                   <?php echo $publicationId === (int)$selected['id'] ? 'aria-current="page"' : ''; ?>>
                    <span class="publication-option-label"><?php echo historyH($publicationLabels[$publicationId] ?? ('#' . $publicationId)); ?></span>
                    <span class="publication-option-date"><?php echo historyH($publicationDate->format('M j, Y')); ?></span>
                </a>
                <?php endforeach; ?>
            </nav>
        </section>

        <section class="history-section" aria-labelledby="overall-standings-title">
            <div class="section-heading">
                <h2 id="overall-standings-title">Overall OPA Standings</h2>
                <p>Group 1 rankings for the selected publication.</p>
            </div>
            <div class="history-card">
                <h2><?php echo historyH($publicationLabels[(int)$selected['id']] ?? ('Publication #' . (int)$selected['id'])); ?></h2>
                <div class="publication-meta">
                    <span class="publication-meta-item">Published <?php echo historyH((new DateTimeImmutable($selected['published_at']))->format('M j, Y · g:i A')); ?></span>
                    <span class="publication-meta-item">By <?php echo historyH($selected['published_by']); ?></span>
                    <span class="publication-meta-item">Entry method: <?php echo historyH(ucfirst((string)$selected['data_entry_method'])); ?></span>
                    <?php if (!empty($selected['source_filename'])): ?>
                    <span class="publication-meta-item">Workbook: <?php echo historyH($selected['source_filename']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="history-table-wrap" style="margin-top:16px">
                    <table class="history-table">
                        <thead><tr><th>Rank</th><th>Squadron</th><th>OPA</th><th>Previous OPA</th><th>OPA Change</th><th>Previous Rank</th><th>Rank Change</th></tr></thead>
                        <tbody>
                        <?php foreach ($leaderboard as $row): ?>
                        <?php
                            $opaChange = $row['opa_change'] === null ? null : (float)$row['opa_change'];
                            $rankChange = $row['rank_change'] === null ? null : (int)$row['rank_change'];
                        ?>
                        <tr>
                            <td><strong>#<?php echo (int)$row['rank']; ?></strong></td>
                            <td><?php echo historyH($row['name']); ?></td>
                            <td><strong><?php echo number_format((float)$row['opa'],2); ?>%</strong></td>
                            <td><?php echo $row['previous_opa']===null?'—':number_format((float)$row['previous_opa'],2).'%'; ?></td>
                            <td class="<?php echo $opaChange===null?'history-change-same':($opaChange>0?'history-change-up':($opaChange<0?'history-change-down':'history-change-same')); ?>"><?php echo $opaChange===null?'—':(($opaChange>0?'+':'').number_format($opaChange,2).' pp'); ?></td>
                            <td><?php echo $row['previous_rank']===null?'—':'#'.(int)$row['previous_rank']; ?></td>
                            <td class="<?php echo $rankChange===null?'history-change-same':($rankChange>0?'history-change-up':($rankChange<0?'history-change-down':'history-change-same')); ?>"><?php echo $rankChange===null?'New':(($rankChange>0?'+':'').$rankChange.' positions'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <?php if ($categoryRows): ?>
        <section class="history-section" aria-labelledby="category-results-title">
            <div class="section-heading">
                <h2 id="category-results-title">Category Results</h2>
                <p>Individual category scores, points, and movement for the selected snapshot.</p>
            </div>
            <?php foreach ($categoryRows as $categoryName => $rows): ?>
            <article class="history-card" style="margin-bottom:16px">
                <h2><?php echo historyH($categoryName); ?></h2>
                <div class="history-table-wrap">
                    <table class="history-table">
                        <thead><tr><th>Rank</th><th>Squadron</th><th>Category Score</th><th>Category Points</th><th>Previous Rank</th><th>Rank Movement</th><th>Score Change</th><th>Previous Points</th><th>Points Change</th><th>Source Rank</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                        <?php
                            $categoryPoints = (int)$row['category_points'];
                            $previousPoints = $row['previous_rank'] === null ? null : 11 - (int)$row['previous_rank'];
                            $pointsChange = $previousPoints === null ? null : $categoryPoints - $previousPoints;
                            $rankMovement = $row['rank_change'] === null ? null : (int)$row['rank_change'];
                            $scoreChange = $row['score_change'] === null ? null : (float)$row['score_change'];
                        ?>
                        <tr>
                            <td><strong>#<?php echo (int)$row['category_rank']; ?></strong></td>
                            <td>Squadron <?php echo (int)$row['squadron_id']; ?></td>
                            <td><?php echo number_format((float)$row['category_score'],2); ?>%</td>
                            <td><?php echo $categoryPoints; ?></td>
                            <td><?php echo $row['previous_rank']===null?'—':'#'.(int)$row['previous_rank']; ?></td>
                            <td class="<?php echo $rankMovement===null?'history-change-same':($rankMovement>0?'history-change-up':($rankMovement<0?'history-change-down':'history-change-same')); ?>"><?php echo $rankMovement===null?'—':(($rankMovement>0?'+':'').$rankMovement); ?></td>
                            <td class="<?php echo $scoreChange===null?'history-change-same':($scoreChange>0?'history-change-up':($scoreChange<0?'history-change-down':'history-change-same')); ?>"><?php echo $scoreChange===null?'—':(($scoreChange>0?'+':'').number_format($scoreChange,2).' pp'); ?></td>
                            <td><?php echo $previousPoints===null?'—':$previousPoints; ?></td>
                            <td class="<?php echo $pointsChange===null?'history-change-same':($pointsChange>0?'history-change-up':($pointsChange<0?'history-change-down':'history-change-same')); ?>"><?php echo $pointsChange===null?'—':(($pointsChange>0?'+':'').$pointsChange); ?></td>
                            <td><?php echo $row['source_rank']===null?'—':(int)$row['source_rank']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
    <?php endif; ?>
    <a class="history-footer-link" href="index.php">← Return to leaderboard</a>
</main>
</body>
</html>