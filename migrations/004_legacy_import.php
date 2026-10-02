<?php
/** Migration 004: import legacy JSON once, then archive the files. */
return static function (PDO $db): void {
    $dataDir=defined('DATA_DIR')?DATA_DIR:'/data';
    $squadrons=$dataDir.'/squadrons.json';
    if (is_file($squadrons) && !is_file($squadrons.'.migrated')) {
        $rows=json_decode(file_get_contents($squadrons)?:'',true);
        if(is_array($rows)){
            $stmt=$db->prepare('INSERT OR IGNORE INTO squadrons(id,name,description,icon_filename,created_at) VALUES(?,?,?,?,?)');
            foreach($rows as $row)if(isset($row['id'],$row['name']))$stmt->execute([(int)$row['id'],$row['name'],$row['description']??null,$row['icon']??$row['icon_filename']??null,$row['created_at']??date('c')]);
        }
        @rename($squadrons,$squadrons.'.legacy.bak'); @file_put_contents($squadrons.'.migrated',date('c'));
    }

    $scores=$dataDir.'/scores.json';
    if(is_file($scores)&&!is_file($scores.'.migrated')){
        $rows=json_decode(file_get_contents($scores)?:'',true);
        if(is_array($rows)){
            $stmt=$db->prepare('INSERT INTO events(squadron_id,event_type,event_name,value,points_awarded,timestamp,created_at) VALUES(?,?,?,?,?,?,?)');
            foreach($rows as $row)if(isset($row['squadron_id'])){$value=(float)($row['value']??0);$stmt->execute([(int)$row['squadron_id'],strtolower(trim((string)($row['event_type']??'other'))),$row['event_name']??$row['tournament_name']??'Event',$value,(float)($row['points_awarded']??$value),$row['timestamp']??date('c'),date('c')]);}
        }
        @rename($scores,$scores.'.legacy.bak'); @file_put_contents($scores.'.migrated',date('c'));
    }

    $brackets=$dataDir.'/brackets.json';
    if(is_file($brackets)&&!is_file($brackets.'.migrated')){
        // Reuse the canonical bracket migration logic so legacy rounds become
        // bracket_participants/bracket_rounds/bracket_matchups, not just a JSON
        // blob in the parent row.
        require_once dirname(__DIR__).'/bracket-schema-migration.php';
        require_once dirname(__DIR__).'/bracket-migrate.php';
        migrateLegacyBrackets($db,$brackets);
        @rename($brackets,$brackets.'.legacy.bak'); @file_put_contents($brackets.'.migrated',date('c'));
    }
};
