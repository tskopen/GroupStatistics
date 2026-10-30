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
$username = (string)($_SESSION['username'] ?? 'admin');
$success = '';
$error = '';
$validation = ['errors' => [], 'warnings' => []];
$publicationDescription = trim((string)($_POST['publication_description'] ?? ''));

function opaRedirectToDraft(int $draftId, array $params = []): void {
    $query = array_merge(['draft_id' => $draftId], $params);
    header('Location: admin-score-management.php?' . http_build_query($query));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_draft') {
            $draftId = opaCreateDraft($db, $username, trim((string)($_POST['title'] ?? 'Scoring update')) ?: 'Scoring update');
            opaRedirectToDraft($draftId, ['notice' => 'draft_created']);
        }

        $draftId = (int)($_POST['draft_id'] ?? 0);
        $draft = opaGetDraft($db, $draftId);
        if (!$draft) throw new RuntimeException('This draft is no longer editable. Create or select an open draft.');

        if ($action === 'save_draft' || $action === 'recalculate_ranks') {
            $categories = opaGetCategories($db);
            $squadrons = $db->query('SELECT id FROM squadrons WHERE id BETWEEN 1 AND 10 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            $submitted = $_POST['scores'] ?? [];
            $updates = [];
            $errors = [];
            foreach ($categories as $category) {
                $categoryId = (int)$category['id'];
                for ($sid = 1; $sid <= 10; $sid++) {
                    $data = $submitted[$categoryId][$sid] ?? [];
                    $rankRaw = trim((string)($data['rank'] ?? ''));
                    $scoreRaw = trim((string)($data['score'] ?? ''));
                    $rank = $rankRaw === '' ? null : filter_var($rankRaw, FILTER_VALIDATE_INT);
                    $score = $scoreRaw === '' ? null : filter_var($scoreRaw, FILTER_VALIDATE_FLOAT);
                    if ($rankRaw !== '' && ($rank === false || $rank < 1 || $rank > 10)) $errors[] = $category['display_name'] . ': Squadron ' . $sid . ' rank must be 1–10.';
                    if ($scoreRaw !== '' && ($score === false || $score < 0 || $score > 100)) $errors[] = $category['display_name'] . ': Squadron ' . $sid . ' percentage must be 0–100.';
                    if (!$errors) $updates[] = [$categoryId, $sid, $rank === false ? null : $rank, $score === false ? null : $score];
                }
            }
            if ($errors) throw new RuntimeException(implode("\n", array_values(array_unique($errors))));
            $db->beginTransaction();
            try {
                $lookup = $db->prepare('SELECT category_rank,category_score,rank_source,score_source,entry_source FROM scoring_draft_scores WHERE draft_id=? AND category_id=? AND squadron_id=?');
                $update = $db->prepare('UPDATE scoring_draft_scores SET category_rank=?,category_score=?,rank_source=?,score_source=?,entry_source=?,updated_at=? WHERE draft_id=? AND category_id=? AND squadron_id=?');
                foreach ($updates as [$categoryId, $sid, $rank, $score]) {
                    $lookup->execute([$draftId, $categoryId, $sid]);
                    $old = $lookup->fetch(PDO::FETCH_ASSOC) ?: [];
                    $rankChanged = ($old['category_rank'] === null ? null : (int)$old['category_rank']) !== $rank;
                    $scoreChanged = ($old['category_score'] === null ? null : (float)$old['category_score']) !== $score;
                    $oldRankSource = $old['rank_source'] ?? ($old['entry_source'] ?? 'manual');
                    $oldScoreSource = $old['score_source'] ?? ($old['entry_source'] ?? 'manual');
                    $rankSource = $rankChanged ? (in_array($oldRankSource, ['import', 'modified'], true) ? 'modified' : 'manual') : $oldRankSource;
                    $scoreSource = $scoreChanged ? (in_array($oldScoreSource, ['import', 'modified'], true) ? 'modified' : 'manual') : $oldScoreSource;
                    if ($rankSource === 'modified' || $scoreSource === 'modified') $source = 'modified';
                    elseif ($rankSource === 'import' || $scoreSource === 'import') $source = 'import';
                    else $source = 'manual';
                    $update->execute([$rank, $score, $rankSource, $scoreSource, $source, opaNow(), $draftId, $categoryId, $sid]);
                }
                if ($action === 'recalculate_ranks') {
                    opaRecalculateDraftRanks($db, $draftId, (int)($_POST['recalculate_category_id'] ?? 0));
                }
                $db->prepare('UPDATE scoring_drafts SET updated_by=?,updated_at=? WHERE id=?')->execute([$username, opaNow(), $draftId]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            opaRedirectToDraft($draftId, ['notice' => $action === 'recalculate_ranks' ? 'ranks_recalculated' : 'saved']);
        }

        if ($action === 'update_category') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name = trim((string)($_POST['display_name'] ?? ''));
            $direction = (string)($_POST['ranking_direction'] ?? 'manual');
            if ($name === '' || !in_array($direction, ['manual', 'higher', 'lower'], true)) throw new RuntimeException('Enter a category name and a valid ranking direction.');
            $stmt = $db->prepare('UPDATE scoring_categories SET display_name=?,description=?,ranking_direction=?,is_active=?,updated_at=? WHERE id=?');
            $stmt->execute([$name, trim((string)($_POST['description'] ?? '')), $direction, isset($_POST['is_active']) ? 1 : 0, opaNow(), $categoryId]);
            opaEnsureDraftRows($db, $draftId);
            opaRedirectToDraft($draftId, ['notice' => 'category_updated']);
        }

        if ($action === 'create_category') {
            $name = trim((string)($_POST['display_name'] ?? ''));
            $direction = (string)($_POST['ranking_direction'] ?? 'manual');
            if ($name === '' || !in_array($direction, ['manual', 'higher', 'lower'], true)) throw new RuntimeException('Enter a category name and a valid ranking direction.');
            $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
            if ($key === '') throw new RuntimeException('The category name must contain letters or numbers.');
            $stmt = $db->prepare('INSERT INTO scoring_categories(category_key,display_name,description,ranking_direction,is_active,sort_order,created_at,updated_at) VALUES(?,?,?,?,1,?,?,?)');
            $maxOrder = (int)$db->query('SELECT COALESCE(MAX(sort_order),0) FROM scoring_categories')->fetchColumn();
            $stmt->execute([$key, $name, trim((string)($_POST['description'] ?? '')), $direction, $maxOrder + 10, opaNow(), opaNow()]);
            opaEnsureDraftRows($db, $draftId);
            opaRedirectToDraft($draftId, ['notice' => 'category_created']);
        }

        if ($action === 'preview_import') {
            if (!isset($_FILES['workbook']) || ($_FILES['workbook']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a valid .xlsx workbook to inspect.');
            }
            $file = $_FILES['workbook'];
            if (($file['size'] ?? 0) > 10 * 1024 * 1024) throw new RuntimeException('The workbook must be 10 MB or smaller.');
            if (!is_uploaded_file($file['tmp_name']) || strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new RuntimeException('Only valid .xlsx workbooks are supported. Legacy .xls files must be saved as .xlsx first.');
            }
            $hash = hash_file('sha256', $file['tmp_name']);
            $duplicate = $db->prepare('SELECT id FROM scoring_imports WHERE file_sha256=?');
            $duplicate->execute([$hash]);
            if ($duplicate->fetchColumn()) throw new RuntimeException('This exact workbook has already been imported.');
            $sheets = opaReadXlsxWorkbook($file['tmp_name']);
            $preview = opaBuildImportPreview($sheets, opaGetCategories($db));
            if (empty($preview['records'])) {
                throw new RuntimeException(implode(' ', $preview['warnings']) ?: 'No supported Group 1 records were found.');
            }
            $existingRows = opaGetDraftRows($db, $draftId);
            $replacements = 0;
            $manualOverrideCount = 0;
            $importRecordCount = 0;
            foreach ($preview['records'] as $categoryId => $squadronRecords) {
                foreach ($squadronRecords as $sid => $record) {
                    $existing = $existingRows[(int)$categoryId][(int)$sid] ?? [];
                    $rankChanges = $record['category_rank'] !== null && isset($existing['category_rank']) && $existing['category_rank'] !== null && (int)$existing['category_rank'] !== (int)$record['category_rank'];
                    $scoreChanges = $record['category_score'] !== null && isset($existing['category_score']) && $existing['category_score'] !== null && abs((float)$existing['category_score'] - (float)$record['category_score']) > 0.0000001;
                    if ($rankChanges) $replacements++;
                    if ($scoreChanges) $replacements++;
                    $rankManualOverride = $rankChanges && in_array($existing['rank_source'] ?? 'manual', ['manual', 'modified'], true);
                    $scoreManualOverride = $scoreChanges && in_array($existing['score_source'] ?? 'manual', ['manual', 'modified'], true);
                    if ($rankManualOverride || $scoreManualOverride) $manualOverrideCount++;
                    $importRecordCount++;
                }
            }
            $_SESSION['opa_import_preview'] = [
                'draft_id' => $draftId, 'file_name' => basename((string)$file['name']),
                'file_hash' => $hash, 'preview' => $preview,
                'categories' => array_values(array_unique(array_map(static function ($categoryId) use ($db) {
                    $stmt = $db->prepare('SELECT display_name FROM scoring_categories WHERE id=?');
                    $stmt->execute([(int)$categoryId]);
                    return (string)$stmt->fetchColumn();
                }, array_keys($preview['records'])))),
                'replacement_count' => $replacements, 'manual_override_count' => $manualOverrideCount, 'record_count' => $importRecordCount,
            ];
            opaRedirectToDraft($draftId, ['notice' => 'import_preview']);
        }

        if ($action === 'apply_import') {
            $pending = $_SESSION['opa_import_preview'] ?? null;
            if (!$pending || (int)$pending['draft_id'] !== $draftId) throw new RuntimeException('The import preview expired. Upload the workbook again.');
            $applied = opaApplyImportPreview($db, $draftId, $pending['preview'], $pending['file_name'], $pending['file_hash'], $username);
            unset($_SESSION['opa_import_preview']);
            opaEnsureDraftRows($db, $draftId);
            opaRedirectToDraft($draftId, ['notice' => 'imported', 'records' => $applied]);
        }

        if ($action === 'discard_draft') {
            $db->prepare("UPDATE scoring_drafts SET status='discarded',updated_by=?,updated_at=? WHERE id=? AND status='draft'")
                ->execute([$username, opaNow(), $draftId]);
            unset($_SESSION['opa_import_preview']);
            $newId = opaCreateDraft($db, $username, 'Scoring update');
            opaRedirectToDraft($newId, ['notice' => 'discarded']);
        }

        if ($action === 'publish') {
            $result = opaPublishDraft($db, $draftId, $username, $publicationDescription);
            unset($_SESSION['opa_import_preview']);
            header('Location: score-history.php?publication_id=' . $result['publication_id'] . '&published=1');
            exit;
        }

        throw new RuntimeException('Unsupported score-management action.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['notice'])) {
    $notices = [
        'draft_created' => 'A new scoring draft was created.',
        'legacy' => 'Legacy event points are retained for event history, but official squadron standings are now managed through OPA Score Management.',
        'saved' => 'Draft saved. Published results have not changed.',
        'ranks_recalculated' => 'Ranks recalculated from the category percentage scores. Review the values and save or publish when ready.',
        'category_updated' => 'Category settings updated.',
        'category_created' => 'New scoring category added to this draft.',
        'import_preview' => 'Workbook inspected. Review the affected categories before applying it.',
        'imported' => 'Workbook values were added to the draft and remain editable.',
        'discarded' => 'The previous draft was discarded. A new draft is ready.',
    ];
    $success = $notices[(string)$_GET['notice']] ?? '';
}

$draftId = (int)($_GET['draft_id'] ?? 0);
$draft = $draftId ? opaGetDraft($db, $draftId) : null;
if (!$draft) {
    $drafts = $db->query("SELECT id FROM scoring_drafts WHERE status='draft' ORDER BY updated_at DESC,id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $draftId = $drafts ? (int)$drafts['id'] : opaCreateDraft($db, $username);
    $draft = opaGetDraft($db, $draftId);
}
opaEnsureDraftRows($db, $draftId);
$categories = opaGetCategories($db);
$categoryStatuses = opaGetDraftCategoryStatuses($db, $draftId);
$allCategories = opaGetCategories($db, false);
$squadrons = $db->query('SELECT id,name,icon_filename FROM squadrons WHERE id BETWEEN 1 AND 10 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$draftRows = opaGetDraftRows($db, $draftId);
$projected = opaGetDraftPreview($db, $draftId);
$validation = opaValidateDraft($db, $draftId);
$pendingImport = $_SESSION['opa_import_preview'] ?? null;
if ($pendingImport && (int)$pendingImport['draft_id'] !== $draftId) $pendingImport = null;
$draftList = $db->query("SELECT id,title,updated_at,updated_by FROM scoring_drafts WHERE status='draft' ORDER BY updated_at DESC,id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$latestPublicationId = opaGetLatestPublicationId($db);
$latestPublication = null;
if ($latestPublicationId !== null) {
    $stmt = $db->prepare('SELECT * FROM scoring_publications WHERE id=?');
    $stmt->execute([$latestPublicationId]);
    $latestPublication = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function opaH($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Score Management | Group 1</title>
<link rel="stylesheet" href="site-ui.css">
<link rel="stylesheet" href="site-components.css">
<style>
:root{--score-primary:<?php echo opaH($theme['primary_color']??'#002147'); ?>;--score-secondary:<?php echo opaH($theme['secondary_color']??'#003366'); ?>;--score-accent:<?php echo opaH($theme['accent_color']??'#667eea'); ?>}
.score-admin{max-width:1320px;margin:26px auto;padding:0 20px 50px;color:var(--text-color,#222)}
.score-admin h1,.score-admin h2,.score-admin h3{color:var(--score-primary)}
.score-admin h1{margin-bottom:5px}.score-intro{color:var(--muted-color,#667085);margin:0 0 20px}
.score-toolbar,.score-card{background:var(--surface-color,#fff);border:1px solid var(--border-color,#d9e0e8);border-radius:12px;padding:18px;margin:0 0 18px;box-shadow:0 2px 7px rgba(0,0,0,.05)}
.score-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.score-toolbar-actions{display:flex;gap:8px;flex-wrap:wrap}
.score-btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:7px;padding:10px 14px;background:var(--score-primary);color:#fff;font-weight:700;text-decoration:none;cursor:pointer}
.score-btn.secondary{background:var(--score-secondary)}.score-btn.danger{background:#9b1c31}.score-btn:disabled{opacity:.55;cursor:not-allowed}
.score-message{padding:12px 14px;border-radius:8px;margin:12px 0;background:#e9f4ec;color:#195b2b}.score-message.error{background:#fcebed;color:#8f172b}.score-message.warning{background:#fff5dd;color:#71520b}
.score-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
.score-field{display:flex;flex-direction:column;gap:5px;margin:8px 0}.score-field label{font-weight:700;font-size:.9rem}
.score-field input,.score-field select,.score-field textarea,.score-input{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#1f2937}
.score-table-wrap{overflow-x:auto}.score-table{width:100%;border-collapse:collapse;min-width:620px}
.score-table th{background:var(--score-primary);color:#fff;text-align:left;padding:10px}
.score-table td{padding:9px 10px;border-bottom:1px solid var(--border-color,#e1e6ed);vertical-align:middle}
.score-table tr:hover td{background:var(--soft-primary-color,#f5f8fc)}
.score-input{max-width:120px}.score-source{font-size:.78rem;color:var(--muted-color,#667085);white-space:nowrap}
.score-points{font-weight:800;color:var(--score-primary);font-variant-numeric:tabular-nums}
.score-preview table{min-width:480px}.score-opa{font-weight:800;font-variant-numeric:tabular-nums}
.score-rank{font-weight:800;color:var(--score-primary)}
.score-section-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.score-section-head h2{margin:0}
.score-category{margin-top:14px;border:1px solid var(--border-color,#d9e0e8);border-radius:9px;overflow:hidden}
.score-category summary{cursor:pointer;padding:13px 16px;background:var(--soft-primary-color,#f4f7fb);font-weight:800;color:var(--score-primary)}
.score-category-content{padding:14px}
.score-columns{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,1fr);gap:18px}
@media(max-width:900px){.score-columns{grid-template-columns:1fr}}
.score-note{font-size:.88rem;color:var(--muted-color,#667085);line-height:1.5}
.score-tag{display:inline-block;border-radius:999px;padding:3px 8px;background:#edf1f6;color:#39485a;font-size:.75rem;font-weight:700}
.score-tag.import{background:#e5f2ff;color:#14518b}.score-tag.modified{background:#fff1d6;color:#7b5000}.score-tag.unchanged{background:#e9f4ec;color:#195b2b}.score-tag.updated{background:#fff1d6;color:#7b5000}.score-tag.new{background:#fcebed;color:#8f172b}
.score-list{margin:8px 0;padding-left:20px}.score-list li{margin:4px 0}
.score-history-link{font-weight:700;color:var(--score-primary)}
.score-inline{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.score-icon{width:30px;height:30px;object-fit:cover;border-radius:4px;vertical-align:middle}
@media(max-width:600px){.score-admin{padding:0 12px 36px}.score-toolbar,.score-card{padding:14px}.score-btn{width:100%}.score-toolbar-actions{width:100%}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/site-header.php'; renderGroupSiteHeader(); ?>
<main class="score-admin">
    <h1>Score Management</h1>
    <p class="score-intro">Manage category rankings and percentage scores in one editable draft. New drafts inherit the latest publication automatically, so you only need to update categories that changed. Only publishing updates the public OPA leaderboard.</p>

    <?php if ($success): ?><div class="score-message"><?php echo opaH($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="score-message error"><?php echo nl2br(opaH($error)); ?></div><?php endif; ?>

    <section class="score-toolbar">
        <div>
            <strong><?php echo opaH($draft['title']); ?> · Draft #<?php echo $draftId; ?></strong>
            <div class="score-note">Last edited <?php echo opaH($draft['updated_at']); ?> by <?php echo opaH($draft['updated_by']); ?></div>
            <div class="score-note">Latest official publication: <?php echo $latestPublication ? opaH($latestPublication['published_at']) . ' · #' . (int)$latestPublication['id'] : 'None yet'; ?></div>
        </div>
        <div class="score-toolbar-actions">
            <a class="score-btn secondary" href="score-history.php">Publication History</a>
            <form method="post" action="admin-score-management.php">
                <input type="hidden" name="action" value="create_draft">
                <input type="hidden" name="title" value="Scoring update">
                <button class="score-btn" type="submit">＋ New Draft</button>
            </form>
        </div>
    </section>

    <section class="score-card">
        <div class="score-section-head"><h2>Import Excel / Autofill</h2><span class="score-tag">Preview before applying</span></div>
        <p class="score-note">Upload an .xlsx workbook to identify worksheet headers and populate the same rank and percentage fields below. Only Squadron identifiers 1–10 are eligible. Existing values are not replaced until you review and confirm the import. Unsupported columns or ambiguous categories are reported rather than guessed.</p>
        <form method="post" enctype="multipart/form-data" action="admin-score-management.php" class="score-inline">
            <input type="hidden" name="action" value="preview_import">
            <input type="hidden" name="draft_id" value="<?php echo $draftId; ?>">
            <input type="file" name="workbook" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            <button class="score-btn secondary" type="submit">Inspect Workbook</button>
        </form>
        <?php if ($pendingImport): ?>
        <div class="score-message warning">
            <strong>Workbook preview: <?php echo opaH($pendingImport['file_name']); ?></strong>
            <p><?php echo (int)$pendingImport['record_count']; ?> squadron/category records identified. <?php echo (int)$pendingImport['replacement_count']; ?> existing field values would change. <?php echo (int)($pendingImport['manual_override_count'] ?? 0); ?> manually entered or corrected rows would be affected. Categories without workbook data will be preserved.</p>
            <p><strong>Categories affected:</strong> <?php echo opaH(implode(', ', $pendingImport['categories'] ?? [])); ?></p>
            <div class="score-table-wrap">
                <table class="score-table">
                    <thead><tr><th>Category</th><th>Squadron</th><th>Group 1 Rank</th><th>Percentage Score</th><th>Original Source Rank</th><th>Raw Measurement</th></tr></thead>
                    <tbody>
                    <?php foreach (($pendingImport['preview']['records'] ?? []) as $categoryId => $records): ?>
                        <?php $previewCategoryName='Category '.$categoryId; foreach ($categories as $cat) if ((int)$cat['id']===(int)$categoryId) $previewCategoryName=$cat['display_name']; ?>
                        <?php foreach ($records as $previewRecord): ?>
                        <tr>
                            <td><?php echo opaH($previewCategoryName); ?></td>
                            <td><?php echo (int)$previewRecord['squadron_id']; ?></td>
                            <td><?php echo $previewRecord['category_rank']===null?'Needs entry':(int)$previewRecord['category_rank']; ?></td>
                            <td><?php echo $previewRecord['category_score']===null?'Not supplied':opaH($previewRecord['category_score']).'%'; ?></td>
                            <td><?php echo $previewRecord['source_rank']===null?'—':(int)$previewRecord['source_rank']; ?></td>
                            <td><?php echo $previewRecord['source_measurement']===null?'—':opaH($previewRecord['source_measurement']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($pendingImport['preview']['warnings'])): ?>
            <strong>Validation notes</strong>
            <ul class="score-list"><?php foreach ($pendingImport['preview']['warnings'] as $warning): ?><li><?php echo opaH($warning); ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <form method="post" action="admin-score-management.php" onsubmit="return confirm('Apply these workbook values to the current draft? Existing fields shown in the preview will be replaced. Unmapped and unrelated categories will be preserved.');">
                <input type="hidden" name="action" value="apply_import">
                <input type="hidden" name="draft_id" value="<?php echo $draftId; ?>">
                <button class="score-btn" type="submit">Apply Import to Draft</button>
            </form>
        </div>
        <?php endif; ?>
    </section>

    <form method="post" action="admin-score-management.php" id="score-entry-form">
        <input type="hidden" name="action" value="save_draft">
        <input type="hidden" name="draft_id" value="<?php echo $draftId; ?>">
        <section class="score-card">
            <div class="score-section-head">
                <div><h2>Manual Score Entry</h2><p class="score-note">Both fields are editable independently. Rank produces category points (1st = 10, 10th = 1); percentage score is used for OPA. Categories marked “No change” are carried forward from the latest publication automatically.</p></div>
                <button class="score-btn" type="submit">Save Draft</button>
            </div>
            <?php foreach ($categories as $category): $categoryId = (int)$category['id']; $categoryStatus = $categoryStatuses[$categoryId] ?? 'initial'; ?>
            <details class="score-category" open>
                <summary><?php echo opaH($category['display_name']); ?>
                    <span class="score-tag"><?php echo $category['ranking_direction'] === 'manual' ? 'Direction not configured' : ($category['ranking_direction'] === 'higher' ? 'Higher is better' : 'Lower is better'); ?></span>
                    <span class="score-tag <?php echo opaH($categoryStatus); ?>"><?php echo $categoryStatus === 'unchanged' ? 'No change · prior values retained' : ($categoryStatus === 'updated' ? 'Updated in this draft' : ($categoryStatus === 'new' ? 'New category · values required' : 'Initial entry required')); ?></span>
                </summary>
                <div class="score-category-content">
                    <div class="score-inline" style="margin-bottom:10px"><button class="score-btn secondary score-recalculate" type="button" data-category-id="<?php echo $categoryId; ?>">Recalculate ranks from percentage scores</button><span class="score-note">Requires all ten scores and a configured higher/lower ranking direction. Ties need manual resolution.</span></div>
                    <div class="score-table-wrap">
                        <table class="score-table">
                            <thead><tr><th>Squadron</th><th>Group 1 Rank</th><th>Rank Source</th><th>Category Score</th><th>Score Source</th><th>Category Points</th></tr></thead>
                            <tbody>
                            <?php foreach ($squadrons as $squadron): $sid=(int)$squadron['id']; $record=$draftRows[$categoryId][$sid]??[]; $source=$record['entry_source']??'manual'; $rankSource=$record['rank_source']??$source; $scoreSource=$record['score_source']??$source; ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($squadron['icon_filename'])): ?><img class="score-icon" src="<?php echo opaH(iconUrl($squadron['icon_filename'])); ?>" alt=""><?php endif; ?>
                                        <strong><?php echo $sid; ?> · <?php echo opaH($squadron['name']); ?></strong>
                                        <?php if (($record['source_rank']??null)!==null): ?><div class="score-source">Source ranking: <?php echo (int)$record['source_rank']; ?></div><?php endif; ?>
                                    </td>
                                    <td><input class="score-input category-rank-input" data-category-id="<?php echo $categoryId; ?>" data-squadron-id="<?php echo $sid; ?>" name="scores[<?php echo $categoryId; ?>][<?php echo $sid; ?>][rank]" type="number" min="1" max="10" step="1" value="<?php echo ($record['category_rank']??null)!==null ? (int)$record['category_rank'] : ''; ?>" placeholder="1–10" aria-label="Squadron <?php echo $sid; ?> <?php echo opaH($category['display_name']); ?> rank"></td>
                                    <td><span class="score-tag <?php echo $rankSource==='import'?'import':($rankSource==='modified'?'modified':''); ?>"><?php echo $rankSource==='import'?'Excel import':($rankSource==='modified'?'Modified after import':'Manual'); ?></span></td>
                                    <td><input class="score-input category-score-input" data-category-id="<?php echo $categoryId; ?>" data-squadron-id="<?php echo $sid; ?>" name="scores[<?php echo $categoryId; ?>][<?php echo $sid; ?>][score]" type="number" min="0" max="100" step="0.01" value="<?php echo ($record['category_score']??null)!==null ? opaH($record['category_score']) : ''; ?>" placeholder="0–100%" aria-label="Squadron <?php echo $sid; ?> <?php echo opaH($category['display_name']); ?> percentage"></td>
                                    <td><span class="score-tag <?php echo $scoreSource==='import'?'import':($scoreSource==='modified'?'modified':''); ?>"><?php echo $scoreSource==='import'?'Excel import':($scoreSource==='modified'?'Modified after import':'Manual'); ?></span></td>
                                    <td class="score-points" data-points-for="<?php echo $categoryId . '-' . $sid; ?>"><?php $points=opaCategoryPoints(isset($record['category_rank'])?(int)$record['category_rank']:null); echo $points===null?'—':$points; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>
            <?php endforeach; ?>
            <div class="score-inline" style="margin-top:16px">
                <button class="score-btn" type="submit">Save Draft</button>
                <span class="score-note">Saving does not change the public leaderboard.</span>
            </div>
        </section>
    </form>

    <div class="score-columns">
        <section class="score-card score-preview">
            <h2>Projected OPA Leaderboard</h2>
            <p class="score-note">OPA is the unrounded average of the active category percentage scores. Incomplete rows remain unranked until completed.</p>
            <div class="score-table-wrap">
                <table class="score-table" id="projected-table">
                    <thead><tr><th>Projected Rank</th><th>Squadron</th><th>Projected OPA</th><th>Completeness</th></tr></thead>
                    <tbody>
                    <?php foreach ($projected as $entry): ?>
                        <tr data-squadron-id="<?php echo (int)$entry['squadron_id']; ?>" data-opa="<?php echo $entry['opa']===null?'':opaH($entry['opa']); ?>">
                            <td class="score-rank projected-rank"><?php echo $entry['rank']===null?'—':'#'.(int)$entry['rank']; ?></td>
                            <td><?php echo (int)$entry['squadron_id']; ?> · <?php echo opaH($entry['name']); ?></td>
                            <td class="score-opa projected-opa"><?php echo $entry['opa']===null?'Incomplete':number_format((float)$entry['opa'],2).'%'; ?></td>
                            <td><?php echo $entry['complete']?'Complete':'Needs scores'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="score-card">
            <h2>Publish Readiness</h2>
            <?php if ($validation['errors']): ?>
                <div class="score-message error"><strong>Publication blocked</strong><ul class="score-list"><?php foreach ($validation['errors'] as $item): ?><li><?php echo opaH($item); ?></li><?php endforeach; ?></ul></div>
            <?php else: ?><div class="score-message">All active categories have ten unique ranks and complete percentage scores.</div><?php endif; ?>
            <?php if ($validation['warnings']): ?>
                <div class="score-message warning"><strong>Review warnings</strong><ul class="score-list"><?php foreach ($validation['warnings'] as $item): ?><li><?php echo opaH($item); ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <p class="score-note">Publication creates an immutable snapshot, records changes against the prior publication, and updates public results. Draft values remain private until then. Unchanged categories retain their previous published results.</p>
            <form method="post" action="admin-score-management.php" onsubmit="return confirm('Publish this scoring dataset as official Group 1 results? This creates a permanent publication snapshot and updates the public OPA leaderboard.');">
                <div class="score-field">
                    <label for="publication-description">Publication Description (optional)</label>
                    <textarea id="publication-description" name="publication_description" maxlength="240" rows="3" placeholder="e.g., Updated AMI results following the latest competition."><?php echo opaH($publicationDescription); ?></textarea>
                    <span class="score-note">A short note displayed with this publication in history and squadron details (240 characters max).</span>
                </div>
                <input type="hidden" name="action" value="publish">
                <input type="hidden" name="draft_id" value="<?php echo $draftId; ?>">
                <button class="score-btn" type="submit" <?php echo $validation['errors']?'disabled':''; ?>>Publish Official Scores</button>
            </form>
            <form method="post" action="admin-score-management.php" style="margin-top:8px" onsubmit="return confirm('Discard this draft? Published score history will not be affected.');">
                <input type="hidden" name="action" value="discard_draft">
                <input type="hidden" name="draft_id" value="<?php echo $draftId; ?>">
                <button class="score-btn danger" type="submit">Discard Draft</button>
            </form>
        </section>
    </div>

    <section class="score-card">
        <div class="score-section-head"><h2>Manage Scoring Categories</h2><span class="score-note">Disabling a category removes it from future OPA calculations without deleting historical records.</span></div>
        <?php foreach ($allCategories as $category): ?>
        <form method="post" action="admin-score-management.php" class="score-card" style="margin-top:12px;padding:14px">
            <input type="hidden" name="action" value="update_category">
            <input type="hidden" name="draft_id" value="<?php echo $draftId; ?>">
            <input type="hidden" name="category_id" value="<?php echo (int)$category['id']; ?>">
            <div class="score-grid">
                <div class="score-field"><label>Category Name</label><input name="display_name" value="<?php echo opaH($category['display_name']); ?>" required></div>
                <div class="score-field"><label>Ranking Direction for Raw Measurements</label>
                    <select name="ranking_direction">
                        <option value="manual" <?php echo $category['ranking_direction']==='manual'?'selected':''; ?>>Manual / not configured</option>
                        <option value="higher" <?php echo $category['ranking_direction']==='higher'?'selected':''; ?>>Higher values are better</option>
                        <option value="lower" <?php echo $category['ranking_direction']==='lower'?'selected':''; ?>>Lower values are better</option>
                    </select>
                </div>
                <div class="score-field"><label>Description</label><input name="description" value="<?php echo opaH($category['description']); ?>"></div>
            </div>
            <label class="score-inline"><input type="checkbox" name="is_active" value="1" <?php echo (int)$category['is_active']===1?'checked':''; ?>> Active in OPA</label>
            <div style="margin-top:10px"><button class="score-btn secondary" type="submit">Save Category Settings</button> <span class="score-tag"><?php echo opaH($category['category_key']); ?></span></div>
        </form>
        <?php endforeach; ?>
        <form method="post" action="admin-score-management.php" class="score-card" style="margin-top:14px;padding:14px">
            <input type="hidden" name="action" value="create_category">
            <input type="hidden" name="draft_id" value="<?php echo $draftId; ?>">
            <h3>Add Category</h3>
            <div class="score-grid">
                <div class="score-field"><label>Category Name</label><input name="display_name" required placeholder="e.g., Academic Readiness"></div>
                <div class="score-field"><label>Raw Measurement Direction</label><select name="ranking_direction"><option value="manual">Manual / not configured</option><option value="higher">Higher values are better</option><option value="lower">Lower values are better</option></select></div>
                <div class="score-field"><label>Description</label><input name="description" placeholder="Optional description"></div>
            </div>
            <button class="score-btn" type="submit">Add Category to Draft</button>
        </form>
    </section>

    <?php if ($draftList): ?>
    <section class="score-card">
        <h2>Saved Drafts</h2>
        <ul class="score-list">
            <?php foreach ($draftList as $savedDraft): ?>
            <li><a class="score-history-link" href="admin-score-management.php?draft_id=<?php echo (int)$savedDraft['id']; ?>">Draft #<?php echo (int)$savedDraft['id']; ?> · <?php echo opaH($savedDraft['title']); ?></a> <span class="score-note">Updated <?php echo opaH($savedDraft['updated_at']); ?> by <?php echo opaH($savedDraft['updated_by']); ?></span></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
    <p><a class="score-history-link" href="admin-panel.php">← Back to Admin Panel</a></p>
</main>

<script>
(function () {
    const categoryIds = <?php echo json_encode(array_map(static fn($category) => (int)$category['id'], $categories)); ?>;
    const inputs = Array.from(document.querySelectorAll('.category-rank-input,.category-score-input'));
    const tableBody = document.querySelector('#projected-table tbody');
    function calculate() {
        const bySquadron = {};
        for (let id = 1; id <= 10; id++) bySquadron[id] = { scores: [], complete: true };
        categoryIds.forEach(function (categoryId) {
            for (let id = 1; id <= 10; id++) {
                const rankInput = document.querySelector('.category-rank-input[data-category-id="' + categoryId + '"][data-squadron-id="' + id + '"]');
                const scoreInput = document.querySelector('.category-score-input[data-category-id="' + categoryId + '"][data-squadron-id="' + id + '"]');
                const rank = rankInput && rankInput.value !== '' ? Number(rankInput.value) : null;
                const score = scoreInput && scoreInput.value !== '' ? Number(scoreInput.value) : null;
                const points = Number.isInteger(rank) && rank >= 1 && rank <= 10 ? 11 - rank : null;
                const pointCell = document.querySelector('[data-points-for="' + categoryId + '-' + id + '"]');
                if (pointCell) pointCell.textContent = points === null ? '—' : String(points);
                if (score === null || !Number.isFinite(score) || score < 0 || score > 100) bySquadron[id].complete = false;
                else bySquadron[id].scores.push(score);
            }
        });
        const rows = [];
        for (let id = 1; id <= 10; id++) {
            const item = bySquadron[id];
            const complete = item.complete && item.scores.length === categoryIds.length && categoryIds.length > 0;
            rows.push({ id: id, opa: complete ? item.scores.reduce((a,b)=>a+b,0) / item.scores.length : null, complete: complete });
        }
        rows.sort(function (a,b) {
            if (a.opa === null && b.opa === null) return a.id-b.id;
            if (a.opa === null) return 1;
            if (b.opa === null) return -1;
            return Math.abs(a.opa-b.opa) < 0.0000001 ? a.id-b.id : b.opa-a.opa;
        });
        let rank = 0, prior = null;
        rows.forEach(function (item,index) {
            const row = tableBody.querySelector('tr[data-squadron-id="' + item.id + '"]');
            if (!row) return;
            if (item.opa === null) {
                row.dataset.opa = '';
                row.querySelector('.projected-rank').textContent = '—';
                row.querySelector('.projected-opa').textContent = 'Incomplete';
                row.cells[3].textContent = 'Needs scores';
            } else {
                if (prior === null || Math.abs(item.opa-prior) >= 0.0000001) { rank=index+1; prior=item.opa; }
                row.dataset.opa = String(item.opa);
                row.querySelector('.projected-rank').textContent = '#' + rank;
                row.querySelector('.projected-opa').textContent = item.opa.toFixed(2) + '%';
                row.cells[3].textContent = 'Complete';
            }
            tableBody.appendChild(row);
        });
    }
    document.querySelectorAll('.score-recalculate').forEach(function (button) {
        button.addEventListener('click', function () {
            const form = document.getElementById('score-entry-form');
            const action = form.querySelector('input[name="action"]');
            action.value = 'recalculate_ranks';
            let category = form.querySelector('input[name="recalculate_category_id"]');
            if (!category) {
                category = document.createElement('input');
                category.type = 'hidden';
                category.name = 'recalculate_category_id';
                form.appendChild(category);
            }
            category.value = button.getAttribute('data-category-id');
            form.requestSubmit();
        });
    });
    inputs.forEach(input => input.addEventListener('input', calculate));
    calculate();
})();
</script>
</body>
</html>
