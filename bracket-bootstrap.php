<?php
require_once __DIR__ . '/bracket-schema-migration.php';
require_once __DIR__ . '/bracket-migrate.php';

function bracketBootstrap(PDO $db): void
{
    $db->exec('PRAGMA foreign_keys = ON');
    initBracketTables($db);
    $json = DATA_DIR . '/brackets.json';
    migrateLegacyBrackets($db, $json);

    // Backfill completed bracket matchups into the normal events ledger so
    // existing bracket results contribute to the same leaderboard totals as
    // scores entered through the scoring page. The matchup/event link makes
    // this idempotent and prevents duplicate points.
    try {
        $rows = $db->query("SELECT m.*, b.name AS bracket_name
            FROM bracket_matchups m JOIN brackets b ON b.id=m.bracket_id
            WHERE m.status='completed' AND m.winner_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $find = $db->prepare('SELECT event_id FROM bracket_score_events WHERE matchup_id=?');
        $insert = $db->prepare('INSERT INTO events(squadron_id,event_type,event_name,value,points_awarded,timestamp,created_at) VALUES(?,?,?,?,?,?,?)');
        $link = $db->prepare('INSERT INTO bracket_score_events(matchup_id,event_id) VALUES(?,?)');
        foreach ($rows as $m) {
            $find->execute([$m['id']]);
            if ($find->fetchColumn()) continue;
            $points=(float)($m['points']??0);
            $now=date('c');
            $insert->execute([(int)$m['winner_id'],'bracket',$m['bracket_name'].' - Match '.$m['match_number'],$points,$points,$m['updated_at']??$now,$now]);
            $link->execute([$m['id'],$db->lastInsertId()]);
        }
    } catch (Throwable $e) {
        error_log('Bracket score-event backfill failed: '.$e->getMessage());
    }
}

function bracketSquadrons(): array
{
    $db=getDb();
    $rows=$db->query('SELECT id,name,icon_filename FROM squadrons ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if($rows)return $rows;
    $legacy=readJson(DATA_DIR.'/squadrons.json');
    return is_array($legacy)?$legacy:[];
}
