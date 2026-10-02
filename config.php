<?php
/**
 * Application bootstrap/configuration.
 *
 * Runtime data is SQLite-backed. Database schema changes belong in the
 * versioned migrations/ directory; this file intentionally contains no
 * DROP/CREATE repair logic and no legacy score/bracket JSON restoration.
 */

define('DATA_DIR', '/data');
if (!defined('IMAGES_DIR')) define('IMAGES_DIR', DATA_DIR . '/images');
if (!defined('DB_PATH')) define('DB_PATH', DATA_DIR . '/squadron-tracker.db');

require_once __DIR__ . '/database-migrations.php';
require_once __DIR__ . '/security.php';

function normalizeEventType($eventType) {
    $eventType = strtolower(trim((string)$eventType));
    return $eventType !== '' ? $eventType : 'other';
}

function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    return $pdo;
}

function initDatabase() {
    $db = getDb();
    runDatabaseMigrations($db);
    seedDefaultConfig();
    normalizeLegacyEventRows($db);
}

function normalizeLegacyEventRows(PDO $db): void {
    $db->exec("UPDATE events SET event_type=LOWER(TRIM(event_type)) WHERE event_type IS NOT NULL AND TRIM(event_type)<>''");
    $db->exec("UPDATE events SET event_type='other' WHERE event_type IS NULL OR TRIM(event_type)=''");
    $db->exec("UPDATE events SET event_name='Event' WHERE event_name IS NULL OR TRIM(event_name)=''");
    $db->exec("UPDATE events SET points_awarded=value WHERE value IS NOT NULL AND (points_awarded IS NULL OR points_awarded=0)");
}

function seedDefaultConfig() {
    $db = getDb();
    $defaults = [
        ['samis', 'SAMIS Scores', 'Weekly SAMIs', '📊'],
        ['pft', 'Physical Fitness Test', 'PFT scores', '💪'],
        ['other', 'Other Event', 'Miscellaneous points', '📌'],
        ['bracket', 'Bracket Tournament', 'Tournament bracket event', '🏆'],
        ['intramural', 'Intramural', 'Intramural game results', '🏀'],
    ];
    $stmt = $db->prepare('INSERT OR IGNORE INTO event_type_config(event_type,display_name,description,emoji) VALUES(?,?,?,?)');
    foreach ($defaults as $row) $stmt->execute($row);
    $stmt = $db->prepare('INSERT OR IGNORE INTO admin_config(key,value) VALUES(?,?)');
    foreach (['intramural_win_points'=>'5','intramural_loss_points'=>'-1','intramural_bonus_0_6_points'=>'10'] as $key=>$value) $stmt->execute([$key,$value]);
}

function initDataStore() {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    if (!is_dir(IMAGES_DIR)) @mkdir(IMAGES_DIR, 0775, true);
}
initDataStore();
initDatabase();

function restoreDefaultSquadrons() {
    $db = getDb();
    $count = (int)$db->query('SELECT COUNT(*) FROM squadrons')->fetchColumn();
    if ($count > 0) return;
    $rows = [
        [1,'Mighty Mach One','Symbolized by the griffin and the Maltese Cross, representing strength, vigilance, and a long tradition of honor.'],
        [2,'Deuce','Represented by red, white, and blue contrails streaking toward space, symbolizing speed, patriotism, and the reach beyond the atmosphere.'],
        [3,'Dogs of War','Embodied by Cerberus and flames, symbolizing ferocity, guardianship, and relentless fighting spirit.'],
        [4,"Fightin' Fourth",'Represented by a prop and wings alongside four classes united, symbolizing aviation heritage and squadron unity across all four years.'],
        [5,'Wolfpack',"Symbolized by a snarling wolf and the rallying cry 'Feed 'em to the wolves!', representing pack mentality and fierce competitiveness."],
        [6,'Bull Six','Represented by a black bull set against a red background, symbolizing raw power, aggression, and intimidation.'],
        [7,'Shadow Seven','Symbolized by a unicorn and a lightning bolt, representing mystique, rarity, and swift, unstoppable striking power.'],
        [8,'Eagle Eight','Represented by the F-15 Eagle and four class stars, symbolizing air superiority and the collective achievement of every class.'],
        [9,'Viking Nine','Symbolized by dragon ships, representing boldness, exploration, and a fearless warrior spirit.'],
        [10,'Tiger Ten','Represented by the Flying Tigers and lightning bolts, symbolizing aggression, speed, and a storied legacy of combat excellence.'],
    ];
    $stmt = $db->prepare('INSERT OR IGNORE INTO squadrons(id,name,description,created_at) VALUES(?,?,?,?)');
    foreach ($rows as $row) $stmt->execute([$row[0],$row[1],$row[2],date('c')]);
}
restoreDefaultSquadrons();

