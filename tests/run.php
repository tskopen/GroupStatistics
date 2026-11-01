<?php
/**
 * Lightweight test runner; intentionally dependency-free so it can run in
 * the same PHP environment as the application.
 */
$failures=0;
function check($condition,$message){global $failures;if(!$condition){$failures++;fwrite(STDERR,"FAIL: {$message}\n");}else{fwrite(STDOUT,"PASS: {$message}\n");}}

$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$dataDir=sys_get_temp_dir().'/groupstats-test-'.bin2hex(random_bytes(6));
mkdir($dataDir,0777,true);
define('DATA_DIR',$dataDir);

foreach(glob(__DIR__.'/../migrations/[0-9][0-9][0-9]_*.php') as $file){$migration=require $file; $migration($db);}

require_once __DIR__ . '/../lib/opa-scoring.php';
$squadronSeed = $db->prepare('INSERT OR IGNORE INTO squadrons(id,name,created_at) VALUES(?,?,?)');
for ($sid = 1; $sid <= 10; $sid++) $squadronSeed->execute([$sid, 'Test Squadron ' . $sid, date('c')]);
check((int)$db->query("SELECT COUNT(*) FROM scoring_categories WHERE is_active=1")->fetchColumn() === 3, 'OPA migration seeds AMI, SAMI, and PFT categories');

$categoryRows = opaGetCategories($db);
$draftId = opaCreateDraft($db, 'test-admin', 'OPA test draft');
$draftUpdate = $db->prepare('UPDATE scoring_draft_scores SET category_rank=?,category_score=?,updated_at=? WHERE draft_id=? AND category_id=? AND squadron_id=?');
foreach ($categoryRows as $category) {
    for ($sid = 1; $sid <= 10; $sid++) {
        $draftUpdate->execute([$sid, 100 - $sid, date('c'), $draftId, (int)$category['id'], $sid]);
    }
}
$draftValidation = opaValidateDraft($db, $draftId);
check(count($draftValidation['errors']) === 0, 'complete unique 1–10 category rankings and 0–100 scores pass publish validation');
$draftPreview = opaGetDraftPreview($db, $draftId);
check($draftPreview[0]['squadron_id'] === 1 && abs($draftPreview[0]['opa'] - 99.0) < 0.000001, 'OPA preview averages percentage scores across all active categories');
$firstPublication = opaPublishDraft($db, $draftId, 'test-admin');
$firstBoard = opaGetPublishedLeaderboard($db);
check(count($firstBoard) === 10 && (int)$firstBoard[0]['squadron_id'] === 1, 'published OPA leaderboard includes only Squadrons 1–10');
$scorePoints = $db->prepare('SELECT category_points FROM scoring_publication_scores WHERE publication_id=? AND category_key=? AND squadron_id=1');
$scorePoints->execute([$firstPublication['publication_id'], 'ami']);
check((float)$scorePoints->fetchColumn() === 10.0, 'category points use the 10-to-1 rank scale');
$breakdown = opaGetSquadronBreakdown($db, 1);
check($breakdown !== null && count($breakdown['categories']) === 3, 'published squadron breakdown returns category ranks and scores');

$secondDraft = opaCreateDraft($db, 'test-admin', 'Second OPA test draft');
foreach ($categoryRows as $category) {
    for ($sid = 1; $sid <= 10; $sid++) {
        $draftUpdate->execute([11 - $sid, 89 + $sid, date('c'), $secondDraft, (int)$category['id'], $sid]);
    }
}
$secondPublication = opaPublishDraft($db, $secondDraft, 'test-admin');
$secondBoard = opaGetPublishedLeaderboard($db);
$squadronOne = null;
foreach ($secondBoard as $entry) if ((int)$entry['squadron_id'] === 1) $squadronOne = $entry;
check($squadronOne !== null && (int)$squadronOne['rank_change'] === -9 && abs((float)$squadronOne['opa_change'] + 9.0) < 0.000001, 'publication snapshots capture OPA and overall rank changes');
$immutableSnapshot = false;
try {
    $db->prepare('UPDATE scoring_publications SET published_by=? WHERE id=?')->execute(['changed', $firstPublication['publication_id']]);
} catch (PDOException $e) {
    $immutableSnapshot = str_contains($e->getMessage(), 'immutable');
}
check($immutableSnapshot, 'published scoring snapshot rows are append-only');

$comparison12 = opaGetSquadronPublicationComparison($db, 1, $firstPublication['publication_id'], $secondPublication['publication_id']);
check($comparison12 !== null && (int)$comparison12['publication_a']['publication_number'] === 1 && (int)$comparison12['publication_b']['publication_number'] === 2, 'OPA comparison accepts explicit P1 versus P2 selections');
check(abs($comparison12['overall']['change']['opa'] + 9.0) < 0.000001 && (int)$comparison12['overall']['change']['rank_movement'] === -9, 'P1 versus P2 calculates OPA and rank movement directly between selected periods');
$amiComparison12 = null;
foreach ($comparison12['categories'] as $categoryComparison) if ($categoryComparison['key'] === 'ami') $amiComparison12 = $categoryComparison;
check($amiComparison12 !== null && (int)$amiComparison12['a']['rank'] === 1 && (int)$amiComparison12['b']['rank'] === 10 && abs($amiComparison12['change']['score'] + 9.0) < 0.000001 && (int)$amiComparison12['change']['points'] === -9, 'category details compare Group 1 rank, percentage score, and category points');

