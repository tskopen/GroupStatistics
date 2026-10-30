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
