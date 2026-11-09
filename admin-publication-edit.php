<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/theme-loader.php';

if (empty($_SESSION['admin'])) {
    header('Location: admin-login.php');
    exit;
}

$theme = loadTheme();
$db = getDb();
$publicationId = (int)($_GET['publication_id'] ?? $_POST['publication_id'] ?? 0);
$error = '';
$success = '';

function publicationEditH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_publication') {
            opaCorrectPublication(
                $db,
                $publicationId,
                is_array($_POST['scores'] ?? null) ? $_POST['scores'] : [],
                (string)($_POST['description'] ?? ''),
                (string)($_POST['correction_reason'] ?? ''),
                (string)($_SESSION['username'] ?? 'admin')
            );
            header('Location: admin-score-management.php?notice=publication_updated#published-publications');
            exit;
        }
        if ($action === 'delete_publication') {
            opaDeletePublication(
                $db,
                $publicationId,
                (string)($_POST['deletion_reason'] ?? ''),
                (string)($_SESSION['username'] ?? 'admin')
            );
            header('Location: admin-score-management.php?notice=publication_deleted#published-publications');
            exit;
        }
        throw new RuntimeException('Unsupported publication action.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$data = opaGetPublicationEditData($db, $publicationId);
$correctionsStmt = $db->prepare('SELECT * FROM scoring_publication_corrections WHERE publication_id=? ORDER BY id DESC');
$correctionsStmt->execute([$publicationId]);
$correctionHistory = $correctionsStmt->fetchAll(PDO::FETCH_ASSOC);
$publication = $data['publication'] ?? null;
$categories = $data['categories'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?php echo publicationEditH($theme['primary_color'] ?? '#002147'); ?>">
<title>Edit OPA Publication | USAFA Group 1 Squadron Tracker</title>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
<style>
:root {
    --pub-navy: <?php echo publicationEditH($theme['primary_color'] ?? '#002147'); ?>;
    --pub-blue: <?php echo publicationEditH($theme['secondary_color'] ?? '#003366'); ?>;
    --pub-accent: <?php echo publicationEditH($theme['accent_color'] ?? '#667eea'); ?>;
    --pub-page: <?php echo publicationEditH($theme['background_color'] ?? '#f4f6f9'); ?>;
    --pub-ink: <?php echo publicationEditH($theme['text_color'] ?? '#202938'); ?>;
    --pub-line: color-mix(in srgb, var(--pub-navy), var(--pub-page) 78%);
}
* { box-sizing:border-box; }
body { margin:0; background:var(--pub-page); color:var(--pub-ink); font-family:Arial,Helvetica,sans-serif; line-height:1.5; }
.pub-hero { background:linear-gradient(120deg,var(--pub-navy),var(--pub-blue)); color:#fff; padding:26px 20px; }
.pub-hero-inner,.pub-main { width:min(100% - 32px,1180px); margin:0 auto; }
.pub-hero h1 { color:#fff; margin:4px 0; font-size:clamp(1.7rem,4vw,2.4rem); }
.pub-hero p { color:#e5edf7; margin:0; }
.pub-main { padding:22px 0 48px; }
.pub-card { background:#fff; border:1px solid var(--pub-line); border-top:4px solid var(--pub-accent); border-radius:13px; padding:18px; margin-bottom:16px; box-shadow:0 3px 12px #0f172a0b; }
.pub-card h2 { color:var(--pub-navy); margin:0 0 8px; font-size:1.2rem; }
.pub-muted { color:#657184; font-size:.91rem; }
.pub-warning { background:#fff6df; border:1px solid #e7c56f; color:#674b09; border-radius:9px; padding:12px 14px; margin-bottom:14px; }
.pub-error { background:#fdebed; border:1px solid #efb5bc; color:#8d1827; border-radius:9px; padding:12px 14px; margin-bottom:14px; white-space:pre-wrap; }
.pub-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.pub-field { display:flex; flex-direction:column; gap:6px; margin:12px 0; font-weight:700; color:var(--pub-navy); }
.pub-field input,.pub-field textarea,.pub-input { width:100%; min-width:0; border:1px solid var(--pub-line); border-radius:7px; padding:9px 10px; font:inherit; color:var(--pub-ink); background:#fff; }
.pub-field textarea { resize:vertical; }
.pub-table-wrap { overflow-x:auto; border:1px solid var(--pub-line); border-radius:9px; margin-top:12px; }
.pub-table { width:100%; min-width:700px; border-collapse:collapse; font-size:.9rem; }
.pub-table th { background:var(--pub-navy); color:#fff; text-align:left; padding:10px; white-space:nowrap; }
.pub-table td { padding:8px 10px; border-bottom:1px solid var(--pub-line); }
.pub-table tr:last-child td { border-bottom:0; }
.pub-input { max-width:125px; }
.pub-btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:40px; border:0; border-radius:8px; padding:9px 15px; color:#fff; background:var(--pub-navy); font-weight:700; text-decoration:none; cursor:pointer; font:inherit; }
.pub-btn.secondary { color:var(--pub-navy); background:#eaf0f7; border:1px solid var(--pub-line); }
.pub-btn.danger { background:#9c2536; }
.pub-actions { display:flex; flex-wrap:wrap; gap:9px; align-items:center; margin-top:12px; }
.pub-audit-item { padding:12px 0; border-bottom:1px solid var(--pub-line); }
.pub-audit-item:last-child { border-bottom:0; }
.pub-audit-item p { margin:4px 0; }
.pub-category { margin:14px 0; border:1px solid var(--pub-line); border-radius:9px; overflow:hidden; }
.pub-category summary { cursor:pointer; padding:12px 14px; font-weight:700; color:var(--pub-navy); background:#f6f8fb; }
.pub-category-content { padding:12px; }
@media(max-width:700px){.pub-main{width:min(100% - 20px,1180px);padding-top:14px}.pub-card{padding:13px}.pub-grid{grid-template-columns:1fr}.pub-actions .pub-btn{width:100%}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(false); ?>
<header class="pub-hero"><div class="pub-hero-inner">
    <div class="pub-muted" style="color:#dbe8f7;text-transform:uppercase;letter-spacing:.12em;font-weight:700">Administrator · Published results</div>
    <h1><?php echo $publication ? 'Edit Publication #' . (int)$publication['id'] : 'Publication not found'; ?></h1>
    <p>Correct historical category ranks and percentage scores, or remove an incorrect publication.</p>
</div></header>
<main class="pub-main">
    <?php if ($error !== ''): ?><div class="pub-error"><?php echo publicationEditH($error); ?></div><?php endif; ?>

    <?php if (!$publication): ?>
        <section class="pub-card">
            <h2>This publication is no longer available</h2>
            <p class="pub-muted">It may have been deleted. Any correction or deletion history recorded for publication #<?php echo $publicationId; ?> is shown below.</p>
            <a class="pub-btn secondary" href="admin-score-management.php#published-publications">← Back to OPA Management</a>
        </section>
    <?php else: ?>
        <section class="pub-card">
            <h2>Publication details</h2>
            <div class="pub-grid">
                <div><strong>Published:</strong> <?php echo publicationEditH((new DateTimeImmutable($publication['published_at']))->format('M j, Y · g:i A')); ?></div>
                <div><strong>Published by:</strong> <?php echo publicationEditH($publication['published_by']); ?></div>
                <div><strong>Source:</strong> <?php echo publicationEditH($publication['data_entry_method']); ?></div>
                <div><strong>File:</strong> <?php echo publicationEditH($publication['source_filename'] ?: '—'); ?></div>
            </div>
            <p class="pub-muted">The original publication date and publisher are retained. Corrections are separately audited.</p>
        </section>

        <div class="pub-warning">
            <strong>OPA calculation:</strong> OPA = (SAMI percentage score × 0.25) + (AMI percentage score × 0.25) + (PAI percentage score × 0.05) + (Commissioning Education percentage score × 0.75) + (Knowledge Testing percentage score × 0.75). Each percentage score is multiplied by its configured Military weight; weighted contributions are summed. The displayed OPA is a score, not a percentage. <strong>Correction impact:</strong> Saving changes this historical publication in place. OPA scores, overall ranks, category points, and period-to-period movement are recalculated for this publication and every later publication. The public leaderboard changes too when the latest publication is corrected.
        </div>

        <form method="post" action="admin-publication-edit.php?publication_id=<?php echo $publicationId; ?>">
            <input type="hidden" name="action" value="save_publication">
            <section class="pub-card">
                <h2>Publication description</h2>
                <label class="pub-field">Description (240 characters maximum)
                    <textarea name="description" maxlength="240" rows="2"><?php echo publicationEditH($publication['description'] ?? ''); ?></textarea>
                </label>
            </section>

            <?php foreach ($categories as $category): ?>
            <details class="pub-category" open>
                <summary><?php echo publicationEditH($category['category_name']); ?></summary>
                <div class="pub-category-content">
                    <div class="pub-muted">Ranks must be unique from 1–10. Percentage scores must be between 0 and 100.</div>
                    <div class="pub-table-wrap">
                        <table class="pub-table">
                            <thead><tr><th>Squadron</th><th>Category Rank</th><th>Percentage Score</th><th>Category Points</th></tr></thead>
                            <tbody>
                            <?php for ($sid=1;$sid<=10;$sid++): $row=$category['rows'][$sid]??null; ?>
                                <tr>
                                    <td><strong><?php echo $sid; ?> · <?php echo publicationEditH($row['squadron_name'] ?? ('Squadron '.$sid)); ?></strong></td>
                                    <td><input class="pub-input" type="number" min="1" max="10" step="1" required
                                        name="scores[<?php echo publicationEditH($category['category_key']); ?>][<?php echo $sid; ?>][rank]"
                                        value="<?php echo $row ? (int)$row['category_rank'] : ''; ?>"></td>
                                    <td><input class="pub-input" type="number" min="0" max="100" step="0.01" required
                                        name="scores[<?php echo publicationEditH($category['category_key']); ?>][<?php echo $sid; ?>][score]"
                                        value="<?php echo $row ? publicationEditH($row['category_score']) : ''; ?>"></td>
                                    <td><?php echo $row ? 11-(int)$row['category_rank'] : '—'; ?></td>
                                </tr>
                            <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>
            <?php endforeach; ?>

            <section class="pub-card">
                <h2>Reason for correction</h2>
                <label class="pub-field">Required audit note
                    <textarea name="correction_reason" maxlength="240" rows="3" required placeholder="Explain what was incorrect and why this change is needed."></textarea>
                </label>
                <div class="pub-actions">
                    <button class="pub-btn" type="submit">Save Publication Corrections</button>
                    <a class="pub-btn secondary" href="admin-score-management.php#published-publications">Cancel</a>
                </div>
            </section>
        </form>

        <section class="pub-card" style="border-top-color:#9c2536">
            <h2>Delete this publication</h2>
            <p class="pub-muted">This permanently removes the selected published snapshot and its category/overall rows. Later publications remain, are renumbered in the history display, and have their comparison values recalculated against the preceding surviving publication. The deletion is retained in the administrator audit log.</p>
            <form method="post" action="admin-publication-edit.php?publication_id=<?php echo $publicationId; ?>"
                onsubmit="return confirm('Permanently delete this published OPA snapshot? This cannot be undone.');">
                <input type="hidden" name="action" value="delete_publication">
                <label class="pub-field">Required deletion reason
                    <textarea name="deletion_reason" maxlength="240" rows="2" required placeholder="Explain why this publication should be removed."></textarea>
                </label>
                <button class="pub-btn danger" type="submit">Delete Publication</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($correctionHistory): ?>
    <section class="pub-card">
        <h2>Correction audit history</h2>
        <?php foreach ($correctionHistory as $audit): ?>
            <article class="pub-audit-item">
                <strong><?php echo strtoupper(publicationEditH($audit['operation'])); ?> · <?php echo publicationEditH($audit['performed_at']); ?> · <?php echo publicationEditH($audit['performed_by']); ?></strong>
                <p><?php echo publicationEditH($audit['reason']); ?></p>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <p><a class="pub-btn secondary" href="admin-score-management.php#published-publications">← Back to OPA Management</a></p>
</main>
</body>
</html>
