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
