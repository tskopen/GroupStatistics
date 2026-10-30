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
// The entire history view is driven by these two publication endpoints.
// Defaults to the oldest and newest available snapshots.
$compareAId = isset($_GET['compare_a']) ? (int)$_GET['compare_a'] : 0;
$compareBId = isset($_GET['compare_b']) ? (int)$_GET['compare_b'] : 0;
$publicationIds = array_map(static fn($publication) => (int)$publication['id'], $publications);
if ($publications) {
    if (!in_array($compareAId, $publicationIds, true)) $compareAId = (int)$publications[count($publications) - 1]['id'];
    if (!in_array($compareBId, $publicationIds, true)) $compareBId = (int)$publications[0]['id'];
}
$publicationComparison = null;
$categoryComparisons = [];
if ($publications) {
    $publicationComparison = opaGetPublicationLeaderboardComparison($db, $compareAId, $compareBId);
    $categoryComparisons = opaGetPublicationCategoryComparison($db, $compareAId, $compareBId);
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
.publication-compare-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr)) auto;
    align-items: end;
    gap: 12px;
    margin: 14px 0 16px;
}
.publication-compare-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    color: var(--navy);
    font-size: .88rem;
    font-weight: 700;
}
.publication-compare-field select {
    width: 100%;
    min-width: 0;
    padding: 10px 11px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--surface);
    color: var(--ink);
    font: inherit;
}
.publication-compare-button {
    min-height: 40px;
    border: 0;
    border-radius: 8px;
    padding: 9px 15px;
    background: var(--navy);
    color: #fff;
    font-weight: 700;
    cursor: pointer;
}
.publication-compare-caption {
    color: var(--muted);
    font-size: .9rem;
    margin: 0 0 14px;
}
.publication-period-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-top: 18px;
}
.publication-period-card {
    display: flex;
    flex-direction: column;
    gap: 5px;
    padding: 13px 14px;
    border: 1px solid var(--line);
    border-radius: 9px;
    background: color-mix(in srgb, var(--page), white 86%);
    font-size: .87rem;
    overflow-wrap: anywhere;
}
.publication-period-card strong { color: var(--navy); }
.publication-period-card p { margin: 3px 0 0; color: var(--ink); white-space: pre-wrap; }
.category-comparison-card { margin-bottom: 16px; }
.category-comparison-card .history-table { min-width: 1120px; }
.history-subheading { margin: 0 0 12px; color: var(--muted); font-size: .9rem; }

