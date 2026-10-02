<?php
function getDb() {
    static $pdo=null;
    if ($pdo===null) {
        if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR,0775,true);
        $pdo=new PDO('sqlite:'.DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    return $pdo;
}

function seedDefaultConfig() {
    $db=getDb();
    $defaults=[['samis','SAMIS Scores','Weekly SAMIs','📊'],['pft','Physical Fitness Test','PFT scores','💪'],['other','Other Event','Miscellaneous points','📌'],['bracket','Bracket Tournament','Tournament bracket event','🏆'],['intramural','Intramural','Intramural game results','🏀']];
    $stmt=$db->prepare('INSERT OR IGNORE INTO event_type_config(event_type,display_name,description,emoji) VALUES(?,?,?,?)');
    foreach($defaults as $row)$stmt->execute($row);
    $stmt=$db->prepare('INSERT OR IGNORE INTO admin_config(key,value) VALUES(?,?)');
    foreach(['intramural_win_points'=>'5','intramural_loss_points'=>'-1','intramural_bonus_0_6_points'=>'10'] as $k=>$v)$stmt->execute([$k,$v]);
}

function normalizeLegacyEventRows(PDO $db): void {
    $db->exec("UPDATE events SET event_type=LOWER(TRIM(event_type)) WHERE event_type IS NOT NULL AND TRIM(event_type)<>''");
    $db->exec("UPDATE events SET event_type='other' WHERE event_type IS NULL OR TRIM(event_type)=''");
    $db->exec("UPDATE events SET event_name='Event' WHERE event_name IS NULL OR TRIM(event_name)=''");
    $db->exec("UPDATE events SET points_awarded=value WHERE value IS NOT NULL AND (points_awarded IS NULL OR points_awarded=0)");
}

function restoreDefaultSquadrons() {
    $db=getDb();
    if ((int)$db->query('SELECT COUNT(*) FROM squadrons')->fetchColumn()>0)return;
    $rows=[[1,'Mighty Mach One','Symbolized by the griffin and the Maltese Cross, representing strength, vigilance, and a long tradition of honor.'],[2,'Deuce','Represented by red, white, and blue contrails streaking toward space, symbolizing speed, patriotism, and the reach beyond the atmosphere.'],[3,'Dogs of War','Embodied by Cerberus and flames, symbolizing ferocity, guardianship, and relentless fighting spirit.'],[4,"Fightin' Fourth",'Represented by a prop and wings alongside four classes united, symbolizing aviation heritage and squadron unity across all four years.'],[5,'Wolfpack',"Symbolized by a snarling wolf and the rallying cry 'Feed 'em to the wolves!', representing pack mentality and fierce competitiveness."],[6,'Bull Six','Represented by a black bull set against a red background, symbolizing raw power, aggression, and intimidation.'],[7,'Shadow Seven','Symbolized by a unicorn and a lightning bolt, representing mystique, rarity, and swift, unstoppable striking power.'],[8,'Eagle Eight','Represented by the F-15 Eagle and four class stars, symbolizing air superiority and the collective achievement of every class.'],[9,'Viking Nine','Symbolized by dragon ships, representing boldness, exploration, and a fearless warrior spirit.'],[10,'Tiger Ten','Represented by the Flying Tigers and lightning bolts, symbolizing aggression, speed, and a storied legacy of combat excellence.']];
    $stmt=$db->prepare('INSERT OR IGNORE INTO squadrons(id,name,description,created_at) VALUES(?,?,?,?)');
    foreach($rows as $row)$stmt->execute([$row[0],$row[1],$row[2],date('c')]);
}

function initDatabase() {
    $db=getDb();
    runDatabaseMigrations($db);
    seedDefaultConfig();
    normalizeLegacyEventRows($db);
    restoreDefaultSquadrons();
}
