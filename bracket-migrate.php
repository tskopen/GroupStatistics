<?php
function normalizeLegacyBracket(array $bracket): array
{
    $out=['id'=>(string)$bracket['id'],'name'=>(string)($bracket['name']??'Untitled Bracket'),'created_date'=>$bracket['created_date']??date('c'),'champion_id'=>isset($bracket['champion_id'])&&$bracket['champion_id']!==''?(int)$bracket['champion_id']:null,'participants'=>[],'rounds'=>[]];
    foreach(($bracket['participants']??[]) as $i=>$p){$id=$p['squadron_id']??$p['id']??null;if($id!==null)$out['participants'][]=['squadron_id'=>(int)$id,'seed'=>$i+1];}
    foreach(($bracket['rounds']??[]) as $ri=>$r){$round=['round_num'=>(int)($r['round_num']??$ri+1),'round_name'=>$r['round_name']??$r['name']??('Round '.($ri+1)),'matchups'=>[]];foreach(($r['matchups']??[]) as $m){$round['matchups'][]=['id'=>(string)($m['id']??bin2hex(random_bytes(8))),'team1_id'=>isset($m['team1_id'])&&$m['team1_id']!==''?(int)$m['team1_id']:null,'team2_id'=>isset($m['team2_id'])&&$m['team2_id']!==''?(int)$m['team2_id']:null,'team1_source_matchup_id'=>$m['team1_source_matchup_id']??null,'team2_source_matchup_id'=>$m['team2_source_matchup_id']??null,'team1_score'=>$m['team1_score']??null,'team2_score'=>$m['team2_score']??null,'winner_id'=>isset($m['winner_id'])&&$m['winner_id']!==''?(int)$m['winner_id']:null,'points'=>$m['points']??0,'status'=>$m['status']??'upcoming','next_match_id'=>$m['next_match_id']??null];}$out['rounds'][]=$round;}
    return $out;
}
function migrateLegacyBrackets(PDO $db,string $jsonPath):int
{
    if(!is_file($jsonPath)||!function_exists('initBracketTables'))return 0;initBracketTables($db);$legacy=json_decode(file_get_contents($jsonPath),true);if(!is_array($legacy))return 0;$count=0;
    foreach($legacy as $raw){if(empty($raw['id'])||empty($raw['name']))continue;$exists=$db->prepare('SELECT 1 FROM brackets WHERE id=? LIMIT 1');$exists->execute([(string)$raw['id']]);if($exists->fetchColumn())continue;try{bracketSave($db,normalizeLegacyBracket($raw));$count++;}catch(Throwable $e){error_log('Bracket migration failed for '.$raw['id'].': '.$e->getMessage());}}
    return $count;
}
