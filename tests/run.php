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

foreach(glob(__DIR__.'/../migrations/00[1-5]_*.php') as $file){$migration=require $file; $migration($db);}
check((bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='squadrons'")->fetchColumn(),'core schema migration creates squadrons');
check((bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='notification_subscriptions'")->fetchColumn(),'notification migration creates subscription table');
$cols=$db->query('PRAGMA table_info(intramural_games)')->fetchAll(PDO::FETCH_COLUMN,1);
check(in_array('sport_id',$cols,true),'intramural games has sport_id');

$tables=$db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
check(in_array('bracket_matchups',$tables,true),'bracket matchup table exists');
check(in_array('bracket_score_events',$tables,true),'bracket score-event link exists');
check(in_array('cadet_of_month_awards',$tables,true),'Cadet of the Month table exists');
check((bool)$db->query("SELECT 1 FROM event_type_config WHERE event_type='cadet_of_month'")->fetchColumn(),'Cadet of the Month event type is seeded');

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

@unlink($dataDir.'/brackets.json.legacy.bak');
@rmdir($dataDir);
if($failures){fwrite(STDERR,"{$failures} test(s) failed.\n");exit(1);}echo "All tests passed.\n";