$thirdDraft = opaCreateDraft($db, 'test-admin', 'Third OPA test draft');
foreach ($categoryRows as $category) {
    for ($sid = 1; $sid <= 10; $sid++) {
        $draftUpdate->execute([11 - $sid, 85 + $sid, date('c'), $thirdDraft, (int)$category['id'], $sid]);
    }
}
$thirdPublication = opaPublishDraft($db, $thirdDraft, 'test-admin');
$comparison13 = opaGetSquadronPublicationComparison($db, 1, $firstPublication['publication_id'], $thirdPublication['publication_id']);
check($comparison13 !== null && (int)$comparison13['publication_a']['publication_number'] === 1 && (int)$comparison13['publication_b']['publication_number'] === 3, 'OPA comparison supports non-adjacent P1 versus P3 selections');
check(abs($comparison13['overall']['change']['opa'] + 13.0) < 0.000001 && (int)$comparison13['overall']['change']['rank_movement'] === -9, 'P1 versus P3 movement is calculated against P1 rather than P2');
$comparisonReverse = opaGetSquadronPublicationComparison($db, 1, $thirdPublication['publication_id'], $firstPublication['publication_id']);
check($comparisonReverse !== null && abs($comparisonReverse['overall']['change']['opa'] - 13.0) < 0.000001, 'publication comparison direction reverses when the A and B selectors are swapped');
$allSquadronComparison = opaGetPublicationLeaderboardComparison($db, $firstPublication['publication_id'], $thirdPublication['publication_id']);
$squadronOneOverallComparison = null;
foreach ($allSquadronComparison['squadrons'] as $comparisonRow) {
    if ((int)$comparisonRow['squadron_id'] === 1) $squadronOneOverallComparison = $comparisonRow;
}
check($allSquadronComparison['publication_a']['label'] === 'P1'
    && $allSquadronComparison['publication_b']['label'] === 'P3'
    && count($allSquadronComparison['squadrons']) === 10,
    'publication history comparison returns every squadron for non-adjacent P1 versus P3 selections');
check($squadronOneOverallComparison !== null
    && abs((float)$squadronOneOverallComparison['opa_change'] + 13.0) < 0.000001
    && (int)$squadronOneOverallComparison['rank_movement'] === -9,
    'all-squadron comparison calculates cumulative OPA and rank movement directly between selected publications');
$categoryComparison13 = opaGetPublicationCategoryComparison($db, $firstPublication['publication_id'], $thirdPublication['publication_id']);
$amiGroupComparison = null;
foreach ($categoryComparison13 as $categoryComparison) if ($categoryComparison['key'] === 'ami') $amiGroupComparison = $categoryComparison;
$amiSquadronOne = null;
foreach (($amiGroupComparison['squadrons'] ?? []) as $categoryRow) {
    if ((int)$categoryRow['squadron_id'] === 1) $amiSquadronOne = $categoryRow;
}
check(count($categoryComparison13) === 3 && $amiGroupComparison !== null
    && count($amiGroupComparison['squadrons']) === 10,
    'publication history category comparison includes every category and all ten squadrons');
check($amiSquadronOne !== null
    && (int)$amiSquadronOne['a']['rank'] === 1
    && (int)$amiSquadronOne['b']['rank'] === 10
    && abs((float)$amiSquadronOne['change']['score'] + 13.0) < 0.000001
    && (float)$amiSquadronOne['change']['points'] === -9.0
    && (int)$amiSquadronOne['change']['rank_movement'] === -9,
    'P1 to P3 category comparison calculates rank, percentage score, and category point deltas directly across the selected periods');
$categoryComparisonReverse = opaGetPublicationCategoryComparison($db, $thirdPublication['publication_id'], $firstPublication['publication_id']);
$reverseAmi = null;
foreach ($categoryComparisonReverse as $categoryComparison) if ($categoryComparison['key'] === 'ami') $reverseAmi = $categoryComparison;
$reverseSquadronOne = $reverseAmi['squadrons'][0] ?? null;
check($reverseSquadronOne !== null
    && abs((float)$reverseSquadronOne['change']['score'] - 13.0) < 0.000001
    && (float)$reverseSquadronOne['change']['points'] === 9.0,
    'category comparison deltas reverse when publication A and B are swapped');

$recentOpaHistory = opaGetRecentOpaHistoryBySquadron($db, 2);
$squadronOneHistory = $recentOpaHistory[1] ?? [];
check(count($squadronOneHistory) === 2
    && $squadronOneHistory[0]['label'] === 'P2'
    && $squadronOneHistory[1]['label'] === 'P3'
    && abs((float)$squadronOneHistory[1]['opa'] - 86.0) < 0.000001,
    'main-board OPA history keeps the latest publications in chronological order with stable P labels');