.publication-description {
    margin: 8px 0 0;
    color: var(--ink);
    font-size: .96rem;
    line-height: 1.5;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
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
    .publication-compare-form { grid-template-columns: 1fr; }
    .publication-compare-button { width: 100%; }
    .publication-period-grid { grid-template-columns: 1fr; }
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
        <?php if ($publicationComparison): ?>
        <section class="history-section" aria-labelledby="publication-comparison-title">
            <div class="section-heading">
                <h2 id="publication-comparison-title">Compare Any Two Publications</h2>
                <p>Review cumulative changes across Group 1 over any selected time span.</p>
            </div>
            <div class="history-card">
                <form method="get" action="score-history.php" class="publication-compare-form">

                    <label class="publication-compare-field" for="compare-publication-a">Compare from (A)
                        <select id="compare-publication-a" name="compare_a">
                            <?php foreach (array_reverse($publications) as $publication): ?>
                            <option value="<?php echo (int)$publication['id']; ?>" <?php echo (int)$publication['id']===$compareAId?'selected':''; ?>>
                                <?php echo historyH($publicationLabels[(int)$publication['id']]); ?> · <?php echo historyH((new DateTimeImmutable($publication['published_at']))->format('M j, Y')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="publication-compare-field" for="compare-publication-b">Compare to (B)
                        <select id="compare-publication-b" name="compare_b">
                            <?php foreach (array_reverse($publications) as $publication): ?>
                            <option value="<?php echo (int)$publication['id']; ?>" <?php echo (int)$publication['id']===$compareBId?'selected':''; ?>>
                                <?php echo historyH($publicationLabels[(int)$publication['id']]); ?> · <?php echo historyH((new DateTimeImmutable($publication['published_at']))->format('M j, Y')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="publication-compare-button" type="submit">Compare Periods</button>
                </form>
                <p class="publication-compare-caption">
                    Comparing <strong><?php echo historyH($publicationComparison['publication_a']['label']); ?></strong>
                    (<?php echo historyH((new DateTimeImmutable($publicationComparison['publication_a']['published_at']))->format('M j, Y')); ?>)
                    to <strong><?php echo historyH($publicationComparison['publication_b']['label']); ?></strong>
                    (<?php echo historyH((new DateTimeImmutable($publicationComparison['publication_b']['published_at']))->format('M j, Y')); ?>).
                    OPA change is B minus A. Positive rank movement means places gained.
                </p>
                <div class="history-table-wrap">
                    <table class="history-table">
                        <thead><tr>
                            <th>Squadron</th><th><?php echo historyH($publicationComparison['publication_a']['label']); ?> OPA</th>
                            <th><?php echo historyH($publicationComparison['publication_a']['label']); ?> Rank</th>
                            <th><?php echo historyH($publicationComparison['publication_b']['label']); ?> OPA</th>
                            <th><?php echo historyH($publicationComparison['publication_b']['label']); ?> Rank</th>
                            <th>OPA Change</th><th>Rank Movement</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($publicationComparison['squadrons'] as $comparisonRow): ?>
                        <?php
                            $opaDelta = (float)$comparisonRow['opa_change'];
                            $rankDelta = (int)$comparisonRow['rank_movement'];
                            $deltaClass = $opaDelta > 0.0000001 ? 'history-change-up' : ($opaDelta < -0.0000001 ? 'history-change-down' : 'history-change-same');
                            $rankClass = $rankDelta > 0 ? 'history-change-up' : ($rankDelta < 0 ? 'history-change-down' : 'history-change-same');
                        ?>
                        <tr>
                            <td><strong><?php echo historyH($comparisonRow['name']); ?></strong></td>
                            <td><?php echo number_format((float)$comparisonRow['opa_a'], 2); ?>%</td>
                            <td>#<?php echo $rankDelta === null ? '—' : (int)$comparisonRow['rank_a']; ?></td>
                            <td><?php echo number_format((float)$comparisonRow['opa_b'], 2); ?>%</td>
                            <td>#<?php echo (int)$comparisonRow['rank_b']; ?></td>
                            <td class="<?php echo $deltaClass; ?>"><?php echo ($opaDelta > 0 ? '+' : '') . number_format($opaDelta, 2); ?> pp</td>
                            <td class="<?php echo $rankClass; ?>"><?php echo $rankDelta > 0 ? '+' . $rankDelta . ' places gained' : ($rankDelta < 0 ? $rankDelta . ' places lost' : 'No change'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="publication-period-grid">
                    <?php foreach (['a' => $publicationComparison['publication_a'], 'b' => $publicationComparison['publication_b']] as $periodKey => $period): ?>
                    <article class="publication-period-card">
                        <strong><?php echo historyH($period['label']); ?> · <?php echo $periodKey === 'a' ? 'Starting period' : 'Ending period'; ?></strong>
                        <span><?php echo historyH((new DateTimeImmutable($period['published_at']))->format('M j, Y · g:i A')); ?></span>
                        <span>Published by <?php echo historyH($period['published_by']); ?></span>
                        <?php if (!empty($period['description'])): ?>
                        <p><?php echo historyH($period['description']); ?></p>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($categoryComparisons): ?>
        <section class="history-section" aria-labelledby="category-results-title">
            <div class="section-heading">
                <h2 id="category-results-title">Category Comparisons</h2>
                <p>Every category uses the same selected publication periods as the overall OPA comparison.</p>
            </div>
            <?php foreach ($categoryComparisons as $category): ?>
            <article class="history-card category-comparison-card">
                <h2><?php echo historyH($category['name']); ?></h2>
                <p class="history-subheading">
                    <?php echo historyH($publicationComparison['publication_a']['label']); ?> versus
                    <?php echo historyH($publicationComparison['publication_b']['label']); ?> ·
                    Rank movement is positive for places gained; score and points changes are B minus A.
                </p>
                <div class="history-table-wrap">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Squadron</th>
                                <th><?php echo historyH($publicationComparison['publication_a']['label']); ?> Rank</th>
                                <th><?php echo historyH($publicationComparison['publication_b']['label']); ?> Rank</th>
                                <th>Rank Movement</th>
                                <th><?php echo historyH($publicationComparison['publication_a']['label']); ?> Score</th>
                                <th><?php echo historyH($publicationComparison['publication_b']['label']); ?> Score</th>
                                <th>Score Change</th>
                                <th><?php echo historyH($publicationComparison['publication_a']['label']); ?> Points</th>
                                <th><?php echo historyH($publicationComparison['publication_b']['label']); ?> Points</th>
                                <th>Points Change</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($category['squadrons'] as $row): ?>
                        <?php
                            $valueA = $row['a'];
                            $valueB = $row['b'];
                            $rankDelta = $row['change']['rank_movement'];
                            $scoreDelta = $row['change']['score'];
                            $pointsDelta = $row['change']['points'];
                            $rankClass = $rankDelta === null ? 'history-change-same' : ($rankDelta > 0 ? 'history-change-up' : ($rankDelta < 0 ? 'history-change-down' : 'history-change-same'));
                            $scoreClass = $scoreDelta === null ? 'history-change-same' : ($scoreDelta > 0.0000001 ? 'history-change-up' : ($scoreDelta < -0.0000001 ? 'history-change-down' : 'history-change-same'));
                            $pointsClass = $pointsDelta === null ? 'history-change-same' : ($pointsDelta > 0.0000001 ? 'history-change-up' : ($pointsDelta < -0.0000001 ? 'history-change-down' : 'history-change-same'));
                        ?>
                        <tr>
                            <td><strong><?php echo historyH($row['name']); ?></strong></td>
                            <td><?php echo $valueA ? '#' . (int)$valueA['rank'] : '—'; ?></td>
                            <td><?php echo $valueB ? '#' . (int)$valueB['rank'] : '—'; ?></td>
                            <td class="<?php echo $rankClass; ?>">
                                <?php
                                    if ($rankDelta === null) echo '—';
                                    elseif ($rankDelta > 0) echo '+' . (int)$rankDelta . ' places gained';
                                    elseif ($rankDelta < 0) echo (int)$rankDelta . ' places lost';
                                    else echo 'No change';
                                ?>
                            </td>
                            <td><?php echo $valueA ? number_format((float)$valueA['score'], 2) . '%' : '—'; ?></td>
                            <td><?php echo $valueB ? number_format((float)$valueB['score'], 2) . '%' : '—'; ?></td>
                            <td class="<?php echo $scoreClass; ?>">
                                <?php echo $scoreDelta === null ? '—' : (($scoreDelta > 0 ? '+' : '') . number_format((float)$scoreDelta, 2) . ' pp'); ?>
                            </td>
                            <td><?php echo $valueA ? number_format((float)$valueA['points'], 0) : '—'; ?></td>
                            <td><?php echo $valueB ? number_format((float)$valueB['points'], 0) : '—'; ?></td>
                            <td class="<?php echo $pointsClass; ?>">
                                <?php echo $pointsDelta === null ? '—' : (($pointsDelta > 0 ? '+' : '') . number_format((float)$pointsDelta, 0)); ?>
                            </td>
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