function getSquadronRankings() {
    $db = getDb();
    $squadrons = $db->query('SELECT id,name FROM squadrons ORDER BY id')->fetchAll();
    $totals = [];
    foreach ($squadrons as $s) $totals[$s['id']] = 0.0;
    foreach ($db->query('SELECT squadron_id,COALESCE(SUM(COALESCE(value,points_awarded,0)),0) total FROM events GROUP BY squadron_id')->fetchAll() as $row) {
        if ($row['squadron_id'] !== null && array_key_exists($row['squadron_id'],$totals)) $totals[$row['squadron_id']] += (float)$row['total'];
    }
    foreach ($db->query('SELECT squadron_id,COALESCE(SUM(points_awarded),0) total FROM intramural_wl_records GROUP BY squadron_id')->fetchAll() as $row) {
        if ($row['squadron_id'] !== null && array_key_exists($row['squadron_id'],$totals)) $totals[$row['squadron_id']] += (float)$row['total'];
    }
    $ranked = [];
    foreach ($squadrons as $s) $ranked[] = ['squadron_id'=>$s['id'],'name'=>$s['name'],'total'=>$totals[$s['id']] ?? 0.0];
    usort($ranked, fn($a,$b) => $b['total'] <=> $a['total']);
    foreach ($ranked as $i=>&$row) $row['rank'] = $i + 1;
    unset($row);
    return $ranked;
}

function recordLeaderboardSnapshot() {
    $db = getDb();
    $ranked = getSquadronRankings();
    $date = date('c');
    $stmt = $db->prepare('INSERT INTO leaderboard_snapshots(snapshot_date,squadron_id,rank,total_points) VALUES(?,?,?,?)');
    foreach ($ranked as $row) $stmt->execute([$date,$row['squadron_id'],$row['rank'],$row['total']]);
    return ['snapshot_date'=>$date,'rankings'=>$ranked];
}

function getLeaderboardMovement($squadronId) {
    $current = null;
    foreach (getSquadronRankings() as $row) if ((int)$row['squadron_id'] === (int)$squadronId) { $current=(int)$row['rank']; break; }
    $stmt = getDb()->prepare('SELECT rank FROM leaderboard_snapshots WHERE squadron_id=? ORDER BY snapshot_date DESC LIMIT 1');
    $stmt->execute([$squadronId]);
    $previous = $stmt->fetchColumn();
    if ($previous === false) return ['current_rank'=>$current,'previous_rank'=>null,'movement'=>'new'];
    $diff = (int)$previous - (int)$current;
    return ['current_rank'=>$current,'previous_rank'=>(int)$previous,'movement'=>$diff>0?'up '.$diff:($diff<0?'down '.abs($diff):'same')];
}

function getAdminUsernames() {
    $raw = $_ENV['ADMIN_USERS'] ?? getenv('ADMIN_USERS');
    if ($raw === false || trim((string)$raw)==='') return ['admin'];
    $users = array_values(array_filter(array_map('trim',explode(',',(string)$raw)),fn($u)=>$u!==''));
    return $users ?: ['admin'];
}

function verifyAdminCredentials($username,$password) {
    $username=trim((string)$username);
    if ($username==='' || !in_array($username,getAdminUsernames(),true)) return false;
    $stored=$_ENV['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD');
    if ($stored===false || $stored==='') return false;
    if (preg_match('/^\$2[aby]\$|^\$argon2/',$stored)) return password_verify((string)$password,$stored);
    return hash_equals((string)$stored,(string)$password);
}

function recordAdminUser($username) {
    getDb()->prepare('INSERT OR IGNORE INTO admin_users(username,created_at) VALUES(?,?)')->execute([$username,date('c')]);
}

function iconUrl($icon) {
    if (!$icon) return null;
    return 'image.php?file='.rawurlencode(basename((string)$icon));
}

/** Legacy JSON helpers retained only for non-database UI configuration. */
function readJson($file) {
    if (!is_file($file)) return [];
    $data=json_decode(file_get_contents($file),true);
    return is_array($data)?$data:[];
}
function writeJson($file,$data) {
    return file_put_contents($file,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
}