$sparseDraftId = opaCreateDraft($db, 'test-admin', 'Single-category update');
$sparseRows = opaGetDraftRows($db, $sparseDraftId);
$latestRows = $db->prepare('SELECT category_key,squadron_id,category_rank,category_score FROM scoring_publication_scores WHERE publication_id=?');
$latestRows->execute([$thirdPublication['publication_id']]);
$latestByCategory = [];
foreach ($latestRows->fetchAll(PDO::FETCH_ASSOC) as $row) $latestByCategory[$row['category_key']][(int)$row['squadron_id']] = $row;
$amiCategoryForSparse = null;
foreach ($categoryRows as $category) if ($category['category_key'] === 'ami') $amiCategoryForSparse = $category;
$allInherited = true;
foreach ($categoryRows as $category) {
    for ($sid = 1; $sid <= 10; $sid++) {
        $expected = $latestByCategory[$category['category_key']][$sid] ?? null;
        $actual = $sparseRows[(int)$category['id']][$sid] ?? [];
        if (!$expected || (int)$actual['category_rank'] !== (int)$expected['category_rank']
            || abs((float)$actual['category_score'] - (float)$expected['category_score']) > 0.0000001) $allInherited = false;
    }
}
check($allInherited, 'new drafts automatically inherit the latest published ranks and percentages for every category');
for ($sid = 1; $sid <= 10; $sid++) {
    $draftUpdate->execute([$sid, 75 + $sid, date('c'), $sparseDraftId, (int)$amiCategoryForSparse['id'], $sid]);
}
$sparseStatuses = opaGetDraftCategoryStatuses($db, $sparseDraftId);
check(($sparseStatuses[(int)$amiCategoryForSparse['id']] ?? '') === 'updated'
    && ($sparseStatuses[(int)$categoryRows[1]['id']] ?? '') === 'unchanged',
    'draft status distinguishes the one updated category from categories carried forward unchanged');
$sparseDescription = 'AMI results updated after the latest competition.';
$sparsePublication = opaPublishDraft($db, $sparseDraftId, 'test-admin', $sparseDescription);
$carryForwardStmt = $db->prepare('SELECT category_rank,category_score FROM scoring_publication_scores WHERE publication_id=? AND category_key=? AND squadron_id=?');
$carryForwardWorks = true;
foreach (['sami', 'pft'] as $unchangedKey) {
    for ($sid = 1; $sid <= 10; $sid++) {
        $carryForwardStmt->execute([$thirdPublication['publication_id'], $unchangedKey, $sid]);
        $before = $carryForwardStmt->fetch(PDO::FETCH_ASSOC);
        $carryForwardStmt->execute([$sparsePublication['publication_id'], $unchangedKey, $sid]);
        $after = $carryForwardStmt->fetch(PDO::FETCH_ASSOC);
        if (!$before || !$after || (int)$before['category_rank'] !== (int)$after['category_rank']
            || abs((float)$before['category_score'] - (float)$after['category_score']) > 0.0000001) $carryForwardWorks = false;
    }
}
check($carryForwardWorks, 'publishing one updated category preserves every unchanged category from the previous publication');
$descriptionStmt = $db->prepare('SELECT description FROM scoring_publications WHERE id=?');
$descriptionStmt->execute([$sparsePublication['publication_id']]);
check($descriptionStmt->fetchColumn() === $sparseDescription, 'publication description is stored with the immutable snapshot');
$sparseComparison = opaGetSquadronPublicationComparison($db, 1, $thirdPublication['publication_id'], $sparsePublication['publication_id']);
check($sparseComparison !== null && $sparseComparison['publication_b']['description'] === $sparseDescription,
    'publication descriptions are included in squadron comparison data');

$amiCategory = null;
foreach ($categoryRows as $category) if ($category['category_key'] === 'ami') $amiCategory = $category;
$importRows = [['row_number' => 1, 'cells' => [0 => 'Squadron', 1 => 'Source Rank', 2 => 'Percentage Score']]];
$originalRanks = [1=>14,2=>7,3=>22,4=>3,5=>18,6=>6,7=>11,8=>2,9=>32,10=>28,11=>1];
foreach ($originalRanks as $sid => $originalRank) {
    $importRows[] = ['row_number' => $sid + 1, 'cells' => [0 => 'Squadron ' . $sid, 1 => (string)$originalRank, 2 => '95.0']];
}
$importPreview = opaBuildImportPreview([['name'=>'AMI','rows'=>$importRows]], $categoryRows);
$importedAmi = $importPreview['records'][(int)$amiCategory['id']] ?? [];
check(count($importedAmi) === 10 && !isset($importedAmi[11]), 'Excel-style import filtering ignores Squadrons 11–40 by identifier');
check(isset($importedAmi[8]) && (int)$importedAmi[8]['category_rank'] === 1 && (int)$importedAmi[2]['category_rank'] === 4, 'source 1–40 rankings are recalculated as Group 1 relative rankings');
$db->prepare("UPDATE scoring_categories SET ranking_direction='higher' WHERE id=?")->execute([(int)$amiCategory['id']]);
$categoryRows = opaGetCategories($db);
$percentOnlyRows = [['row_number' => 1, 'cells' => [0 => 'Squadron', 1 => 'Percentage Score']]];
for ($sid = 1; $sid <= 10; $sid++) $percentOnlyRows[] = ['row_number' => $sid + 1, 'cells' => [0 => 'Squadron ' . $sid, 1 => (string)(80 + $sid)]];
$percentOnlyPreview = opaBuildImportPreview([['name'=>'AMI','rows'=>$percentOnlyRows]], $categoryRows);
$percentOnlyRecords = $percentOnlyPreview['records'][(int)$amiCategory['id']] ?? [];
check(isset($percentOnlyRecords[10]) && (int)$percentOnlyRecords[10]['category_rank'] === 1 && (int)$percentOnlyRecords[1]['category_rank'] === 10, 'percentage performance values can determine relative ranks when higher-is-better is configured');
$rankDraftId = opaCreateDraft($db, 'test-admin', 'Manual rank recalculation');
$rankScoreUpdate = $db->prepare('UPDATE scoring_draft_scores SET category_score=?,updated_at=? WHERE draft_id=? AND category_id=? AND squadron_id=?');
for ($sid = 1; $sid <= 10; $sid++) $rankScoreUpdate->execute([$sid * 8, date('c'), $rankDraftId, (int)$amiCategory['id'], $sid]);
opaRecalculateDraftRanks($db, $rankDraftId, (int)$amiCategory['id']);
$rankCheck = $db->prepare('SELECT category_rank FROM scoring_draft_scores WHERE draft_id=? AND category_id=? AND squadron_id=10');
$rankCheck->execute([$rankDraftId, (int)$amiCategory['id']]);
check((int)$rankCheck->fetchColumn() === 1, 'admin recalculation ranks percentage scores using the configured category direction');
$provenanceDraftId = opaCreateDraft($db, 'test-admin', 'Field provenance test');
$provenancePreview = ['records' => [(int)$amiCategory['id'] => [1 => [
    'category_rank' => 1, 'category_score' => null, 'source_rank' => 14, 'source_measurement' => null,
]]]];
opaApplyImportPreview($db, $provenanceDraftId, $provenancePreview, 'provenance.xlsx', hash('sha256', 'field-provenance-test'), 'test-admin');
$provenanceStmt = $db->prepare('SELECT rank_source,score_source FROM scoring_draft_scores WHERE draft_id=? AND category_id=? AND squadron_id=1');
$provenanceStmt->execute([$provenanceDraftId, (int)$amiCategory['id']]);
$provenance = $provenanceStmt->fetch(PDO::FETCH_ASSOC);
check($provenance['rank_source'] === 'import' && $provenance['score_source'] === 'manual', 'rank and percentage provenance are tracked independently when only one field is imported');



