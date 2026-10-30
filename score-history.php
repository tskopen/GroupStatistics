<?php
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';
$theme = loadTheme();
$db = getDb();
$publications = $db->query('SELECT * FROM scoring_publications ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
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
<title>OPA Publication History | Group 1</title>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
<style>
.history-page{max-width:1200px;margin:28px auto;padding:0 20px 48px;color:var(--text-color,#222)}
.history-card{background:var(--surface-color,#fff);border:1px solid var(--border-color,#d9e0e8);border-radius:10px;padding:18px;margin:0 0 18px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.history-page h1,.history-page h2{color:var(--primary-color,#002147)}
.history-table{width:100%;border-collapse:collapse;min-width:650px}
.history-table th{background:var(--primary-color,#002147);color:#fff;text-align:left;padding:10px}
.history-table td{padding:9px;border-bottom:1px solid var(--border-color,#ddd)}
.history-table-wrap{overflow-x:auto}
.history-change-up{color:#19703a;font-weight:700}.history-change-down{color:#a51d31;font-weight:700}
.history-muted{color:var(--muted-color,#667085);font-size:.9rem}
.history-nav{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 18px}
.history-nav a{padding:8px 11px;border-radius:6px;background:var(--soft-primary-color,#f2f5f9);color:var(--primary-color,#002147);font-weight:700;text-decoration:none}
.history-nav a.active{background:var(--primary-color,#002147);color:#fff}
@media(max-width:600px){.history-page{padding:0 12px 36px}.history-card{padding:13px}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
<main class="history-page">
    <h1>OPA Publication History</h1>
    <p class="history-muted">Each publication is a permanent snapshot of the official scoring dataset. Drafts are never shown here.</p>
    <?php if (!$publications): ?>
        <section class="history-card"><p>No official scoring publications have been published yet.</p></section>
    <?php else: ?>
        <nav class="history-nav" aria-label="Select publication">
            <?php foreach ($publications as $publication): ?>
            <a class="<?php echo (int)$publication['id']===(int)$selected['id']?'active':''; ?>" href="score-history.php?publication_id=<?php echo (int)$publication['id']; ?>">
                #<?php echo (int)$publication['id']; ?> · <?php echo historyH((new DateTimeImmutable($publication['published_at']))->format('M j, Y')); ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <section class="history-card">
            <h2>Publication #<?php echo (int)$selected['id']; ?></h2>
            <p class="history-muted">Published <?php echo historyH($selected['published_at']); ?> by <?php echo historyH($selected['published_by']); ?> · Entry method: <?php echo historyH($selected['data_entry_method']); ?><?php if ($selected['source_filename']): ?> · Workbook: <?php echo historyH($selected['source_filename']); ?><?php endif; ?></p>
            <div class="history-table-wrap">
                <table class="history-table">
                    <thead><tr><th>Rank</th><th>Squadron</th><th>OPA</th><th>Previous OPA</th><th>OPA Change</th><th>Previous Rank</th><th>Rank Change</th></tr></thead>
                    <tbody>
                    <?php foreach ($leaderboard as $row): ?>
                    <tr>
                        <td><strong>#<?php echo (int)$row['rank']; ?></strong></td>
                        <td><?php echo historyH($row['name']); ?></td>
                        <td><strong><?php echo number_format((float)$row['opa'],2); ?>%</strong></td>
                        <td><?php echo $row['previous_opa']===null?'—':number_format((float)$row['previous_opa'],2).'%'; ?></td>
                        <td class="<?php echo ($row['opa_change']??0)>0?'history-change-up':(($row['opa_change']??0)<0?'history-change-down':''); ?>"><?php echo $row['opa_change']===null?'—':(($row['opa_change']>0?'+':'').number_format((float)$row['opa_change'],2).' pp'); ?></td>
                        <td><?php echo $row['previous_rank']===null?'—':'#'.(int)$row['previous_rank']; ?></td>
                        <td class="<?php echo ($row['rank_change']??0)>0?'history-change-up':(($row['rank_change']??0)<0?'history-change-down':''); ?>"><?php echo $row['rank_change']===null?'New':(($row['rank_change']>0?'+':'').(int)$row['rank_change'].' positions'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php foreach ($categoryRows as $categoryName => $rows): ?>
        <section class="history-card">
            <h2><?php echo historyH($categoryName); ?></h2>
            <div class="history-table-wrap">
                <table class="history-table">
                    <thead><tr><th>Rank</th><th>Squadron</th><th>Category Score</th><th>Category Points</th><th>Previous Rank</th><th>Rank Movement</th><th>Score Change</th><th>Source Rank</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>#<?php echo (int)$row['category_rank']; ?></td>
                        <td>Squadron <?php echo (int)$row['squadron_id']; ?></td>
                        <td><?php echo number_format((float)$row['category_score'],2); ?>%</td>
                        <td><?php echo (int)$row['category_points']; ?></td>
                        <td><?php echo $row['previous_rank']===null?'—':'#'.(int)$row['previous_rank']; ?></td>
                        <td><?php echo $row['rank_change']===null?'—':(($row['rank_change']>0?'+':'').(int)$row['rank_change']); ?></td>
                        <td><?php echo $row['score_change']===null?'—':(($row['score_change']>0?'+':'').number_format((float)$row['score_change'],2).' pp'); ?></td>
                        <td><?php echo $row['source_rank']===null?'—':(int)$row['source_rank']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endforeach; ?>
    <?php endif; ?>
    <p><a href="index.php">← Return to leaderboard</a></p>
</main>
</body>
</html>