check((bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='squadrons'")->fetchColumn(),'core schema migration creates squadrons');
check((bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='notification_subscriptions'")->fetchColumn(),'notification migration creates subscription table');
check((bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='group_announcements'")->fetchColumn(),'announcement migration creates separate announcements table');
$announcementInsert = $db->prepare("INSERT INTO group_announcements(title,description,status,created_at,updated_at) VALUES(?,?,?,?,?)");
$announcementInsert->execute(['Test event','Announcement body','draft','2026-10-28 12:00:00','2026-10-28 12:00:00']);
$announcementId = (int)$db->lastInsertId();
check($announcementId > 0,'announcement table supports creating a draft');
$publicCount = (int)$db->query("SELECT COUNT(*) FROM group_announcements WHERE status='published'")->fetchColumn();
check($publicCount === 0,'draft announcements remain hidden from public listing queries');
$db->prepare("UPDATE group_announcements SET status='published' WHERE id=?")->execute([$announcementId]);
check((int)$db->query("SELECT COUNT(*) FROM group_announcements WHERE status='published'")->fetchColumn() === 1,'announcement can be published');
$db->prepare("DELETE FROM group_announcements WHERE id=?")->execute([$announcementId]);
check((int)$db->query("SELECT COUNT(*) FROM group_announcements")->fetchColumn() === 0,'announcement can be permanently deleted');
$cols=$db->query('PRAGMA table_info(intramural_games)')->fetchAll(PDO::FETCH_COLUMN,1);
check(in_array('sport_id',$cols,true),'intramural games has sport_id');

$tables=$db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
check(in_array('bracket_matchups',$tables,true),'bracket matchup table exists');
check(in_array('bracket_score_events',$tables,true),'bracket score-event link exists');
check(in_array('cadet_of_month_awards',$tables,true),'Cadet of the Month table exists');
check((bool)$db->query("SELECT 1 FROM event_type_config WHERE event_type='cadet_of_month'")->fetchColumn(),'Cadet of the Month event type is seeded');

$directorySections = $db->query("SELECT id, name FROM directory_sections ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
check(count($directorySections) >= 4, 'Meet the Group migration seeds the starter sections');
$sectionId = (int)$directorySections[0]['id'];
$now = date('Y-m-d H:i:s');
$personStmt = $db->prepare("INSERT INTO directory_people(display_name, bio, contact, created_at, updated_at) VALUES(?,?,?,?,?)");
$personStmt->execute(['Directory Test Person', 'Test profile', '', $now, $now]);
$personId = (int)$db->lastInsertId();
$roleStmt = $db->prepare("INSERT INTO directory_roles(section_id, person_id, title, responsibilities, sort_order, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?)");
$roleStmt->execute([$sectionId, $personId, 'Test Role', 'Test responsibilities', 1, 'draft', $now, $now]);
$roleId = (int)$db->lastInsertId();
check($roleId > 0, 'directory supports creating a role assignment for a profile');
check((int)$db->query("SELECT COUNT(*) FROM directory_roles WHERE status='published'")->fetchColumn() === 0, 'directory drafts are excluded from public role listings');
$db->prepare("UPDATE directory_roles SET status='published' WHERE id=?")->execute([$roleId]);
check((int)$db->query("SELECT COUNT(*) FROM directory_roles WHERE status='published'")->fetchColumn() === 1, 'directory role can be published');
$db->prepare("UPDATE directory_roles SET section_id=? WHERE id=?")->execute([(int)$directorySections[1]['id'], $roleId]);
check((int)$db->query("SELECT section_id FROM directory_roles WHERE id=" . $roleId)->fetchColumn() === (int)$directorySections[1]['id'], 'directory role can be moved to another section');
$db->prepare("DELETE FROM directory_roles WHERE id=?")->execute([$roleId]);
check((int)$db->query("SELECT COUNT(*) FROM directory_roles WHERE id=" . $roleId)->fetchColumn() === 0, 'directory role can be removed without deleting the profile');


// Regression: production already had relational bracket rounds when migration
// 004 ran. Legacy JSON import must reuse those rounds rather than inserting a
// duplicate (bracket_id, round_number) row and taking the whole site down.
$db->exec("INSERT INTO brackets(id,name,created_date,updated_at,bracket_type) VALUES('legacy-test','Existing','2026-01-01','2026-01-01','multi_round')");
$db->exec("INSERT INTO bracket_rounds(bracket_id,round_number,name,created_at) VALUES('legacy-test',1,'Round 1','2026-01-01')");
file_put_contents($dataDir.'/brackets.json',json_encode([[
    'id'=>'legacy-test','name'=>'Existing','rounds'=>[[
        'name'=>'Round 1','matchups'=>[[
            'id'=>'legacy-match-1','team1_id'=>1,'team2_id'=>2,'status'=>'upcoming'
        ]]
    ]]
]]));
$legacyMigration=require __DIR__.'/../migrations/004_legacy_import.php';
$legacyMigration($db);
$roundCount=(int)$db->query("SELECT COUNT(*) FROM bracket_rounds WHERE bracket_id='legacy-test' AND round_number=1")->fetchColumn();
$matchCount=(int)$db->query("SELECT COUNT(*) FROM bracket_matchups WHERE bracket_id='legacy-test'")->fetchColumn();
check($roundCount===1,'legacy import reuses an existing bracket round');
check($matchCount===1,'legacy import adds the missing matchup without duplicating the round');
check(is_file($dataDir.'/brackets.json.legacy.bak'),'legacy bracket JSON is archived after successful import');


// Regression: inspect a real XLSX package structure like the Group 1 workbook.
// Its headers are stored in sharedStrings.xml, rankings contain Excel #N/A error
// cells, and percentage averages are numeric fractions using built-in percent style 10.
if (class_exists('ZipArchive') && function_exists('simplexml_load_string')) {
    $xlsxHeaders = [
        'Squadron', 'MAE Ranking', 'Marching Ranking', 'SAMI Ranking', 'AMI Ranking',
        'PAI Ranking', 'CAI Ranking', 'PP No-Notice AMI Ranking',
        'Commissioning Education Ranking', 'Knowledge Testing Ranking',
        'Group Commander', 'Spot Discrepancies', 'Major Assessment Event Avg',
        'Marching Avg', 'SAMI Avg', 'AMI Avg', 'PAI Avg', 'CAI Avg',
        'PP No-Notice AMI Avg', 'Commissioning Education Avg',
        'Knowledge Testing Avg', 'Group Commander Avg', 'Spot Discrepancies Avg',
    ];
    $wideCategoryDefinitions = [
        [101, 'mae', 'MAE'],
        [102, 'marching', 'Marching'],
        [103, 'sami', 'SAMI'],
        [104, 'ami', 'AMI'],
        [105, 'pai', 'PAI'],
        [106, 'cai', 'CAI'],
        [107, 'pp-no-notice-ami', 'PP No-Notice AMI'],
        [108, 'commissioning-education', 'Commissioning Education'],
        [109, 'knowledge-testing', 'Knowledge Testing'],
        [110, 'group-commander', 'Group Commander'],
        [111, 'spot-discrepancies', 'Spot Discrepancies'],
    ];
    $wideTestCategories = [];
    foreach ($wideCategoryDefinitions as [$id, $key, $name]) {
        $wideTestCategories[] = [
            'id' => $id, 'category_key' => $key, 'display_name' => $name,
            'is_active' => 1, 'ranking_direction' => 'manual',
        ];
    }

    $samiRanks = [4, 35, 3, 10, 8, 20, 9, 19, 34, 18];
    $amiRanks = [3, 9, 26, 19, 15, 13, 1, 36, 14, 35];
    $paiRanks = [38, 29, 25, 31, 2, 4, 19, 21, 1, 24];
    $commissioningRanks = [26, 13, 8, 2, 19, 4, 9, 5, 29, 37];
    $knowledgeRanks = [4, 7, 38, 15, 25, 11, 29, 37, 2, 18];
    $samiScores = [.9746, .9544, .9747, .9732, .9734, .9653, .9733, .9667, .9549, .9669];
    $amiScores = [.9542, .9467, .9298, .9348, .9404, .9413, .9606, .9197, .9412, .9219];
    $paiScores = [.9825, .9906, .9911, .9906, .9984, .9979, .9932, .9919, .9996, .9912];
    $commissioningScores = [.9261, .9321, .9340, .9431, .9282, .9400, .9331, .9370, .9241, .9147];
    $knowledgeScores = [.9153, .9103, .8791, .8982, .8882, .9027, .8849, .8794, .9234, .8953];

    $xlsxRows = [];
    for ($sid = 1; $sid <= 10; $sid++) {
        $i = $sid - 1;
        $xlsxRows[] = [
            $sid, '#N/A', '#N/A', $samiRanks[$i], $amiRanks[$i], $paiRanks[$i],
            '#N/A', '#N/A', $commissioningRanks[$i], $knowledgeRanks[$i],
            '#N/A', '#N/A', null, null, $samiScores[$i], $amiScores[$i],
            $paiScores[$i], null, null, $commissioningScores[$i],
            $knowledgeScores[$i], null, null,
        ];
    }
    // Include an out-of-scope row, as the uploaded workbook contains Squadrons 1–40.
    $xlsxRows[] = [11, '#N/A', '#N/A', 29, 12, 35, '#N/A', '#N/A', 39, 16,
        '#N/A', '#N/A', null, null, .9575, .9421, .9891, null, null, .9081, .8969, null, null];

    $xlsxStrings = array_merge($xlsxHeaders, ['Original screenshot — preserved at full resolution']);
    $xlsxStringXml = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($xlsxStrings) . '" uniqueCount="' . count($xlsxStrings) . '">';
    foreach ($xlsxStrings as $string) {
        $xlsxStringXml .= '<si><t>' . htmlspecialchars($string, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
    }
    $xlsxStringXml .= '</sst>';

    $columnName = static function (int $index): string {
        $name = '';
        while ($index >= 0) {
            $name = chr(($index % 26) + 65) . $name;
            $index = intdiv($index, 26) - 1;
        }
        return $name;
    };
    $worksheetRows = [];
    $headerCells = [];
    foreach ($xlsxHeaders as $column => $_header) {
        $cellRef = $columnName($column) . '1';
        $headerCells[] = '<c r="' . $cellRef . '" t="s"><v>' . $column . '</v></c>';
    }
    $worksheetRows[] = '<row r="1">' . implode('', $headerCells) . '</row>';
    foreach ($xlsxRows as $rowIndex => $values) {
        $excelRow = $rowIndex + 2;
        $cells = [];
        foreach ($values as $column => $value) {
            $cellRef = $columnName($column) . $excelRow;
            if ($value === null) {
                $cells[] = '<c r="' . $cellRef . '"/>';
            } elseif ($value === '#N/A') {
                $cells[] = '<c r="' . $cellRef . '" t="e"><v>#N/A</v></c>';
            } else {
                $style = ($column >= 14 && $column <= 20) ? ' s="1"' : '';
                $cells[] = '<c r="' . $cellRef . '"' . $style . '><v>' . (string)$value . '</v></c>';
            }
        }
        $worksheetRows[] = '<row r="' . $excelRow . '">' . implode('', $cells) . '</row>';
    }
    $sheetOneXml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . implode('', $worksheetRows) . '</sheetData></worksheet>';
    $sheetTwoXml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>' . count($xlsxHeaders) . '</v></c></row>'
        . '</sheetData></worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Editable Table" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Screenshot" sheetId="2" r:id="rId2"/></sheets></workbook>';
    $relationshipsXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';
    $stylesXml = '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="0"/><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="10"/></cellXfs>'
        . '</styleSheet>';

    $xlsxPath = tempnam(sys_get_temp_dir(), 'opa-xlsx-');
    $zip = new ZipArchive();
    $opened = $zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened === true) {
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $relationshipsXml);
        $zip->addFromString('xl/sharedStrings.xml', $xlsxStringXml);
        $zip->addFromString('xl/styles.xml', $stylesXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetOneXml);
        $zip->addFromString('xl/worksheets/sheet2.xml', $sheetTwoXml);
        $zip->close();

        try {
            $decodedWorkbook = opaReadXlsxWorkbook($xlsxPath);
            $decodedHeaders = $decodedWorkbook[0]['rows'][0]['cells'] ?? [];
            check(($decodedHeaders[0] ?? null) === 'Squadron'
                && ($decodedHeaders[1] ?? null) === 'MAE Ranking'
                && ($decodedHeaders[22] ?? null) === 'Spot Discrepancies Avg',
                'XLSX reader resolves shared-string headers from the Editable Table sheet');
            check(($decodedWorkbook[0]['rows'][1]['cells'][14] ?? null) === '97.46%',
                'XLSX reader converts styled fractional averages into readable percentages');

            check((opaMatchWideCategory('MAE', $wideTestCategories)['id'] ?? null) === 101
                && (opaMatchWideCategory('Major Assessment Event', $wideTestCategories)['id'] ?? null) === 101,
                'wide category aliases match MAE and Major Assessment Event symmetrically');

            $widePreview = opaBuildImportPreview($decodedWorkbook, $wideTestCategories);
            $samiRecords = $widePreview['records'][103] ?? [];
            $amiRecords = $widePreview['records'][104] ?? [];
            $paiRecords = $widePreview['records'][105] ?? [];
            $commissioningRecords = $widePreview['records'][108] ?? [];
            $knowledgeRecords = $widePreview['records'][109] ?? [];
            check($widePreview['identified_sheets'] === 1
                && count($samiRecords) === 10
                && count($amiRecords) === 10
                && count($paiRecords) === 10
                && count($commissioningRecords) === 10
                && count($knowledgeRecords) === 10,
                'uploaded Group 1 workbook layout is identified and imports all ten eligible squadrons');
            check(abs((float)$samiRecords[1]['category_score'] - 97.46) < 0.000001
                && abs((float)$amiRecords[1]['category_score'] - 95.42) < 0.000001
                && abs((float)$paiRecords[1]['category_score'] - 98.25) < 0.000001
                && abs((float)$commissioningRecords[1]['category_score'] - 92.61) < 0.000001
                && abs((float)$knowledgeRecords[1]['category_score'] - 91.53) < 0.000001,
                'Group 1 workbook imports source averages as 0–100 percentage scores');
            check((int)$samiRecords[3]['category_rank'] === 1
                && (int)$amiRecords[7]['category_rank'] === 1
                && !isset($samiRecords[11]),
                'source ranks are recalculated within Squadrons 1–10 and exclude Squadron 11–40 data');
        } catch (Throwable $e) {
            check(false, 'generated Group 1 XLSX fixture is readable: ' . $e->getMessage());
        } finally {
            @unlink($xlsxPath);
        }
    } else {
        @unlink($xlsxPath);
        check(false, 'generated Group 1 XLSX fixture can be created');
    }
} else {
    check(false, 'PHP ZipArchive and SimpleXML extensions are installed for XLSX reader tests');
}

// Published publication correction tests: edits recalculate dependent records in later
// publications, retain an audit trail, and re-enable immutable snapshot triggers.
$firstPublicationId = (int)$firstPublication['publication_id'];
$secondPublicationId = (int)$secondPublication['publication_id'];
$thirdPublicationId = (int)$thirdPublication['publication_id'];
$correctionData = opaGetPublicationEditData($db, $firstPublicationId);
$correctionSubmitted = [];
$expectedCorrectedOpa = 0.0;
$correctionCategoryCount = count($correctionData['categories'] ?? []);
foreach (($correctionData['categories'] ?? []) as $correctionCategory) {
    $key = $correctionCategory['category_key'];
    foreach ($correctionCategory['rows'] as $sid => $row) {
        $correctionSubmitted[$key][$sid] = [
            'rank' => (int)$row['category_rank'],
            'score' => (float)$row['category_score'],
        ];
    }
    if ($key === 'ami') {
        $rankOne = $correctionSubmitted[$key][1]['rank'];
        $rankTwo = $correctionSubmitted[$key][2]['rank'];
        $correctionSubmitted[$key][1]['rank'] = $rankTwo;
        $correctionSubmitted[$key][2]['rank'] = $rankOne;
        $correctionSubmitted[$key][1]['score'] += 0.25;
    }
}
for ($sid = 1; $sid <= 10; $sid++) {
    $scoreTotal = 0.0;
    foreach ($correctionSubmitted as $categoryValues) $scoreTotal += (float)$categoryValues[$sid]['score'];
    $expectedCorrectedOpa = $sid === 1 ? $scoreTotal / max(1, $correctionCategoryCount) : $expectedCorrectedOpa;
}
$correctionReason = 'Corrected a transcription error in the first publication.';
opaCorrectPublication($db, $firstPublicationId, $correctionSubmitted, 'Corrected first publication', $correctionReason, 'test-admin');
$correctedAmiStmt = $db->prepare('SELECT category_rank,category_score,category_points FROM scoring_publication_scores WHERE publication_id=? AND category_key=? AND squadron_id=1');
$correctedAmiStmt->execute([$firstPublicationId, 'ami']);
$correctedAmi = $correctedAmiStmt->fetch(PDO::FETCH_ASSOC);
$correctedOpaStmt = $db->prepare('SELECT opa FROM scoring_publication_squadrons WHERE publication_id=? AND squadron_id=1');
$correctedOpaStmt->execute([$firstPublicationId]);
$correctedOpa = (float)$correctedOpaStmt->fetchColumn();
$nextPreviousOpaStmt = $db->prepare('SELECT previous_opa FROM scoring_publication_squadrons WHERE publication_id=? AND squadron_id=1');
$nextPreviousOpaStmt->execute([$secondPublicationId]);
$secondPublicationPreviousOpa = (float)$nextPreviousOpaStmt->fetchColumn();
check((int)$correctedAmi['category_rank'] === (int)$correctionSubmitted['ami'][1]['rank']
    && abs((float)$correctedAmi['category_score'] - (float)$correctionSubmitted['ami'][1]['score']) < 0.000001
    && (float)$correctedAmi['category_points'] === 11.0 - (float)$correctionSubmitted['ami'][1]['rank'],
    'published correction updates category rank, score, and points');
check(abs($correctedOpa - $expectedCorrectedOpa) < 0.000001
    && abs($secondPublicationPreviousOpa - $correctedOpa) < 0.000001,
    'published correction recalculates OPA and later publication movement baselines');
$auditEditStmt = $db->prepare("SELECT operation,reason,performed_by FROM scoring_publication_corrections WHERE publication_id=? ORDER BY id DESC LIMIT 1");
$auditEditStmt->execute([$firstPublicationId]);
$auditEdit = $auditEditStmt->fetch(PDO::FETCH_ASSOC);
check($auditEdit && $auditEdit['operation'] === 'edit'
    && $auditEdit['reason'] === $correctionReason
    && $auditEdit['performed_by'] === 'test-admin',
    'published correction records who changed the snapshot and why');
$immutabilityRestored = false;
try {
    $db->prepare('UPDATE scoring_publications SET published_by=? WHERE id=?')->execute(['blocked', $firstPublicationId]);
} catch (PDOException $e) {
    $immutabilityRestored = str_contains($e->getMessage(), 'immutable');
}
check($immutabilityRestored, 'publication immutability triggers are restored after a correction');

$deleteReason = 'This publication was duplicated and should be removed.';
opaDeletePublication($db, $secondPublicationId, $deleteReason, 'test-admin');
$remainingPublicationIds = $db->query('SELECT id FROM scoring_publications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$thirdPreviousOpaStmt = $db->prepare('SELECT previous_opa FROM scoring_publication_squadrons WHERE publication_id=? AND squadron_id=1');
$thirdPreviousOpaStmt->execute([$thirdPublicationId]);
$thirdPreviousOpa = (float)$thirdPreviousOpaStmt->fetchColumn();
$auditDeleteStmt = $db->prepare("SELECT operation,reason FROM scoring_publication_corrections WHERE publication_id=? ORDER BY id DESC LIMIT 1");
$auditDeleteStmt->execute([$secondPublicationId]);
$auditDelete = $auditDeleteStmt->fetch(PDO::FETCH_ASSOC);
check(!in_array($secondPublicationId, array_map('intval', $remainingPublicationIds), true)
    && count($remainingPublicationIds) === 3,
    'deleting a publication removes the snapshot and keeps the remaining history');
check(abs($thirdPreviousOpa - $correctedOpa) < 0.000001,
    'later publications are recalculated against the preceding surviving publication after deletion');
check($auditDelete && $auditDelete['operation'] === 'delete' && $auditDelete['reason'] === $deleteReason,
    'publication deletion remains recorded in the administrator audit trail');
$deleteImmutabilityRestored = false;
try {
    $db->prepare('DELETE FROM scoring_publications WHERE id=?')->execute([$firstPublicationId]);
} catch (PDOException $e) {
    $deleteImmutabilityRestored = str_contains($e->getMessage(), 'immutable');
}
check($deleteImmutabilityRestored, 'publication delete protection is restored after a controlled deletion');

// Web Push configuration regression checks. These use synthetic format fixtures
// only; they do not contain or access production credentials.
require_once __DIR__ . '/../vapid-config.php';
$oldVapidPublic = getenv('VAPID_PUBLIC_KEY');
$oldVapidPrivate = getenv('VAPID_PRIVATE_KEY');
$oldVapidSubject = getenv('VAPID_SUBJECT');
$oldDataDir = getenv('DATA_DIR');
putenv('DATA_DIR=' . $dataDir);
putenv('VAPID_PUBLIC_KEY');
putenv('VAPID_PRIVATE_KEY');
putenv('VAPID_SUBJECT=mailto:notification-test@example.test');

$missingVapidRejected = false;
try {
    loadVapidConfiguration();
} catch (RuntimeException $e) {
    $missingVapidRejected = true;
}
check($missingVapidRejected, 'push configuration rejects missing keys instead of generating demo credentials');

$testKeyObject = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
$testKeyDetails = $testKeyObject ? openssl_pkey_get_details($testKeyObject) : false;
$testPrivateKey = is_array($testKeyDetails) && isset($testKeyDetails['ec']['d'])
    ? rtrim(strtr(base64_encode($testKeyDetails['ec']['d']), '+/', '-_'), '=')
    : '';
$testPublicKey = is_array($testKeyDetails) && isset($testKeyDetails['ec']['x'], $testKeyDetails['ec']['y'])
    ? rtrim(strtr(base64_encode(chr(4) . $testKeyDetails['ec']['x'] . $testKeyDetails['ec']['y']), '+/', '-_'), '=')
    : '';
check($testPrivateKey !== '' && $testPublicKey !== '', 'test runner can create a synthetic P-256 key pair');

putenv('VAPID_PUBLIC_KEY=' . $testPublicKey);
putenv('VAPID_PRIVATE_KEY=' . $testPrivateKey);
$validPairAccepted = false;
try {
    $vapidConfig = loadVapidConfiguration();
    $validPairAccepted = $vapidConfig['publicKey'] === $testPublicKey
        && $vapidConfig['privateKey'] === $testPrivateKey
        && $vapidConfig['subject'] === 'mailto:notification-test@example.test';
} catch (Throwable $e) {
    $validPairAccepted = false;
}
check($validPairAccepted, 'push configuration accepts a matching P-256 key pair and contact subject');

$otherKeyObject = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
$otherKeyDetails = $otherKeyObject ? openssl_pkey_get_details($otherKeyObject) : false;
$otherPrivateKey = is_array($otherKeyDetails) && isset($otherKeyDetails['ec']['d'])
    ? rtrim(strtr(base64_encode($otherKeyDetails['ec']['d']), '+/', '-_'), '=')
    : '';
putenv('VAPID_PRIVATE_KEY=' . $otherPrivateKey);
$mismatchedPairRejected = false;
try {
    loadVapidConfiguration();
} catch (RuntimeException $e) {
    $mismatchedPairRejected = str_contains($e->getMessage(), 'same key pair');
}
check($mismatchedPairRejected, 'push configuration rejects mismatched public/private key values');

putenv('VAPID_PRIVATE_KEY=' . $testPrivateKey);
$publishedDemoKeyRejected = false;
putenv('VAPID_PUBLIC_KEY=BOEd7Z3e-DesqeCznrwgIHe6o9o-M_bROcmhfJxkqOo6WNlIOPxQnYOq6Tp_RdGq9aBxvFlyOtMDLJ2e5r6hUWA');
try {
    loadVapidConfiguration();
} catch (RuntimeException $e) {
    $publishedDemoKeyRejected = true;
}
check($publishedDemoKeyRejected, 'push configuration rejects the previously published demo public key');

foreach ([
    'VAPID_PUBLIC_KEY' => $oldVapidPublic,
    'VAPID_PRIVATE_KEY' => $oldVapidPrivate,
    'VAPID_SUBJECT' => $oldVapidSubject,
    'DATA_DIR' => $oldDataDir,
] as $name => $value) {
    if ($value === false) {
        putenv($name);
    } else {
        putenv($name . '=' . $value);
    }
}

@unlink($dataDir.'/brackets.json.legacy.bak');
@rmdir($dataDir);
if($failures){fwrite(STDERR,"{$failures} test(s) failed.\n");exit(1);}echo "All tests passed.\n";
